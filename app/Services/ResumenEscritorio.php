<?php

namespace App\Services;

use App\Models\AsignacionHorario;
use App\Models\Asistencia;
use App\Models\DiaExcepcional;
use App\Models\Equipo;
use App\Models\Licencia;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Arma los datos del escritorio desde la base local MySQL.
 *
 * Vive aparte del controlador porque son consultas con sus mañas: la tabla
 * `asistencias` tiene 4,4 millones de filas y cada consulta tiene que caer
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
     * Minutos que se cachean los números del día. El escritorio se mira varias
     * veces por hora y las marcaciones entran de a poco.
     */
    private const CACHE_MINUTOS = 5;

    /**
     * Los cuatro números del día: quiénes tenían que marcar, cuántos de ellos
     * marcaron, cuántos faltan y cuántos están de licencia.
     *
     * «Sin marcar» descuenta a los que tienen licencia aprobada hoy: no se los
     * espera, y contarlos haría parecer ausente a quien tiene permiso.
     *
     * `con_horario` sale de los horarios asignados. Mientras esa tabla esté vacía
     * viaja en `null`, junto con `marcaron` y `sin_marcar`: «0 sin marcar»
     * parecería un dato y es una tabla sin cargar. `personas` cuenta a todos
     * los que marcaron, tengan horario o no, y es lo que se muestra en ese caso.
     *
     * @return array{personas: int, con_horario: ?int, marcaron: ?int, sin_marcar: ?int, licenciados: int}
     */
    public function hoy(): array
    {
        return Cache::remember('escritorio.numeros-del-dia', now()->addMinutes(self::CACHE_MINUTOS), function (): array {
            $hoy = today();

            $cisConHorario = $this->cisConHorarioEse($hoy);
            $cisQueMarcaron = $this->enRango($hoy, $hoy)->distinct()->pluck('ci')
                ->map(fn ($ci): string => trim((string) $ci))
                ->all();
            $cisDeLicencia = Licencia::query()
                ->where('estado', 'Aprobado')
                ->where('fecha', '>=', $hoy->toDateString())
                ->where('fecha', '<', $hoy->copy()->addDay()->toDateString())
                ->distinct()
                ->pluck('ci')
                ->map(fn ($ci): string => trim((string) $ci))
                ->all();

            return [
                'personas' => count($cisQueMarcaron),
                'con_horario' => $cisConHorario === null ? null : count($cisConHorario),
                'marcaron' => $cisConHorario === null ? null : count(array_intersect($cisConHorario, $cisQueMarcaron)),
                'sin_marcar' => $cisConHorario === null
                    ? null
                    : count(array_diff($cisConHorario, $cisQueMarcaron, $cisDeLicencia)),
                'licenciados' => count($cisDeLicencia),
            ];
        });
    }

    /**
     * El próximo día excepcional de la semana que viene, para avisarlo antes
     * de que llegue. Más lejos no hace falta tenerlo en la portada.
     */
    public function proximoExcepcional(): ?DiaExcepcional
    {
        return DiaExcepcional::query()
            ->whereNotNull('motivoInasistencia')
            ->where('fecha', '>=', today()->toDateString())
            ->where('fecha', '<', today()->addDays(8)->toDateString())
            ->orderBy('fecha')
            ->first();
    }

    /**
     * Equipos activos que están fuera de línea, para el aviso de arriba.
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
            ->where('fecha', '<', $manana->toDateString())
            ->orderByDesc('fecha')
            ->value('fecha');

        if ($fecha === null) {
            return null;
        }

        $dia = Carbon::parse($fecha)->startOfDay();

        $hora = Asistencia::query()
            ->where('fecha', '>=', $dia->toDateString())
            ->where('fecha', '<', $dia->copy()->addDay()->toDateString())
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
     * Carnets con horario asignado ese día. `null` si todavía no se migraron las
     * asignaciones: no es lo mismo «nadie tiene horario» que «no hay datos».
     *
     * @return list<string>|null
     */
    private function cisConHorarioEse(Carbon $fecha): ?array
    {
        if (AsignacionHorario::query()->doesntExist()) {
            return null;
        }

        // `horarios.dia` guarda 1=Domingo … 7=Sábado, igual que DAYOFWEEK() de
        // MySQL y que ProcesadorAsistencia.
        $diaSemana = $fecha->dayOfWeek + 1;

        return AsignacionHorario::query()
            ->join('horarios', 'horarios.id', '=', 'asignacion_horarios.horario_id')
            ->whereNull('horarios.deleted_at')
            ->where('horarios.dia', $diaSemana)
            ->where('asignacion_horarios.desde', '<=', $fecha->copy()->endOfDay())
            ->where('asignacion_horarios.hasta', '>=', $fecha->copy()->startOfDay())
            ->distinct()
            ->pluck('asignacion_horarios.ci')
            ->map(fn ($ci): string => trim((string) $ci))
            ->all();
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
