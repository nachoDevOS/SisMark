<?php

namespace App\Services;

use App\Models\Licencia;
use App\Models\Turno;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Traduce la asistencia ya procesada de un mes al lenguaje del Reglamento
 * Interno de Personal: atrasos, inasistencias, ausencias en el puesto y
 * omisiones de registro.
 *
 * Va **encima** de {@see ProcesadorAsistencia} y no dentro. El procesador
 * responde qué pasó cada día —quién marcó, cuándo y cuánto trabajó— y eso no
 * depende de ningún reglamento. Acá se responde qué significa eso para el RIP,
 * que es lo que cambia cuando Recursos Humanos fija una interpretación.
 * Mezclarlos obligaría a tocar el motor de asistencia, y sus reglas están
 * probadas contra dos años de datos reales.
 *
 * De acá sale un acumulado mensual que {@see EscalaRip} convierte en sanciones.
 *
 * **Nada de esto se guarda todavía.** Es un cálculo al vuelo para que Recursos
 * Humanos compare contra los meses que ya resolvió a mano, antes de que estas
 * cifras funden un memorándum.
 *
 * @phpstan-type Hecho array{fecha: Carbon, turno: ?string, tipo: string, tipoEtiqueta: string, jornadas: float, segundos: int, detalle: string, articulo: string}
 * @phpstan-type Mes array{acumulado: array<string, mixed>, hechos: list<Hecho>, sanciones: list<array<string, mixed>>, totalDias: ?float, totalMonto: ?float, haber: ?array<string, mixed>, diasControlados: int, diasApartados: int, apartados: list<array{fecha: Carbon, motivo: string}>, hasta: Carbon, enCurso: bool}
 */
class CalificadorRip
{
    public function __construct(
        private ProcesadorAsistencia $procesador,
        private EscalaRip $escala,
        private HaberFuncionario $haberes,
    ) {}

    /**
     * Califica un mes completo de un funcionario.
     *
     * `$reincidencia` es qué número de vez en la gestión que este mes pasa de
     * los 120 minutos de atraso; lo resuelve {@see reincidenciaDeAtraso()}, que
     * tiene que mirar los meses anteriores.
     *
     * @return Mes
     */
    public function mes(string $ci, int $gestion, int $mes, ?int $reincidencia = null): array
    {
        $desde = Carbon::create($gestion, $mes, 1)->startOfDay();
        $finDelMes = $desde->copy()->endOfMonth()->startOfDay();

        // **El mes en curso se corta en ayer.** Sin este corte, los días que
        // todavía no llegaron entran sin marcas y se califican como falta: un
        // 19 de agosto el funcionario aparecía con ocho inasistencias
        // consecutivas —las que faltaban para terminar el mes— y eso disparaba
        // el abandono de funciones del Art. 48 sobre días que nadie trabajó.
        //
        // Se corta en ayer y no en hoy porque la jornada de hoy está abierta:
        // quien todavía no marcó la salida figuraría con una omisión que
        // seguramente no va a cometer. El Art. 51.II va en la misma dirección
        // —el cómputo se hace a la conclusión del mes—, así que lo que se
        // muestra antes es siempre un avance.
        $ayer = Carbon::yesterday()->startOfDay();
        $hasta = $finDelMes->lessThanOrEqualTo($ayer) ? $finDelMes : $ayer;
        $enCurso = $hasta->lessThan($finDelMes);

        $dias = $hasta->lessThan($desde)
            ? collect()
            : $this->procesador->procesar($ci, $desde, $hasta);

        // El haber sale de Mamoré y puede no estar: sin contrato, sin sueldo
        // cargado o con la API caída, la sanción se muestra igual en días y el
        // monto queda en `null`. Los días son del reglamento; los bolivianos son
        // una conveniencia.
        //
        // Va **antes** de calificar porque además decide qué días se califican:
        // los que la persona no tenía contrato no se le pueden imputar.
        $haber = $this->haberes->delMes($ci, $gestion, $mes);

        $calificado = $this->calificar($dias, $haber);

        $calificado['acumulado']['atrasoReincidencia'] = $reincidencia
            ?? $this->reincidenciaDeAtraso($ci, $gestion, $mes, $calificado['acumulado']['atrasoSegundos']);

        $sanciones = $this->escala->evaluar($calificado['acumulado']);
        $totalDias = $this->escala->totalDias($sanciones);

        // Cada sanción se cobra al sueldo del contrato que regía cuando se
        // cometió. Un mes puede tener dos contratos con sueldos distintos, así
        // que los días de la escala se reparten en proporción a lo que cada uno
        // aportó. Ver {@see HaberFuncionario::montoRepartido()}.
        $sanciones = array_map(fn (array $sancion): array => [
            ...$sancion,
            'monto' => $this->haberes->montoRepartido(
                $sancion['dias'],
                $haber,
                $calificado['reparto'][$sancion['tipo']] ?? [],
            ),
        ], $sanciones);

        return [
            ...$calificado,
            'sanciones' => $sanciones,
            'totalDias' => $totalDias,
            'haber' => $haber,
            'totalMonto' => $this->totalDeMontos($totalDias, $sanciones),
            // Hasta dónde se calificó de verdad. Con el mes abierto, la cifra
            // es un avance y la pantalla tiene que decirlo: una sanción se
            // determina recién a la conclusión del mes (Art. 51.II).
            'hasta' => $hasta,
            'enCurso' => $enCurso,
        ];
    }

