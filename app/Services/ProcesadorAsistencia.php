<?php

namespace App\Services;

use App\Models\AsignacionTurno;
use App\Models\Asistencia;
use App\Models\DiaExcepcional;
use App\Models\Licencia;
use App\Models\Turno;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Procesa las marcaciones crudas de un funcionario contra su turno asignado,
 * los días excepcionales y sus licencias, y devuelve una ficha por día con
 * entradas, salidas, atrasos y horas computadas.
 *
 * Las reglas están documentadas en `docs/REPORTE-PROCESADO-ASISTENCIA.md`. Las
 * cuatro que mandan sobre el resto:
 *
 * 1. **Prioridad**: día excepcional → sin turno → licencia de día completo →
 *    recién ahí se miran las marcaciones.
 * 2. **Atraso**: se cuenta en **minutos completos** desde `hEntrada`, la hora
 *    nominal, y lo dispara `hTolerancia` medida en la misma unidad. Con entrada
 *    08:00 y tolerancia 08:10, el minuto 08:10 entero está adentro: a las
 *    08:10:59 no hay atraso; a las 08:11:00 hay 11 min. Los segundos se
 *    descartan: la tolerancia se concede en minutos.
 * 3. **Horas computadas**: se acotan al turno. Llegar dentro de la tolerancia
 *    cuenta como llegar a la hora; quedarse de más no suma. La permanencia real
 *    viaja aparte, como dato.
 * 4. **Licencia parcial**: si tapa el borde del turno no se exige marcar ahí. Si
 *    deja un hueco (empieza después de `hEntrada`), la marca es obligatoria y su
 *    ausencia es abandono.
 *
 * Todas las horas se manejan en **segundos desde medianoche**: las columnas de
 * `turnos` y `asistencias` guardan la hora sobre la fecha base 1899-12-30, así
 * que el día calendario de esas columnas no significa nada.
 *
 * @phpstan-type Bloque array{turno: Turno, entrada: ?int, salida: ?int, entradaExigida: bool, salidaExigida: bool, atraso: int, anticipo: int, permanencia: ?int, licenciado: int, computado: int, esperado: int, estado: string, licencia: ?Licencia, avisos: list<string>}
 * @phpstan-type Dia array{fecha: Carbon, estado: string, motivo: ?string, bloques: list<Bloque>, marcas: list<int>, marcasUsadas: int, atraso: int, anticipo: int, computado: int, esperado: int}
 */
class ProcesadorAsistencia
{
    public function __construct(private ContratosFuncionario $contratos) {}

    public const CUMPLE = 'cumple';

    public const ATRASO = 'atraso';

    public const ABANDONO = 'abandono';

    public const SIN_ENTRADA = 'sin_entrada';

    public const SIN_SALIDA = 'sin_salida';

    public const FALTA = 'falta';

    public const LICENCIA = 'licencia';

    public const EXCEPCIONAL = 'excepcional';

    public const NO_LABORABLE = 'no_laborable';

    public const TURNO_INVALIDO = 'turno_invalido';

    /**
     * El día no lo cubre ningún contrato de Mamoré.
     *
     * No es una falta ni un día libre: es que la persona no era funcionaria
     * ese día, así que no había jornada que cumplir. Se calcula igual para
     * que quien consuma el procesador sepa que el día existió y por qué no
     * se controló, pero no aporta horas, ni atraso, ni falta.
     */
    public const SIN_CONTRATO = 'sin_contrato';

    /**
     * Etiqueta legible de cada estado, para las vistas y el CSV.
     *
     * @var array<string, string>
     */
    public const ETIQUETAS = [
        self::CUMPLE => 'Cumple',
        self::ATRASO => 'Atraso',
        self::ABANDONO => 'Abandono',
        self::SIN_ENTRADA => 'Sin entrada',
        self::SIN_SALIDA => 'Sin salida',
        self::FALTA => 'Falta',
        self::LICENCIA => 'Licencia',
        self::EXCEPCIONAL => 'Día excepcional',
        self::NO_LABORABLE => 'No laborable',
        self::SIN_CONTRATO => 'Sin contrato',
        self::TURNO_INVALIDO => 'Turno mal configurado',
    ];

    /**
     * Texto que va en la columna «Falta» del reporte. Son los estados donde
     * faltó una marca que se exigía; el resto deja la celda vacía.
     *
     * @var array<string, string>
     */
    public const FALTAS = [
        self::FALTA => 'FALTA',
        self::SIN_ENTRADA => 'SIN ENTRADA',
        self::SIN_SALIDA => 'SIN SALIDA',
        self::TURNO_INVALIDO => 'TURNO MAL CONFIGURADO',
    ];

