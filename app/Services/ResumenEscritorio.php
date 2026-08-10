<?php

namespace App\Services;

use App\Models\AsignacionTurno;
use App\Models\Asistencia;
use App\Models\DiaExcepcional;
use App\Models\Equipo;
use App\Models\Licencia;
use App\Models\Persona;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Arma los paneles del escritorio desde la base local MySQL.
 *
 * Vive aparte del controlador porque son varias consultas con sus mañas: la
 * tabla `asistencias` tiene 4,4 millones de filas y cada panel tiene que caer
 * sobre el índice `(fecha, ci)`. Dos reglas que se respetan en todo el archivo:
 *
 * 1. **Nunca `whereDate()` sobre `fecha`.** Envolver la columna en una función
 *    anula el índice y MySQL recorre la tabla entera. Va siempre el rango
 *    `>= inicio` y `< día siguiente`.
 * 2. **`fecha` y `hora` son columnas distintas.** `fecha` es el día a
 *    medianoche; `hora` guarda solo la hora sobre la fecha base 1899-12-30.
 *    Ordenar por las dos juntas obliga a MySQL a ordenar en memoria (medido:
 *    1.036 ms); buscar primero el día y después la hora de ese día son dos
 *    consultas indexadas (2 ms).
 */
class ResumenEscritorio
{
    /**
     * Minutos que se cachean los paneles del día. El escritorio se mira varias
     * veces por hora y las marcaciones entran de a poco.
     */
    private const CACHE_MINUTOS = 5;

    /**
     * La calidad de los datos se cuenta sobre la tabla entera, sin índice que
     * ayude (medido: 1,3 s). Es un dato que cambia lento: se cachea una hora.
     */
    private const CACHE_CALIDAD_MINUTOS = 60;

    /**
     * Días hacia atrás que se miran para saber cómo viene un día normal.
     */
    private const DIAS_COMPARACION = 28;

    /**
     * Estado de la captura: si los equipos están reportando y si lo que llegó
     * hoy se parece a un día normal.
     *
     * @return array{equipos: array{total: int, en_linea: int, fuera_linea: int}, ultima_marcacion: ?Carbon, minutos_sin_marcar: ?int, equipo_desactualizado: ?Equipo, comparacion: array{hoy: int, tipico: ?int, desvio: ?int}}
     */
    public function captura(): array
    {
        // En la caché solo van escalares. Guardar el Carbon o el modelo Equipo
        // los serializa, y al recuperarlos de una caché en base vuelven como
        // `__PHP_Incomplete_Class`: la vista revienta al pedirles un método.
        $datos = Cache::remember('escritorio.captura', now()->addMinutes(self::CACHE_MINUTOS), function (): array {
            $ultima = $this->ultimaMarcacion();

            return [
                'equipos' => [
                    'total' => Equipo::count(),
                    'en_linea' => Equipo::where('en_linea', true)->count(),
                    'fuera_linea' => Equipo::where('en_linea', false)->count(),
                ],
                'ultima_marcacion' => $ultima?->toIso8601String(),
                'comparacion' => $this->compararConDiaTipico(),
            ];
        });

        $ultima = $datos['ultima_marcacion'] === null ? null : Carbon::parse($datos['ultima_marcacion']);

        return [
            'equipos' => $datos['equipos'],
            'ultima_marcacion' => $ultima,
            // Entero a propósito: `diffInMinutes()` devuelve float y la vista
            // reparte los minutos en horas y días con `intdiv()`, que con un
            // float aborta la petición.
            'minutos_sin_marcar' => $ultima === null ? null : (int) $ultima->diffInMinutes(now()),
            // Fuera de la caché: es un modelo, y además cuesta 2 ms.
            'equipo_desactualizado' => $this->equipoMasDesactualizado(),
            'comparacion' => $datos['comparacion'],
        ];
    }