    /**
     * Recorre los días procesados y los reparte en hechos del reglamento.
     *
     * Los días con el turno mal configurado se **apartan**: un turno con cuatro
     * horas de tolerancia o sin horas declaradas no puede fundar un descuento, y
     * calificarlo daría un resultado limpio sobre un dato roto. Se informan por
     * separado para que se arreglen.
     *
     * @param  Collection<int, array<string, mixed>>  $dias
     * @return array{acumulado: array<string, mixed>, hechos: list<Hecho>, diasControlados: int, diasApartados: int, apartados: list<array{fecha: Carbon, motivo: string}>}
     */
    public function calificar(Collection $dias, ?array $haber = null): array
    {
        $acumulado = EscalaRip::vacio();
        $hechos = [];
        $apartados = [];
        $controlados = 0;

        // Posición del día entre los que **tenían turno**, se hayan calificado
        // o no. La racha se mide sobre esto y no sobre los días calificados: un
        // día apartado deja un hueco y **corta** la racha, en vez de pegar las
        // inasistencias que tiene a los lados. Un día demasiado roto para
        // calificarse no puede ser lo bastante bueno para unir.
        //
        // El fin de semana sí sigue uniendo, porque no ocupa posición: «tres
        // días hábiles consecutivos» no se rompe porque en el medio hubo un
        // domingo.
        $posicion = -1;

        // Días con inasistencia o ausencia, en orden, para medir la continuidad
        // que exigen los Arts. 46.IV, 47.III y 48.
        $secuencia = ['inasistencia' => [], 'ausencia' => []];

        // Cuánto aportó cada tramo de contrato a cada conducta, para repartir
        // después los días de la escala entre los sueldos que rigieron.
        $reparto = [
            EscalaRip::ATRASO => [],
            EscalaRip::INASISTENCIA => [],
            EscalaRip::AUSENCIA => [],
            EscalaRip::OMISION => [],
        ];

        foreach ($dias as $dia) {
            $bloques = $dia['bloques'];

            // **Sin contrato no hay deber de asistencia que incumplir.** Entre
            // dos contrataciones la persona no es funcionaria, así que esos días
            // no se califican ni cuentan para la continuidad del Art. 48. Sin
            // esto, un hueco contractual de una semana se leía como abandono de
            // funciones.
            //
            // Lo decide {@see ProcesadorAsistencia}, que es la primera puerta:
            // acá solo se ocupa la posición para que el día **corte** la racha en
            // vez de unir los días que tiene a los lados.
            if ($dia['estado'] === ProcesadorAsistencia::SIN_CONTRATO) {
                $posicion++;
                $apartados[] = ['fecha' => $dia['fecha'], 'motivo' => 'Sin contrato vigente'];

                continue;
            }

            // El día sin turno no ocupa posición: «tres días hábiles
            // consecutivos» no se rompe porque en el medio hubo un domingo.
            if ($bloques === []) {
                continue;
            }

            $posicion++;

            if ($this->apartado($dia)) {
                $apartados[] = ['fecha' => $dia['fecha'], 'motivo' => 'Turno mal configurado'];

                continue;
            }

            $controlados++;
            // En jornada partida cada bloque es media jornada; con un solo
            // turno, el bloque es el día entero.
            $peso = 1 / count($bloques);
            // A qué contrato se le imputa lo que pase hoy.
            $tramo = $haber === null ? null : $this->haberes->tramoDe($haber, $dia['fecha']);
            $indiceTramo = $tramo[0] ?? 0;

            foreach ($bloques as $bloque) {
                $hecho = $this->delBloque($dia, $bloque, $peso);

                if ($hecho === null) {
                    continue;
                }

                $hechos[] = $hecho;

                match ($hecho['tipo']) {
                    EscalaRip::ATRASO => $acumulado['atrasoSegundos'] += $hecho['segundos'],
                    EscalaRip::OMISION => $acumulado['omisiones']++,
                    EscalaRip::INASISTENCIA => $secuencia['inasistencia'][$posicion] = ($secuencia['inasistencia'][$posicion] ?? 0) + $hecho['jornadas'],
                    EscalaRip::AUSENCIA => $secuencia['ausencia'][$posicion] = ($secuencia['ausencia'][$posicion] ?? 0) + $hecho['jornadas'],
                    default => null,
                };

                // La magnitud con la que este hecho pesa dentro de su conducta:
                // minutos para el atraso, cantidad para la omisión, jornadas
                // para el ausentismo. Es la proporción con la que después se
                // reparten los días de la escala entre los contratos.
                $magnitud = match ($hecho['tipo']) {
                    EscalaRip::ATRASO => (float) $hecho['segundos'],
                    EscalaRip::OMISION => 1.0,
                    default => $hecho['jornadas'],
                };

                $reparto[$hecho['tipo']][$indiceTramo] = ($reparto[$hecho['tipo']][$indiceTramo] ?? 0) + $magnitud;
            }
        }

        foreach (['inasistencia' => EscalaRip::INASISTENCIA, 'ausencia' => EscalaRip::AUSENCIA] as $clave => $tipo) {
            $posiciones = $secuencia[$clave];
            $prefijo = $clave === 'inasistencia' ? 'inasistencia' : 'ausencia';

            $acumulado["{$prefijo}Jornadas"] = (float) array_sum($posiciones);
            $acumulado["{$prefijo}Dias"] = count($posiciones);
            $acumulado["{$prefijo}Continuos"] = $this->rachaMasLarga(array_keys($posiciones));
        }

        return [
            'acumulado' => $acumulado,
            'hechos' => $hechos,
            'reparto' => $reparto,
            'diasControlados' => $controlados,
            'diasApartados' => count($apartados),
            'apartados' => $apartados,
        ];
    }