    /**
     * Modificador de `.pill` con el que se pinta cada estado en las vistas.
     *
     * @var array<string, string>
     */
    public const COLORES = [
        self::CUMPLE => 'pill--ok',
        self::ATRASO => 'pill--advertencia',
        self::ABANDONO => 'pill--no',
        self::SIN_ENTRADA => 'pill--advertencia',
        self::SIN_SALIDA => 'pill--advertencia',
        self::FALTA => 'pill--no',
        self::LICENCIA => 'pill--info',
        self::EXCEPCIONAL => 'pill--info',
        self::NO_LABORABLE => 'pill--info',
        self::SIN_CONTRATO => 'pill--advertencia',
        self::TURNO_INVALIDO => 'pill--advertencia',
    ];

    /**
     * Marcas separadas por menos de esto se toman como una sola: el reloj
     * rebota y deja la misma huella dos o tres veces seguidas.
     */
    private const DEDUPE_SEGUNDOS = 120;

    /**
     * Gravedad de cada estado, para elegir el del día cuando hay más de un
     * turno. Gana el más grave.
     *
     * @var array<string, int>
     */
    private const GRAVEDAD = [
        self::NO_LABORABLE => 0,
        // No pesa: no es una falta, es que no había jornada que cumplir.
        self::SIN_CONTRATO => 0,
        self::EXCEPCIONAL => 1,
        self::LICENCIA => 2,
        self::CUMPLE => 3,
        self::ATRASO => 4,
        self::SIN_SALIDA => 5,
        self::SIN_ENTRADA => 6,
        self::TURNO_INVALIDO => 7,
        self::ABANDONO => 8,
        self::FALTA => 9,
    ];

    /**
     * Ficha procesada de cada día del rango, en orden cronológico.
     *
     * @return Collection<int, Dia>
     */
    public function procesar(string $ci, Carbon $desde, Carbon $hasta): Collection
    {
        $ci = trim($ci);
        $desde = $desde->copy()->startOfDay();
        $hasta = $hasta->copy()->startOfDay();

        // **La primera puerta es el contrato**, antes que el turno: un día que
        // ningún contrato cubre no se controla, aunque haya turno asignado y
        // aunque la persona haya marcado. Va acá adentro y no en cada pantalla
        // para que lo hereden todos los consumidores —el reporte procesado, su
        // imprimible, el Excel, la API de asistencia y el régimen del RIP— sin
        // tener que acordarse en cada uno.
        //
        // `null` es «no se conocen los contratos» —Mamoré sin configurar— y se
        // procesa todo, como antes. Vacío es «no tuvo contrato», que sí excluye.
        $tramos = $this->contratos->tramos($ci, $desde, $hasta);

        $asignaciones = $this->asignacionesDelRango($ci, $desde, $hasta);
        $excepcionales = $this->excepcionalesDelRango($desde, $hasta);
        $licencias = $this->licenciasDelRango($ci, $desde, $hasta);
        // Un día más: la salida de un turno nocturno cae en la fecha siguiente.
        $marcas = $this->marcasDelRango($ci, $desde, $hasta->copy()->addDay());

        $dias = collect();

        for ($fecha = $desde->copy(); $fecha->lessThanOrEqualTo($hasta); $fecha->addDay()) {
            $dias->push($this->procesarDia($fecha->copy(), $asignaciones, $excepcionales, $licencias, $marcas, $tramos));
        }

        return $dias;
    }

    /**
     * Totales del rango, para el pie del reporte.
     *
     * @param  Collection<int, Dia>  $dias
     * @return array{dias: int, atraso: int, anticipo: int, computado: int, esperado: int, saldo: int, porEstado: array<string, int>}
     */
    public function totales(Collection $dias): array
    {
        $porEstado = [];

        foreach ($dias as $dia) {
            $porEstado[$dia['estado']] = ($porEstado[$dia['estado']] ?? 0) + 1;
        }

        $computado = (int) $dias->sum('computado');
        $esperado = (int) $dias->sum('esperado');

        return [
            'dias' => $dias->count(),
            'atraso' => (int) $dias->sum('atraso'),
            'anticipo' => (int) $dias->sum('anticipo'),
            'computado' => $computado,
            'esperado' => $esperado,
            'saldo' => $computado - $esperado,
            'porEstado' => $porEstado,
        ];
    }