    /**
     * Lo que pasó hoy: cuánto se marcó, quiénes, y quiénes tenían que marcar.
     *
     * `con_turno` sale de los turnos asignados. Mientras esa tabla esté vacía
     * —la migración del SIA todavía no la trajo— viaja en `null` para que la
     * vista no muestre un «0 sin marcar» que parece un dato y es una tabla sin
     * cargar.
     *
     * @return array{marcaciones: int, personas: int, con_turno: ?int, sin_marcar: ?int, licenciados: int}
     */
    public function hoy(): array
    {
        return Cache::remember('escritorio.hoy', now()->addMinutes(self::CACHE_MINUTOS), function (): array {
            $hoy = today();
            $delDia = $this->enRango($hoy, $hoy);

            $cisConTurno = $this->cisConTurnoEse($hoy);
            $cisQueMarcaron = $this->enRango($hoy, $hoy)->distinct()->pluck('ci')
                ->map(fn ($ci): string => trim((string) $ci))
                ->all();

            return [
                'marcaciones' => $delDia->count(),
                'personas' => count($cisQueMarcaron),
                'con_turno' => $cisConTurno === null ? null : count($cisConTurno),
                'sin_marcar' => $cisConTurno === null
                    ? null
                    : count(array_diff($cisConTurno, $cisQueMarcaron)),
                'licenciados' => Licencia::query()
                    ->where('fecha', '>=', $hoy)
                    ->where('fecha', '<', $hoy->copy()->addDay())
                    ->distinct()
                    ->count('ci'),
            ];
        });
    }

    /**
     * Marcaciones de hoy repartidas por hora del día, en 24 casilleros.
     *
     * Es el panel que más dice de un vistazo: se ve el pico de entrada, el de
     * salida y si a media mañana el reloj dejó de reportar.
     *
     * @return array{horas: list<int>, totales: list<int>, pico: ?int}
     */
    public function histogramaHorario(): array
    {
        $totales = Cache::remember('escritorio.histograma', now()->addMinutes(self::CACHE_MINUTOS), function (): array {
            $hoy = today();

            $porHora = $this->enRango($hoy, $hoy)
                ->selectRaw($this->horaDelDia().' as h, count(*) as total')
                ->groupBy('h')
                ->pluck('total', 'h');

            return collect(range(0, 23))
                ->map(fn (int $hora): int => (int) $porHora->get($hora, 0))
                ->all();
        });

        $maximo = max($totales);

        return [
            'horas' => range(0, 23),
            'totales' => $totales,
            // La hora con más marcaciones, para señalarla en el eje.
            'pico' => $maximo > 0 ? (int) array_search($maximo, $totales, true) : null,
        ];
    }

    /**
     * Marcaciones por día de los últimos `$dias`, para el gráfico de tendencia.
     *
     * @return array{dias: list<string>, totales: list<int>, etiquetas: list<string>}
     */
    public function tendencia(int $dias = 30): array
    {
        $hasta = today();
        $desde = $hasta->copy()->subDays($dias - 1);

        $totalesPorFecha = Cache::remember(
            "escritorio.tendencia.{$dias}",
            now()->addMinutes(self::CACHE_MINUTOS),
            fn (): array => $this->totalesPorFecha($desde, $hasta),
        );

        $fechas = collect(range($dias - 1, 0))->map(fn (int $atras): Carbon => $hasta->copy()->subDays($atras));

        return [
            'dias' => $fechas->map(fn (Carbon $dia): string => $dia->format('d/m'))->all(),
            'totales' => $fechas->map(fn (Carbon $dia): int => $totalesPorFecha[$dia->toDateString()] ?? 0)->all(),
            'etiquetas' => $fechas->map(fn (Carbon $dia): string => $dia->format('d/m/Y'))->all(),
        ];
    }

    /**
     * Defectos conocidos de los datos migrados, para que no se lean como si
     * estuvieran limpios.
     *
     * @return array{total: int, tipos: array<string, int>, antes_2000: int, futuras: int}
     */
    public function calidadDatos(): array
    {
        return Cache::remember('escritorio.calidad', now()->addMinutes(self::CACHE_CALIDAD_MINUTOS), function (): array {
            $manana = today()->addDay();

            return [
                'total' => Asistencia::count(),
                // Una sola pasada para los tres tipos: contar cada uno por
                // separado serían tres recorridos de la tabla.
                'tipos' => Asistencia::query()
                    ->selectRaw('tipo, count(*) as total')
                    ->groupBy('tipo')
                    ->pluck('total', 'tipo')
                    ->map(fn ($total): int => (int) $total)
                    ->all(),
                'antes_2000' => Asistencia::where('fecha', '<', '2000-01-01')->count(),
                // Fecha futura: la batería del RTC del reloj se agota y las
                // marcaciones salen con años imposibles (2064, 2103).
                'futuras' => Asistencia::where('fecha', '>=', $manana)->count(),
            ];
        });
    }