    /**
     * Lo que suman en bolivianos todas las sanciones del mes.
     *
     * `null` cuando hay proceso interno —ahí no hay días que cobrar— o cuando a
     * alguna sanción no se le pudo poner precio: un total al que le falta un
     * pedazo parece completo y no lo es.
     *
     * @param  list<array<string, mixed>>  $sanciones
     */
    private function totalDeMontos(?float $totalDias, array $sanciones): ?float
    {
        if ($totalDias === null) {
            return null;
        }

        $total = 0.0;

        foreach ($sanciones as $sancion) {
            if ($sancion['dias'] === null) {
                continue;
            }

            if ($sancion['monto'] === null) {
                return null;
            }

            $total += $sancion['monto'];
        }

        return round($total, 2);
    }

    /**
     * Qué hecho del reglamento representa un bloque, o `null` si no representa
     * ninguno (cumplió, estaba licenciado, era feriado o no le tocaba trabajar).
     *
     * @param  array<string, mixed>  $dia
     * @param  array<string, mixed>  $bloque
     * @return ?Hecho
     */
    private function delBloque(array $dia, array $bloque, float $peso): ?array
    {
        $turno = $bloque['turno'] instanceof Turno ? trim((string) $bloque['turno']->nombreTurno) : null;

        return match ($bloque['estado']) {
            ProcesadorAsistencia::ATRASO => $this->deAtraso($dia, $bloque, $turno, $peso),

            // Ni entrada ni salida: no vino.
            ProcesadorAsistencia::FALTA => $this->hecho(
                $dia['fecha'], $turno, EscalaRip::INASISTENCIA, $peso, 0,
                'No registró entrada ni salida.', '45.II',
            ),

            // Marcó la salida pero no la entrada. Si hay una marca suelta
            // después del corte de los 30 minutos, no es que se olvidó de
            // marcar: llegó tarde y el reloj no la aceptó como entrada.
            ProcesadorAsistencia::SIN_ENTRADA => $this->llegoTarde($dia, $bloque)
                ? $this->hecho(
                    $dia['fecha'], $turno, EscalaRip::INASISTENCIA, $peso, 0,
                    'Registró su ingreso pasados los 30 minutos de la hora fijada.', '45.II',
                )
                : $this->hecho(
                    $dia['fecha'], $turno, EscalaRip::OMISION, 0, 0,
                    'No registró la entrada.', '45.III',
                ),

            ProcesadorAsistencia::SIN_SALIDA => $this->hecho(
                $dia['fecha'], $turno, EscalaRip::OMISION, 0, 0,
                'No registró la salida.', '45.III',
            ),

            // El procesador usa «abandono» para dos cosas distintas: irse antes
            // de la hora, y la licencia parcial que deja un hueco sin marcar. La
            // segunda no es ausencia del puesto, es una marca que falta.
            ProcesadorAsistencia::ABANDONO => $bloque['entrada'] === null && $bloque['licencia'] instanceof Licencia
                ? $this->hecho(
                    $dia['fecha'], $turno, EscalaRip::OMISION, 0, 0,
                    'La licencia no cubre la hora de entrada y no registró la marca.', '45.III',
                )
                : $this->hecho(
                    $dia['fecha'], $turno, EscalaRip::AUSENCIA, $peso, 0,
                    'Se retiró antes de la hora de salida.', '45.II',
                ),

            default => null,
        };
    }