    /**
     * Formatea segundos como «8h 25m» (o «—» si no hay dato).
     */
    public static function duracion(?int $segundos): string
    {
        if ($segundos === null) {
            return '—';
        }

        $signo = $segundos < 0 ? '-' : '';
        $segundos = abs($segundos);

        return $signo.intdiv($segundos, 3600).'h '.str_pad((string) intdiv($segundos % 3600, 60), 2, '0', STR_PAD_LEFT).'m';
    }

    /**
     * Formatea un desvío como «25 min» o «10 min 1 seg».
     *
     * El atraso llega siempre en minutos completos, así que ahí los segundos no
     * aparecen nunca. Los conserva para la salida anticipada, que sí se mide al
     * segundo.
     */
    public static function desvio(int $segundos): string
    {
        if ($segundos <= 0) {
            return '0 min';
        }

        $minutos = intdiv($segundos, 60);
        $resto = $segundos % 60;

        return $resto === 0 ? "{$minutos} min" : "{$minutos} min {$resto} seg";
    }

    /**
     * Hora legible de un instante expresado en segundos desde medianoche.
     */
    public static function hora(?int $segundos): string
    {
        if ($segundos === null) {
            return '—';
        }

        return sprintf('%02d:%02d:%02d', intdiv($segundos, 3600), intdiv($segundos % 3600, 60), $segundos % 60);
    }

    /**
     * Resuelve un día concreto siguiendo el orden de prioridad.
     *
     * @param  Collection<int, AsignacionTurno>  $asignaciones
     * @param  Collection<string, DiaExcepcional>  $excepcionales
     * @param  Collection<string, Collection<int, Licencia>>  $licencias
     * @param  Collection<string, list<int>>  $marcas
     * @return Dia
     */
    private function procesarDia(
        Carbon $fecha,
        Collection $asignaciones,
        Collection $excepcionales,
        Collection $licencias,
        Collection $marcas,
        ?array $tramos,
    ): array {
        $clave = $fecha->toDateString();
        $delDia = $marcas->get($clave, []);

        // 0. Sin contrato no hay deber de asistencia que incumplir. Se corta
        //    antes que nada, incluso antes del feriado: si la persona no era
        //    funcionaria ese día, que además fuera feriado no significa nada.
        //
        //    El estado distingue dos cosas que no son lo mismo: un día que la
        //    persona **habría tenido que trabajar** y no estaba contratada es
        //    «sin contrato» y hay que verlo; un sábado sigue siendo un sábado,
        //    haya contrato o no, y va como «no laborable». Ninguno de los dos se
        //    procesa, pero solo el primero señala algo que revisar.
        if (! $this->contratos->cubierto($tramos, $fecha)) {
            return $this->dia(
                $fecha,
                $this->turnosDelDia($fecha, $asignaciones) === [] ? self::NO_LABORABLE : self::SIN_CONTRATO,
                null,
                [],
                $delDia,
            );
        }

        // 1. El día excepcional manda sobre todo: no se procesan marcaciones ni
        //    turnos, aunque el funcionario tenga varios asignados.
        if ($excepcionales->has($clave)) {
            return $this->dia($fecha, self::EXCEPCIONAL, $excepcionales->get($clave)->motivoInasistencia, [], $delDia);
        }

        $turnos = $this->turnosDelDia($fecha, $asignaciones);

        // 2. Sin turno asignado ese día no hay nada que controlar: no es falta.
        if ($turnos === []) {
            return $this->dia($fecha, self::NO_LABORABLE, null, [], $delDia);
        }

        $delFuncionario = $licencias->get($clave, collect());

        // 3. La licencia de turno completo cubre el día entero, aunque apunte a
        //    un solo `turno_id`: no se exige marcar en ninguno de los turnos.
        //    Igual se arma un bloque por turno, para que el reporte muestre el
        //    horario licenciado con su T.C., su goce de haberes y su motivo. Van
        //    con `esperado = 0`: el día no suma ni resta horas.
        $completa = $delFuncionario->first(fn (Licencia $licencia): bool => (bool) $licencia->tCompleto);

        if ($completa instanceof Licencia) {
            $bloques = array_map(
                fn (Turno $turno): array => $this->bloque($turno, $completa, 0, self::LICENCIA, [], [
                    'entradaExigida' => false,
                    'salidaExigida' => false,
                ]),
                $turnos,
            );

            return $this->dia($fecha, self::LICENCIA, $completa->motivo, $bloques, $delDia);
        }

        // 4. Recién acá se miran las marcaciones.
        $bloques = $this->evaluarTurnos(
            $turnos,
            $delFuncionario,
            $delDia,
            $marcas->get($fecha->copy()->addDay()->toDateString(), []),
        );

        return $this->dia($fecha, null, null, $bloques, $delDia);
    }

