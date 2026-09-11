<?php

namespace App\Services;

use App\Exceptions\MamoreException;
use App\Models\Turno;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Reporte de asistencia procesada de **toda una dirección administrativa**: una
 * fila por funcionario con sus totales del rango, en vez de un funcionario por
 * vez.
 *
 * Responde la pregunta que el reporte individual no puede: «¿cómo estuvo la
 * dirección este mes?». Con el individual había que saber de antemano a quién
 * mirar, así que el atraso de quien nadie pensó en consultar no aparecía nunca.
 *
 * ---
 * **Quién entra en el reporte lo decide el contrato, no la planilla de hoy.**
 *
 * Se pide a Mamoré quién estuvo en esa dirección *durante el rango*, así que
 * entra quien se fue a mitad de período —trabajó esos días y marcó— y entra
 * quien llegó después de empezado. Una persona puede tener **más de un
 * contrato** en el rango: una renovación, o un pase de una dirección a otra. Sus
 * tramos viajan en la fila para que se vea de dónde sale cada uno, y el hueco
 * entre dos contratos no se controla, igual que en el reporte individual.
 * ---
 *
 * ---
 * **Los contratos se traen en un solo viaje.** El procesador, llamado de a uno,
 * pregunta los contratos de cada persona por su cuenta: para una dirección de
 * 500 funcionarios eso son 500 peticiones en serie contra una cuota de 60 por
 * minuto, o sea un reporte que no termina. Acá se piden todos juntos y se le
 * pasan ya resueltos a {@see ProcesadorAsistencia::procesarConTramos()}.
 * ---
 *
 * @phpstan-import-type Tramo from ContratosFuncionario
 *
 * @phpstan-type Fila array{persona: array<string, mixed>, tramos: list<Tramo>, totales: array<string, mixed>}
 */
class ReporteDireccion
{
    public function __construct(
        private DirectorioMamore $directorio,
        private ContratosFuncionario $contratos,
        private ProcesadorAsistencia $procesador,
    ) {}

    /**
     * Las filas del reporte: un funcionario por fila, con sus contratos del
     * rango y sus totales.
     *
     * Quien no tuvo ningún contrato dentro del rango **no genera fila**. Mamoré
     * lo devolvió porque pertenece a la dirección, pero si ninguno de sus
     * contratos toca el período no hubo jornada que controlar, y una fila con
     * todo en cero se leería como si hubiera cumplido sin marcar nunca.
     *
     * `$unidad` acota a una unidad administrativa de la dirección; en `null`
     * entran todas.
     *
     * @return Collection<int, Fila>
     *
     * @throws MamoreException si Mamoré no responde
     */
    public function filas(int $direccion, Carbon $desde, Carbon $hasta, ?int $unidad = null): Collection
    {
        $personas = $this->directorio->porDireccion(
            $direccion,
            $desde->toDateString(),
            $hasta->toDateString(),
            $unidad,
        );

        if ($personas->isEmpty()) {
            return collect();
        }

        $cis = $personas->pluck('ci')->map(fn (string $ci): string => trim($ci))->all();

        // `null` es «no se conocen los contratos» —Mamoré sin configurar—, y
        // entonces se procesa el rango entero para todos, igual que hace el
        // reporte individual. No puede confundirse con una lista vacía, que sí
        // afirma que la persona no estuvo contratada ni un día.
        $tramosPorCi = $this->contratos->tramosDeVarios($cis, $desde, $hasta);

        return $personas
            ->map(function (array $persona) use ($tramosPorCi, $desde, $hasta): ?array {
                $ci = trim((string) $persona['ci']);
                $tramos = $tramosPorCi === null ? null : ($tramosPorCi[$ci] ?? []);

                if ($tramos === []) {
                    return null;
                }

                $dias = $this->procesador->procesarConTramos($ci, $desde, $hasta, $tramos);

                return [
                    'persona' => $persona,
                    'tramos' => $tramos ?? [],
                    'totales' => $this->procesador->totales($dias),
                    'meses' => $this->porMes($dias, $tramos),
                ];
            })
            ->filter()
            ->sortBy(fn (array $fila): string => mb_strtolower(
                (string) ($fila['persona']['nombreFormal'] ?: $fila['persona']['nombre'])
            ))
            ->values();
    }