    /**
     * Lo que viene: licencias en curso y el próximo día que no se controla.
     *
     * @return array{licencias_hoy: int, proximo_excepcional: ?DiaExcepcional, funcionarios: int, turnos_sin_asignar: bool}
     */
    public function agenda(): array
    {
        // Mismo criterio que en captura(): en la caché no van modelos.
        $datos = Cache::remember('escritorio.agenda', now()->addMinutes(self::CACHE_MINUTOS), function (): array {
            $hoy = today();

            return [
                'licencias_hoy' => Licencia::query()
                    ->where('fecha', '>=', $hoy)
                    ->where('fecha', '<', $hoy->copy()->addDay())
                    ->count(),
                'funcionarios' => Persona::count(),
                // Aviso para el instalador: sin asignaciones no hay control de
                // cumplimiento posible, por más marcaciones que entren.
                'turnos_sin_asignar' => AsignacionTurno::query()->doesntExist(),
            ];
        });

        return [
            ...$datos,
            'proximo_excepcional' => DiaExcepcional::query()
                ->whereNotNull('motivoInasistencia')
                ->where('fecha', '>=', today())
                ->orderBy('fecha')
                ->first(),
        ];
    }

    /**
     * Equipos activos que están fuera de línea, para la tabla del pie.
     *
     * @return Collection<int, Equipo>
     */
    public function equiposFueraDeLinea(): Collection
    {
        return Equipo::query()
            ->where('en_linea', false)
            ->where('activo', true)
            ->orderBy('nombre')
            ->get();
    }

    /**
     * Momento de la última marcación registrada, sin contar las de fecha futura.
     *
     * Se resuelve en dos pasos —primero el día, después la hora de ese día—
     * porque `fecha` y `hora` son columnas separadas y pedirle a MySQL que
     * ordene por las dos cuesta más de un segundo sobre 4,4 millones de filas.
     *
     * Va por `orderByDesc(...)->value(...)` en vez de `max(...)`: con el filtro
     * de fecha futura MySQL no siempre aplica la optimización de MIN/MAX sobre
     * el índice, y la consulta se convierte en un recorrido completo. Con la
     * eliminación lógica encima —que `asistencias` ya no tiene— eran 885 ms
     * contra 6 ms; la forma que se mide rápida es esta, así que se conserva.
     */
    public function ultimaMarcacion(): ?Carbon
    {
        $manana = today()->addDay();

        $fecha = Asistencia::query()
            ->where('fecha', '<', $manana)
            ->orderByDesc('fecha')
            ->value('fecha');

        if ($fecha === null) {
            return null;
        }

        $dia = Carbon::parse($fecha)->startOfDay();

        $hora = Asistencia::query()
            ->where('fecha', '>=', $dia)
            ->where('fecha', '<', $dia->copy()->addDay())
            ->orderByDesc('hora')
            ->value('hora');

        if ($hora === null) {
            return $dia;
        }

        // `hora` viene sobre la fecha base 1899-12-30: solo sirve la parte
        // horaria, que se monta sobre el día real.
        return $dia->copy()->setTimeFrom(Carbon::parse($hora));
    }

    /**
     * El equipo activo que hace más tiempo no se sincroniza. Los que nunca se
     * sincronizaron van primero: son los que nadie probó todavía.
     */
    private function equipoMasDesactualizado(): ?Equipo
    {
        return Equipo::query()
            ->where('activo', true)
            ->orderByRaw('ultima_sync IS NULL DESC')
            ->orderBy('ultima_sync')
            ->first();
    }