    /**
     * Evalúa cada turno del día repartiendo las marcas entre ellos. Cada marca
     * se consume una sola vez: la que ya fue entrada de un turno no puede ser
     * después la salida de otro.
     *
     * @param  list<Turno>  $turnos
     * @param  Collection<int, Licencia>  $licencias
     * @param  list<int>  $delDia
     * @param  list<int>  $delDiaSiguiente
     * @return list<Bloque>
     */
    private function evaluarTurnos(array $turnos, Collection $licencias, array $delDia, array $delDiaSiguiente): array
    {
        $ventanas = $this->ventanas($turnos);
        $usadas = [];
        $bloques = [];

        foreach ($turnos as $indice => $turno) {
            $bloques[] = $this->evaluarTurno(
                $turno,
                $ventanas[$indice],
                $licencias->first(fn (Licencia $licencia): bool => $licencia->turno_id === null || $licencia->turno_id === $turno->id),
                $delDia,
                $turno->siguienteDia ? $delDiaSiguiente : $delDia,
                $usadas,
            );
        }

        return $bloques;
    }

    /**
     * Ventanas de entrada y salida de cada turno, ya recortadas para que no se
     * pisen entre turnos consecutivos del mismo día.
     *
     * Cuando la ventana de salida de un turno se solapa con la de entrada del
     * siguiente (pasa en los turnos partidos), la marca del medio sería ambigua.
     * El corte va en el punto medio del solape: por debajo es salida del primero,
     * por encima es entrada del segundo.
     *
     * @param  list<Turno>  $turnos
     * @return list<array{eMin: int, eMax: int, sMin: int, sMax: int, avisos: list<string>}>
     */
    private function ventanas(array $turnos): array
    {
        $ventanas = [];

        foreach ($turnos as $turno) {
            $ventanas[] = [
                'eMin' => $this->segundos($turno->eMinima) ?? 0,
                'eMax' => $this->segundos($turno->eMaxima) ?? 86399,
                'sMin' => $this->segundos($turno->sMinima) ?? 0,
                'sMax' => $this->segundos($turno->sMaxima) ?? 86399,
                'avisos' => [],
            ];
        }

        foreach (array_keys($ventanas) as $indice) {
            $siguiente = $indice + 1;

            if (! isset($ventanas[$siguiente]) || $turnos[$indice]->siguienteDia) {
                continue;
            }

            if ($ventanas[$indice]['sMax'] <= $ventanas[$siguiente]['eMin']) {
                continue;
            }

            $corte = intdiv($ventanas[$indice]['sMax'] + $ventanas[$siguiente]['eMin'], 2);
            $aviso = 'Ventanas solapadas con el otro turno del día: el corte quedó en '.self::hora($corte).'.';

            $ventanas[$indice]['sMax'] = $corte;
            $ventanas[$indice]['avisos'][] = $aviso;
            $ventanas[$siguiente]['eMin'] = $corte;
            $ventanas[$siguiente]['avisos'][] = $aviso;
        }

        return $ventanas;
    }