    /**
     * El cierre de la dirección: la suma de todas las filas.
     *
     * Tiene la misma forma que los totales de una persona —`porEstado` incluido—
     * para que la fila del pie se pinte con el mismo código que las de arriba.
     * `funcionarios` es lo único que se agrega, porque es la única cifra que no
     * existe cuando se mira a una sola persona.
     *
     * @param  Collection<int, Fila>  $filas
     * @return array{funcionarios: int, dias: int, atraso: int, anticipo: int, computado: int, esperado: int, saldo: int, porEstado: array<string, int>}
     */
    public function totales(Collection $filas): array
    {
        $porEstado = [];

        foreach ($filas as $fila) {
            foreach ($fila['totales']['porEstado'] as $estado => $cuantos) {
                $porEstado[$estado] = ($porEstado[$estado] ?? 0) + $cuantos;
            }
        }

        $computado = (int) $filas->sum(fn (array $fila): int => $fila['totales']['computado']);
        $esperado = (int) $filas->sum(fn (array $fila): int => $fila['totales']['esperado']);

        return [
            'funcionarios' => $filas->count(),
            'dias' => (int) $filas->sum(fn (array $fila): int => $fila['totales']['dias']),
            'atraso' => (int) $filas->sum(fn (array $fila): int => $fila['totales']['atraso']),
            'anticipo' => (int) $filas->sum(fn (array $fila): int => $fila['totales']['anticipo']),
            'computado' => $computado,
            'esperado' => $esperado,
            'saldo' => $computado - $esperado,
            'porEstado' => $porEstado,
        ];
    }

    /**
     * Los totales de cada mes calendario del rango, en orden.
     *
     * ---
     * **Los minutos de atraso se acumulan por mes y no se suman entre meses.**
     *
     * Doce minutos en enero y diez en febrero no son veintidós: son doce y son
     * diez. La tolerancia y la escala de sanciones se miden sobre el mes
     * calendario, así que un total del rango no significa nada —y peor, se lee
     * como si significara algo—.
     *
     * Los conteos de días (atrasos, faltas, abandonos) sí se pueden acumular:
     * contar días no cambia de unidad al cruzar el mes. Por eso el subtotal de
     * la persona los suma y deja los minutos con una raya.
     * ---
     *
     * ---
     * **Solo salen los meses que algún contrato cubre.** Quien trabajó de enero
     * a abril no tiene mayo: no es que ese mes esté en cero, es que no existió
     * para esa persona, y una fila vacía se lee como un mes sin novedad. Vale
     * igual para el hueco entre dos contratos.
     *
     * Un mes en el que hubo contrato sí se muestra aunque no tenga nada que
     * reportar: ahí el cero es información: estuvo y no tuvo incumplimientos.
     * ---
     *
     * Se agrupan los días ya procesados en vez de volver a procesar mes por mes:
     * el cálculo es el mismo y la vuelta extra costaría otro viaje a Mamoré por
     * persona y por mes.
     *
     * @param  Collection<int, array{fecha: Carbon}>  $dias
     * @param  list<array{desde: Carbon, hasta: ?Carbon}>|null  $tramos
     * @return list<array{clave: string, etiqueta: string, totales: array<string, mixed>}>
     */
    private function porMes(Collection $dias, ?array $tramos): array
    {
        return $dias
            ->groupBy(fn (array $dia): string => $dia['fecha']->format('Y-m'))
            // Un mes en el que ningún día estuvo cubierto no existió para esta
            // persona.
            //
            // Se le pregunta al contrato y no al estado del día: un día sin
            // cubrir sale «sin contrato» solo si además tenía turno, y los
            // sábados y domingos van «no laborable» estén cubiertos o no. Mirar
            // los estados dejaba pasar cualquier mes que tuviera un fin de
            // semana, que son todos.
            //
            // Con los tramos en `null` —Mamoré sin configurar— `cubierto()`
            // responde que sí a todo, así que no se recorta nada.
            ->reject(fn (Collection $delMes): bool => $delMes->every(
                fn (array $dia): bool => ! $this->contratos->cubierto($tramos, $dia['fecha'])
            ))
            ->map(fn (Collection $delMes, string $clave): array => [
                'clave' => $clave,
                'etiqueta' => self::MESES[(int) substr($clave, 5, 2)].' '.substr($clave, 0, 4),
                'totales' => $this->procesador->totales($delMes),
            ])
            ->values()
            ->all();
    }

    /**
     * Nombre corto de cada mes, como los escribe el reporte impreso. Va acá y no
     * por localización de Carbon porque el resto del sistema nombra sus períodos
     * con arreglos propios ({@see Turno::DIAS}).
     *
     * @var array<int, string>
     */
    public const MESES = [
        1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr', 5 => 'May', 6 => 'Jun',
        7 => 'Jul', 8 => 'Ago', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic',
    ];

    /**
     * ¿El rango pisa más de un mes calendario?
     *
     * Es lo que decide si la tabla se abre por mes: dentro de un mismo mes los
     * totales del rango ya son mensuales y la fila por persona alcanza.
     */
    public static function cruzaMeses(Carbon $desde, Carbon $hasta): bool
    {
        return $desde->format('Y-m') !== $hasta->format('Y-m');
    }

    /**
     * Cuántos días de una fila cayeron en alguno de esos estados.
     *
     * Los estados que no aparecen en `porEstado` valen cero: el procesador solo
     * anota los que se dieron.
     *
     * @param  array<string, int>  $porEstado
     * @param  list<string>  $estados
     */
    public static function contar(array $porEstado, array $estados): int
    {
        $total = 0;

        foreach ($estados as $estado) {
            $total += $porEstado[$estado] ?? 0;
        }

        return $total;
    }
}