    /**
     * Un atraso, salvo que pase del corte: ahí el Art. 45.II lo convierte en
     * inasistencia de la jornada y deja de sumar minutos.
     *
     * @param  array<string, mixed>  $dia
     * @param  array<string, mixed>  $bloque
     * @return ?Hecho
     */
    private function deAtraso(array $dia, array $bloque, ?string $turno, float $peso): ?array
    {
        $segundos = $this->segundosDeAtraso($bloque);

        if ($segundos <= 0) {
            return null;
        }

        if ($segundos > (int) config('rip.corteInasistencia')) {
            return $this->hecho(
                $dia['fecha'], $turno, EscalaRip::INASISTENCIA, $peso, 0,
                'Registró su ingreso pasados los 30 minutos de la hora fijada.', '45.II',
            );
        }

        return $this->hecho(
            $dia['fecha'], $turno, EscalaRip::ATRASO, 0, $segundos,
            ProcesadorAsistencia::desvio($segundos).' de atraso.', '22.V',
        );
    }

    /**
     * Los segundos que se le computan al atraso según la interpretación
     * vigente: desde la hora nominal de entrada o desde el fin de la tolerancia.
     *
     * El procesador siempre mide desde la nominal, así que para la otra lectura
     * se descuenta la tolerancia del propio turno (o la fija, si se configuró
     * una).
     *
     * @param  array<string, mixed>  $bloque
     */
    private function segundosDeAtraso(array $bloque): int
    {
        $segundos = (int) $bloque['atraso'];

        if (config('rip.atrasoDesde') !== EscalaRip::DESDE_TOLERANCIA) {
            return $segundos;
        }

        return max(0, $segundos - $this->tolerancia($bloque['turno']));
    }

    /**
     * Cuánta tolerancia concede el turno, en segundos. La configuración puede
     * imponer una fija para todos, que es lo que hará falta si Recursos Humanos
     * define un valor único distinto del que tiene cargado cada turno.
     */
    private function tolerancia(mixed $turno): int
    {
        $fija = config('rip.toleranciaFija');

        if ($fija !== null) {
            return (int) $fija;
        }

        if (! $turno instanceof Turno || $turno->hEntrada === null || $turno->hTolerancia === null) {
            return 0;
        }

        $entrada = $turno->hEntrada->hour * 3600 + $turno->hEntrada->minute * 60 + $turno->hEntrada->second;
        $tolerancia = $turno->hTolerancia->hour * 3600 + $turno->hTolerancia->minute * 60 + $turno->hTolerancia->second;

        return max(0, $tolerancia - $entrada);
    }