    /**
     * Evalúa un turno contra las marcas del día.
     *
     * @param  array{eMin: int, eMax: int, sMin: int, sMax: int, avisos: list<string>}  $ventana
     * @param  list<int>  $marcasEntrada
     * @param  list<int>  $marcasSalida
     * @param  list<int>  $usadas  marcas ya consumidas por otro turno (por referencia)
     * @return Bloque
     */
    private function evaluarTurno(
        Turno $turno,
        array $ventana,
        ?Licencia $licencia,
        array $marcasEntrada,
        array $marcasSalida,
        array &$usadas,
    ): array {
        $hEntrada = $this->segundos($turno->hEntrada) ?? 0;
        $hSalida = $this->segundos($turno->hSalida) ?? 0;
        $hTolerancia = $this->segundos($turno->hTolerancia) ?? $hEntrada;
        $sTolerancia = $this->segundos($turno->sTolerancia) ?? $hSalida;
        $esperado = $this->esperado($turno, $hEntrada, $hSalida);
        $avisos = array_merge($ventana['avisos'], $this->revisarTurno($turno, $hEntrada, $hSalida, $hTolerancia, $sTolerancia, $ventana));

        if ($ventana['eMin'] > $ventana['eMax'] || $ventana['sMin'] > $ventana['sMax']) {
            return $this->bloque($turno, $licencia, $esperado, self::TURNO_INVALIDO, $avisos);
        }

        // Tramo licenciado recortado al turno. Fuera de él quedan uno o dos
        // tramos de trabajo, y solo esos exigen marca.
        [$licEntra, $licSale] = $this->tramoLicencia($licencia, $hEntrada, $hSalida + ($turno->siguienteDia ? 86400 : 0));
        $conLicencia = $licEntra !== null && $licSale !== null;
        $entradaExigida = ! $conLicencia || $licEntra > $hEntrada;
        $salidaExigida = ! $conLicencia || $licSale < $hSalida + ($turno->siguienteDia ? 86400 : 0);

        $entrada = $entradaExigida ? $this->primeraMarca($marcasEntrada, $ventana['eMin'], $ventana['eMax'], $usadas) : null;
        $salida = $salidaExigida ? $this->ultimaMarca($marcasSalida, $ventana['sMin'], $ventana['sMax'], $usadas) : null;

        // En el turno nocturno la salida es del día siguiente: para las cuentas
        // se corre un día entero, así la resta con la entrada da positivo. Las
        // ventanas siguen crudas, porque se comparan contra marcas de ese día.
        $cruce = $turno->siguienteDia ? 86400 : 0;
        $finTurno = $hSalida + $cruce;
        $toleranciaSalida = $sTolerancia + $cruce;
        $salidaReal = $salida === null ? null : $salida + $cruce;

        // El atraso se cuenta en **minutos completos** desde la hora nominal de
        // entrada, y la tolerancia se mide en la misma unidad. Con entrada 08:00
        // y tolerancia 08:10 hay diez minutos de gracia, así que el minuto 08:10
        // entero está adentro: a las 08:10:59 todavía van 10 minutos y no hay
        // atraso; a las 08:11:00 van 11 y sí lo hay.
        //
        // Los segundos se descartan a propósito. El reloj los registra, pero la
        // tolerancia se concede en minutos: sancionar por 08:10:01 castigaría un
        // segundo dentro del minuto que el reglamento regala.
        $minutosDeAtraso = $entrada === null ? 0 : intdiv(max($entrada - $hEntrada, 0), 60);
        $minutosDeTolerancia = intdiv(max($hTolerancia - $hEntrada, 0), 60);
        $enTolerancia = $entrada === null || $minutosDeAtraso <= $minutosDeTolerancia;

        $atraso = $enTolerancia ? 0 : $minutosDeAtraso * 60;
        $anticipo = $salidaReal !== null && $salidaReal < $toleranciaSalida ? $toleranciaSalida - $salidaReal : 0;

        // Llegar dentro de la tolerancia cuenta como llegar a la hora; quedarse
        // después de la salida no suma. Fuera de la tolerancia el inicio es el
        // minuto en punto del atraso y no la marca cruda: si el descuento dice
        // once minutos, las horas computadas tienen que descontar esos once y no
        // once y medio.
        $inicio = $entrada === null ? null : ($enTolerancia ? $hEntrada : min($hEntrada + $atraso, $finTurno));
        $fin = $salidaReal === null ? null : ($salidaReal >= $toleranciaSalida ? $finTurno : max($salidaReal, $hEntrada));

        $licenciado = $conLicencia ? $licSale - $licEntra : 0;
        $trabajado = $conLicencia
            ? $this->trabajadoConLicencia($inicio, $fin, $licEntra, $licSale, $entradaExigida, $salidaExigida)
            : ($inicio === null || $fin === null ? 0 : max(0, $fin - $inicio));

        $estado = $this->estadoBloque(
            $entrada,
            $salida,
            $entradaExigida,
            $salidaExigida,
            $atraso,
            $conLicencia,
            $marcasSalida,
            $ventana,
            $usadas,
            $avisos,
        );

        return $this->bloque($turno, $licencia, $esperado, $estado, $avisos, [
            'entrada' => $entrada,
            'salida' => $salida,
            'entradaExigida' => $entradaExigida,
            'salidaExigida' => $salidaExigida,
            'atraso' => $atraso,
            'anticipo' => $anticipo,
            'permanencia' => $entrada === null || $salidaReal === null ? null : max(0, $salidaReal - $entrada),
            'licenciado' => $licenciado,
            'computado' => min($esperado, $licenciado + $trabajado),
        ]);
    }

    /**
     * Suma de los tramos de trabajo que la licencia parcial deja libres: el
     * previo (`hEntrada` → inicio de la licencia) y el posterior (fin de la
     * licencia → `hSalida`). Cada uno solo se acredita si hay marca del lado que
     * lo cierra.
     */
    private function trabajadoConLicencia(?int $inicio, ?int $fin, int $licEntra, int $licSale, bool $entradaExigida, bool $salidaExigida): int
    {
        $trabajado = 0;

        if ($entradaExigida && $inicio !== null) {
            $trabajado += max(0, $licEntra - $inicio);
        }

        if ($salidaExigida && $fin !== null) {
            $trabajado += max(0, $fin - $licSale);
        }

        return $trabajado;
    }