    /**
     * Compara lo marcado hoy hasta esta hora contra lo que se marcó, hasta la
     * misma hora, en los otros días de la misma semana laboral.
     *
     * El corte por hora es lo que hace útil la comparación: a las 09:00 un día
     * normal lleva la mitad de sus marcaciones, así que medir el total del día
     * daría siempre «muy por debajo» toda la mañana.
     *
     * @return array{hoy: int, tipico: ?int, desvio: ?int}
     */
    private function compararConDiaTipico(): array
    {
        $hoy = today();
        $hasta = (int) now()->format('H');
        $desde = $hoy->copy()->subDays(self::DIAS_COMPARACION);

        $porDia = collect($this->totalesPorFecha(
            $desde,
            $hoy,
            fn (Builder $query) => $query->whereRaw($this->horaDelDia().' <= ?', [$hasta]),
        ));

        $deHoy = (int) $porDia->get($hoy->toDateString(), 0);

        // Solo los mismos días de la semana: un lunes no se parece a un sábado.
        $comparables = $porDia
            ->filter(fn ($total, string $dia): bool => $dia !== $hoy->toDateString()
                && Carbon::parse($dia)->dayOfWeek === $hoy->dayOfWeek)
            ->map(fn ($total): int => (int) $total)
            ->values();

        if ($comparables->count() < 2) {
            return ['hoy' => $deHoy, 'tipico' => null, 'desvio' => null];
        }

        $tipico = (int) round($comparables->median());

        return [
            'hoy' => $deHoy,
            'tipico' => $tipico,
            'desvio' => $tipico > 0 ? (int) round((($deHoy - $tipico) / $tipico) * 100) : null,
        ];
    }

    /**
     * Carnets con turno asignado ese día. `null` si todavía no se migraron las
     * asignaciones: no es lo mismo «nadie tiene turno» que «no hay datos».
     *
     * @return list<string>|null
     */
    private function cisConTurnoEse(Carbon $fecha): ?array
    {
        if (AsignacionTurno::query()->doesntExist()) {
            return null;
        }

        // `turnos.dia` guarda 1=Domingo … 7=Sábado, igual que DAYOFWEEK() de
        // MySQL y que ProcesadorAsistencia.
        $diaSemana = $fecha->dayOfWeek + 1;

        return AsignacionTurno::query()
            ->join('turnos', 'turnos.id', '=', 'asignacion_turnos.turno_id')
            ->whereNull('turnos.deleted_at')
            ->where('turnos.dia', $diaSemana)
            ->where('asignacion_turnos.desde', '<=', $fecha->copy()->endOfDay())
            ->where('asignacion_turnos.hasta', '>=', $fecha->copy()->startOfDay())
            ->distinct()
            ->pluck('asignacion_turnos.ci')
            ->map(fn ($ci): string => trim((string) $ci))
            ->all();
    }

    /**
     * Marcaciones por día del rango, con la fecha (`Y-m-d`) como clave.
     *
     * Agrupa por la columna `fecha` cruda y no por `DATE(fecha)`: la columna
     * guarda siempre el día a medianoche —verificado, 0 excepciones en los 4,4
     * millones de filas—, así que la función no aporta nada y en cambio anula
     * el índice.
     *
     * @param  (callable(Builder<Asistencia>): mixed)|null  $filtro
     * @return array<string, int>
     */
    private function totalesPorFecha(Carbon $desde, Carbon $hasta, ?callable $filtro = null): array
    {
        $consulta = $this->enRango($desde, $hasta);

        if ($filtro !== null) {
            $filtro($consulta);
        }

        return $consulta
            ->selectRaw('fecha, count(*) as total')
            ->groupBy('fecha')
            ->pluck('total', 'fecha')
            ->mapWithKeys(fn ($total, $fecha): array => [
                Carbon::parse($fecha)->toDateString() => (int) $total,
            ])
            ->all();
    }

    /**
     * Expresión SQL que saca la hora (0-23) de la columna `hora`.
     *
     * Depende del motor: producción es MySQL, y las pruebas corren en SQLite,
     * que no tiene `HOUR()`. Se resuelve por SQL y no en PHP para no traerse
     * las filas de 28 días a memoria solo para contarlas.
     *
     * Descarta la fecha base de la columna, que además viene sucia del legado:
     * 539 filas de 4,4 millones la traen distinta de 1899-12-30.
     */
    private function horaDelDia(): string
    {
        return match (Asistencia::query()->getConnection()->getDriverName()) {
            'sqlite' => "CAST(strftime('%H', hora) AS INTEGER)",
            default => 'HOUR(hora)',
        };
    }

    /**
     * Marcaciones de un rango de días, ambos extremos incluidos. El recorte lo
     * hace el scope del modelo, que es el mismo que usan el listado y los
     * reportes; acá queda el atajo para no repetir `Asistencia::query()`.
     *
     * @return Builder<Asistencia>
     */
    private function enRango(Carbon $desde, Carbon $hasta): Builder
    {
        return Asistencia::query()->enRango($desde, $hasta);
    }
}