    /**
     * ¿Hay una marca del día que caiga después del corte de los 30 minutos y
     * antes de la salida del turno? Es la llegada tardía que el reloj no aceptó
     * como entrada porque quedó fuera de la ventana.
     *
     * @param  array<string, mixed>  $dia
     * @param  array<string, mixed>  $bloque
     */
    private function llegoTarde(array $dia, array $bloque): bool
    {
        $turno = $bloque['turno'];

        if (! $turno instanceof Turno || $turno->hEntrada === null || $turno->hSalida === null) {
            return false;
        }

        $entrada = $turno->hEntrada->hour * 3600 + $turno->hEntrada->minute * 60 + $turno->hEntrada->second;
        $salida = $turno->hSalida->hour * 3600 + $turno->hSalida->minute * 60 + $turno->hSalida->second;
        $corte = $entrada + (int) config('rip.corteInasistencia');

        foreach ($dia['marcas'] as $marca) {
            if ($marca > $corte && $marca < $salida) {
                return true;
            }
        }

        return false;
    }

    /**
     * Qué número de vez en la gestión que el funcionario pasa de los 120
     * minutos de atraso, contando este mes. La gestión va del 1 de enero al 31
     * de diciembre (Art. 50).
     *
     * **Recorre los meses anteriores uno por uno**, así que en diciembre son
     * once pasadas del procesador. Es aceptable para una ficha que se abre a
     * demanda y desaparece cuando exista la tabla de cierres mensuales, que
     * guarda el acumulado ya calculado.
     */
    public function reincidenciaDeAtraso(string $ci, int $gestion, int $mes, int $segundosDelMes): int
    {
        if (intdiv($segundosDelMes, 60) <= 120) {
            return 1;
        }

        $previas = 0;

        for ($anterior = 1; $anterior < $mes; $anterior++) {
            $desde = Carbon::create($gestion, $anterior, 1)->startOfDay();
            // Con el haber del mes anterior: los días en que no tenía contrato
            // tampoco suman minutos a la reincidencia de la gestión.
            $calificado = $this->calificar(
                $this->procesador->procesar($ci, $desde, $desde->copy()->endOfMonth()->startOfDay()),
                $this->haberes->delMes($ci, $gestion, $anterior),
            );

            if (intdiv($calificado['acumulado']['atrasoSegundos'], 60) > 120) {
                $previas++;
            }
        }

        return $previas + 1;
    }

    /**
     * La racha más larga de posiciones consecutivas. Las posiciones son índices
     * de días controlados, no fechas: así «tres días continuos» no se rompe
     * porque en el medio hubo un domingo.
     *
     * @param  list<int>  $posiciones
     */
    private function rachaMasLarga(array $posiciones): int
    {
        if ($posiciones === []) {
            return 0;
        }

        sort($posiciones);

        $mejor = 1;
        $actual = 1;

        for ($i = 1; $i < count($posiciones); $i++) {
            $actual = $posiciones[$i] === $posiciones[$i - 1] + 1 ? $actual + 1 : 1;
            $mejor = max($mejor, $actual);
        }

        return $mejor;
    }

    /**
     * ¿El día se aparta por configuración rota del turno?
     *
     * @param  array<string, mixed>  $dia
     */
    private function apartado(array $dia): bool
    {
        if (! config('rip.apartarTurnosInvalidos')) {
            return false;
        }

        foreach ($dia['bloques'] as $bloque) {
            if ($bloque['estado'] === ProcesadorAsistencia::TURNO_INVALIDO) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return Hecho
     */
    private function hecho(
        Carbon $fecha,
        ?string $turno,
        string $tipo,
        float $jornadas,
        int $segundos,
        string $detalle,
        string $articulo,
    ): array {
        return [
            'fecha' => $fecha,
            'turno' => $turno,
            'tipo' => $tipo,
            'tipoEtiqueta' => EscalaRip::TIPOS[$tipo],
            'jornadas' => $jornadas,
            'segundos' => $segundos,
            'detalle' => $detalle,
            'articulo' => $articulo,
        ];
    }
}