    /**
     * Estado del bloque a partir de lo que se pudo leer.
     *
     * `abandono` es siempre irse antes de tiempo: hay una marca real por debajo
     * de `sMinima`, o la licencia dejó un hueco al principio del turno que había
     * que marcar y nadie marcó. Marcar después de `sMaxima` no es abandono: el
     * funcionario se quedó de más, la falta es no haber marcado en la ventana.
     *
     * @param  list<int>  $marcasSalida
     * @param  array{eMin: int, eMax: int, sMin: int, sMax: int, avisos: list<string>}  $ventana
     * @param  list<int>  $usadas
     * @param  list<string>  $avisos
     */
    private function estadoBloque(
        ?int $entrada,
        ?int $salida,
        bool $entradaExigida,
        bool $salidaExigida,
        int $atraso,
        bool $conLicencia,
        array $marcasSalida,
        array $ventana,
        array $usadas,
        array &$avisos,
    ): string {
        $sinEntrada = $entradaExigida && $entrada === null;
        $sinSalida = $salidaExigida && $salida === null;

        if (! $sinEntrada && ! $sinSalida) {
            return $atraso > 0 ? self::ATRASO : self::CUMPLE;
        }

        $sueltas = array_values(array_diff($marcasSalida, $usadas));
        $antes = array_filter($sueltas, fn (int $marca): bool => $marca > $ventana['eMax'] && $marca < $ventana['sMin']);
        $despues = array_filter($sueltas, fn (int $marca): bool => $marca > $ventana['sMax']);

        if ($despues !== []) {
            $avisos[] = 'Marcó después de la máxima hora de salida ('.self::hora($ventana['sMax']).').';
        }

        if ($sinSalida && $antes !== []) {
            $avisos[] = 'Marcó antes de la mínima hora de salida ('.self::hora($ventana['sMin']).').';

            return self::ABANDONO;
        }

        // Licencia parcial que no llega a cubrir la hora de entrada: quedaba un
        // hueco que había que marcar sí o sí.
        if ($sinEntrada && $conLicencia) {
            $avisos[] = 'La licencia no cubre la hora de entrada: la marca era obligatoria.';

            return self::ABANDONO;
        }

        if ($sinEntrada && $sinSalida) {
            return self::FALTA;
        }

        return $sinEntrada ? self::SIN_ENTRADA : self::SIN_SALIDA;
    }

    /**
     * Revisiones de configuración del turno. No frenan el cálculo (salvo las
     * ventanas invertidas, que sí lo hacen), pero se muestran junto al día para
     * que no se lea como un resultado limpio lo que es un turno mal cargado.
     *
     * @param  array{eMin: int, eMax: int, sMin: int, sMax: int, avisos: list<string>}  $ventana
     * @return list<string>
     */
    private function revisarTurno(Turno $turno, int $hEntrada, int $hSalida, int $hTolerancia, int $sTolerancia, array $ventana): array
    {
        $avisos = [];

        if ($ventana['eMin'] > $ventana['eMax']) {
            $avisos[] = 'Ventana de entrada invertida (mínima mayor que máxima).';
        }

        if ($ventana['sMin'] > $ventana['sMax']) {
            $avisos[] = 'Ventana de salida invertida (mínima mayor que máxima).';
        }

        if ($hTolerancia < $hEntrada || $hTolerancia > $ventana['eMax']) {
            $avisos[] = 'La tolerancia de entrada cae fuera de la ventana de entrada.';
        }

        if ($ventana['sMin'] === $sTolerancia) {
            $avisos[] = 'Mínima hora de salida igual a la tolerancia: nunca se reporta salida anticipada.';
        }

        if ($turno->siguienteDia && $hSalida > $hEntrada) {
            $avisos[] = 'Marcado como salida al día siguiente, pero la salida es posterior a la entrada.';
        }

        if (! $turno->siguienteDia && $hSalida < $hEntrada) {
            $avisos[] = 'La salida es anterior a la entrada, pero el turno no cruza medianoche.';
        }

        if ((float) $turno->hTrabajadas <= 0) {
            $avisos[] = 'El turno no declara horas trabajadas.';
        }

        return $avisos;
    }

    /**
     * Horas esperadas del turno. `hTrabajadas` manda, pero hay turnos migrados
     * con 0 aunque tengan horario real: ahí se calcula del horario.
     */
    private function esperado(Turno $turno, int $hEntrada, int $hSalida): int
    {
        $declarado = (int) round(((float) $turno->hTrabajadas) * 3600);

        if ($declarado > 0) {
            return $declarado;
        }

        return $hSalida >= $hEntrada ? $hSalida - $hEntrada : 86400 - $hEntrada + $hSalida;
    }

    /**
     * Tramo licenciado recortado al turno, en segundos desde medianoche.
     * `[null, null]` si la licencia no acota horas o no toca el turno.
     *
     * @return array{0: ?int, 1: ?int}
     */
    private function tramoLicencia(?Licencia $licencia, int $hEntrada, int $hSalida): array
    {
        if (! $licencia instanceof Licencia) {
            return [null, null];
        }

        $entra = $this->segundos($licencia->lEntra);
        $sale = $this->segundos($licencia->lSale);

        if ($entra === null || $sale === null || $sale <= $entra) {
            return [null, null];
        }

        $recortadoEntra = max($entra, $hEntrada);
        $recortadoSale = min($sale, $hSalida);

        return $recortadoSale <= $recortadoEntra ? [null, null] : [$recortadoEntra, $recortadoSale];
    }

    /**
     * Primera marca sin usar dentro de la ventana. La marca elegida se agrega a
     * `$usadas` para que ningún otro turno la vuelva a tomar.
     *
     * @param  list<int>  $marcas
     * @param  list<int>  $usadas
     */
    private function primeraMarca(array $marcas, int $desde, int $hasta, array &$usadas): ?int
    {
        foreach ($marcas as $marca) {
            if ($marca >= $desde && $marca <= $hasta && ! in_array($marca, $usadas, true)) {
                $usadas[] = $marca;

                return $marca;
            }
        }

        return null;
    }

    /**
     * Última marca sin usar dentro de la ventana.
     *
     * @param  list<int>  $marcas
     * @param  list<int>  $usadas
     */
    private function ultimaMarca(array $marcas, int $desde, int $hasta, array &$usadas): ?int
    {
        $elegida = null;

        foreach ($marcas as $marca) {
            if ($marca >= $desde && $marca <= $hasta && ! in_array($marca, $usadas, true)) {
                $elegida = $marca;
            }
        }

        if ($elegida !== null) {
            $usadas[] = $elegida;
        }

        return $elegida;
    }

    /**
     * Arma la ficha del día. Si `$estado` viene en `null` se deduce del bloque
     * más grave.
     *
     * @param  list<Bloque>  $bloques
     * @param  list<int>  $marcas
     * @return Dia
     */
    private function dia(Carbon $fecha, ?string $estado, ?string $motivo, array $bloques, array $marcas): array
    {
        $peor = self::CUMPLE;
        $usadas = 0;

        foreach ($bloques as $bloque) {
            if (self::GRAVEDAD[$bloque['estado']] > self::GRAVEDAD[$peor]) {
                $peor = $bloque['estado'];
            }

            $usadas += ($bloque['entrada'] === null ? 0 : 1) + ($bloque['salida'] === null ? 0 : 1);
        }

        return [
            'fecha' => $fecha,
            'estado' => $estado ?? ($bloques === [] ? self::FALTA : $peor),
            'motivo' => $motivo,
            'bloques' => $bloques,
            'marcas' => $marcas,
            'marcasUsadas' => $usadas,
            'atraso' => array_sum(array_column($bloques, 'atraso')),
            'anticipo' => array_sum(array_column($bloques, 'anticipo')),
            'computado' => array_sum(array_column($bloques, 'computado')),
            'esperado' => array_sum(array_column($bloques, 'esperado')),
        ];
    }

    /**
     * @param  list<string>  $avisos
     * @param  array<string, mixed>  $datos
     * @return Bloque
     */
    private function bloque(Turno $turno, ?Licencia $licencia, int $esperado, string $estado, array $avisos, array $datos = []): array
    {
        return array_merge([
            'turno' => $turno,
            'entrada' => null,
            'salida' => null,
            'entradaExigida' => true,
            'salidaExigida' => true,
            'atraso' => 0,
            'anticipo' => 0,
            'permanencia' => null,
            'licenciado' => 0,
            'computado' => 0,
            'esperado' => $esperado,
            'estado' => $estado,
            'licencia' => $licencia,
            'avisos' => $avisos,
        ], $datos);
    }

    /**
     * Turnos que le tocan al funcionario esa fecha, ordenados por hora de
     * entrada. Si dos asignaciones vigentes traen el mismo turno (rangos
     * solapados del SIA), gana la que empieza más tarde.
     *
     * @param  Collection<int, AsignacionTurno>  $asignaciones
     * @return list<Turno>
     */
    private function turnosDelDia(Carbon $fecha, Collection $asignaciones): array
    {
        // `dia` guarda 1=Domingo … 7=Sábado, igual que DAYOFWEEK() de MySQL.
        $dia = $fecha->dayOfWeek + 1;

        return $asignaciones
            ->filter(fn (AsignacionTurno $asignacion): bool => $asignacion->turno instanceof Turno
                && (int) $asignacion->turno->dia === $dia
                && $asignacion->desde?->copy()->startOfDay()->lessThanOrEqualTo($fecha)
                && $asignacion->hasta?->copy()->startOfDay()->greaterThanOrEqualTo($fecha))
            ->sortByDesc(fn (AsignacionTurno $asignacion): string => $asignacion->desde?->toDateString() ?? '')
            ->unique('turno_id')
            ->map(fn (AsignacionTurno $asignacion): Turno => $asignacion->turno)
            ->sortBy(fn (Turno $turno): int => $this->segundos($turno->hEntrada) ?? 0)
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, AsignacionTurno>
     */
    private function asignacionesDelRango(string $ci, Carbon $desde, Carbon $hasta): Collection
    {
        return AsignacionTurno::query()
            ->with('turno')
            ->where('ci', $ci)
            ->whereDate('desde', '<=', $hasta)
            ->whereDate('hasta', '>=', $desde)
            ->get();
    }

    /**
     * Solo las fechas con motivo cargado: la tabla tiene una fila por día del
     * calendario, y las que no tienen motivo son días comunes.
     *
     * @return Collection<string, DiaExcepcional>
     */
    private function excepcionalesDelRango(Carbon $desde, Carbon $hasta): Collection
    {
        return DiaExcepcional::query()
            ->whereNotNull('motivoInasistencia')
            ->whereDate('fecha', '>=', $desde)
            ->whereDate('fecha', '<=', $hasta)
            ->get()
            ->keyBy(fn (DiaExcepcional $dia): string => $dia->fecha->toDateString());
    }

    /**
     * @return Collection<string, Collection<int, Licencia>>
     */
    private function licenciasDelRango(string $ci, Carbon $desde, Carbon $hasta): Collection
    {
        return Licencia::query()
            ->where('ci', $ci)
            // Solo las aprobadas justifican la ausencia. Una solicitud que el
            // funcionario cargó desde Mamoré y que Recursos Humanos todavía no
            // miró no puede taparle la falta, y una rechazada menos: alcanzaría
            // con pedir una licencia para que el día dejara de contar.
            ->where('estado', 'Aprobado')
            ->whereDate('fecha', '>=', $desde)
            ->whereDate('fecha', '<=', $hasta)
            ->get()
            ->groupBy(fn (Licencia $licencia): string => $licencia->fecha->toDateString());
    }

    /**
     * Marcas de cada día en segundos desde medianoche, ordenadas y sin los
     * rebotes del reloj.
     *
     * @return Collection<string, list<int>>
     */
    private function marcasDelRango(string $ci, Carbon $desde, Carbon $hasta): Collection
    {
        return Asistencia::query()
            ->where('ci', $ci)
            ->enRango($desde, $hasta)
            ->orderBy('fecha')
            ->orderBy('hora')
            ->get()
            ->groupBy(fn (Asistencia $marcacion): string => $marcacion->fecha->toDateString())
            ->map(fn (Collection $delDia): array => $this->deduplicar(
                $delDia->map(fn (Asistencia $marcacion): ?int => $this->segundos($marcacion->hora))
                    ->filter(fn (?int $segundos): bool => $segundos !== null)
                    ->sort()
                    ->values()
                    ->all()
            ));
    }

    /**
     * Colapsa las marcas separadas por menos de {@see self::DEDUPE_SEGUNDOS}.
     *
     * @param  list<int>  $marcas
     * @return list<int>
     */
    private function deduplicar(array $marcas): array
    {
        $limpias = [];

        foreach ($marcas as $marca) {
            if ($limpias !== [] && $marca - end($limpias) < self::DEDUPE_SEGUNDOS) {
                continue;
            }

            $limpias[] = $marca;
        }

        return $limpias;
    }

    /**
     * Segundos desde medianoche de una columna horaria. La fecha se descarta:
     * `turnos` y `asistencias` guardan la hora sobre la base 1899-12-30.
     */
    private function segundos(?Carbon $hora): ?int
    {
        return $hora === null ? null : $hora->hour * 3600 + $hora->minute * 60 + $hora->second;
    }
}
