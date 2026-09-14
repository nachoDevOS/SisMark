<?php

namespace App\Models;

use App\Traits\RegistersUserEvents;
use Carbon\CarbonInterface;
use Database\Factories\AsignacionTurnoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Asignación de turno en la base local (MySQL), migrada desde «AsignacionTurnos»
 * del SIA: el turno asignado a un funcionario en un rango de fechas.
 *
 * Conexión por defecto, con id propio, timestamps y eliminación lógica. El
 * carnet vive en `ci` (en el SIA era IdPersona). Se conserva `idTurno` (código
 * del SIA) y se agrega la FK `turno_id` → `turnos.id`, resuelta al copiar.
 */
class AsignacionTurno extends Model
{
    /** @use HasFactory<AsignacionTurnoFactory> */
    use HasFactory, RegistersUserEvents, SoftDeletes;

    protected $table = 'asignacion_turnos';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'ci',
        'idTurno',
        'turno_id',
        'desde',
        'hasta',
        'contrato_id',
        'observacion',
        'estado',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'desde' => 'datetime',
            'hasta' => 'datetime',
            'contrato_id' => 'integer',
        ];
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'ci', 'ci');
    }

    /**
     * El turno asignado. Se relaciona por `turno_id` (la FK real): `idTurno` es
     * solo el código histórico que traía el SIA y no sirve para vincular.
     */
    public function turno(): BelongsTo
    {
        return $this->belongsTo(Turno::class, 'turno_id');
    }

    /**
     * Filtra por CI, por nombre del funcionario o por nombre del turno. Cada
     * palabra del texto debe aparecer en alguno de los tres.
     *
     * Las dos tablas chicas se resuelven **antes** y entran como una lista, en
     * vez de ir como `whereHas`. Un `whereHas` es un `EXISTS` correlacionado:
     * MySQL lo evalúa una vez por cada una de las 420.721 filas de esta tabla,
     * dos veces por término. Medido, el conteo de la búsqueda tardaba 3,1 s y
     * traer la página otros 3,8 s.
     *
     * Preguntarle primero a `personas` (5.469 filas) y a `turnos` (757) qué
     * cruza el término, y después filtrar por esas listas, da exactamente el
     * mismo resultado en 150 ms.
     */
    public function scopeBuscar(Builder $query, string $texto): Builder
    {
        foreach (Persona::terminos($texto) as $termino) {
            $cis = Persona::query()
                ->where(fn (Builder $nombre) => $nombre->coincideNombre($termino))
                ->pluck('ci');

            $turnos = Turno::query()
                ->where('nombreTurno', 'like', "%{$termino}%")
                ->pluck('id');

            $query->where(fn (Builder $sub) => $sub
                ->where('ci', 'like', "%{$termino}%")
                ->orWhereIn('ci', $cis)
                ->orWhereIn('turno_id', $turnos));
        }

        return $query;
    }

    /**
     * Asignaciones que cubren la fecha dada (`desde` ≤ fecha ≤ `hasta`).
     *
     * Sin `whereDate()`: envolver la columna en `DATE()` anula el índice
     * `(hasta, desde)` y obliga a recorrer la tabla —medido, 109 ms contra 14—.
     * Las dos columnas son `datetime`, así que el día se acota por rango: desde
     * el arranque del día pedido hasta el arranque del siguiente.
     */
    public function scopeVigenteEn(Builder $query, CarbonInterface $fecha): Builder
    {
        $dia = $fecha->copy()->startOfDay();

        return $query
            ->where('desde', '<', $dia->copy()->addDay())
            ->where('hasta', '>=', $dia);
    }

    /**
     * Las asignaciones que nacieron de un contrato de Mamoré.
     *
     * Son varias y no una: un horario semanal es un turno por cada día que se
     * trabaja, así que el contrato de lunes a viernes deja cinco filas con el
     * mismo `contrato_id`. Se mueven todas juntas cuando la vigencia del
     * contrato cambia.
     */
    public function scopeDelContrato(Builder $query, int $contratoId): Builder
    {
        return $query->where('contrato_id', $contratoId);
    }

    /**
     * Los períodos de vigencia distintos que tiene asignado un funcionario, en
     * el mismo orden que `delFuncionario`.
     *
     * El SIA reasigna el turno día por día: un mismo período trae una fila por
     * cada día de la semana que se trabaja, con las mismas fechas y el mismo
     * horario repetidos cinco veces. Paginar esas filas parte la tanda al
     * medio y multiplica por cinco las páginas; paginar los períodos deja cada
     * tanda entera en una sola página y el listado se lee por vigencia, que es
     * como Recursos Humanos lo consulta.
     *
     * Va con `groupBy` y no con `distinct` porque el orden usa una expresión
     * `CASE`: con `DISTINCT`, MySQL exige que toda expresión del `ORDER BY`
     * esté también en el `SELECT`, y agrupando alcanza con que la columna sea
     * una de las agrupadas.
     */
    public function scopePeriodosDelFuncionario(Builder $query, string $ci, bool $incluirVencidas = false): Builder
    {
        $hoy = today();

        return $query
            ->select('asignacion_turnos.desde', 'asignacion_turnos.hasta')
            ->join('turnos', 'turnos.id', '=', 'asignacion_turnos.turno_id')
            ->whereNull('turnos.deleted_at')
            ->where('asignacion_turnos.ci', $ci)
            ->unless($incluirVencidas, fn (Builder $sub) => $sub->where('asignacion_turnos.hasta', '>=', $hoy))
            ->groupBy('asignacion_turnos.desde', 'asignacion_turnos.hasta')
            ->orderByRaw('CASE WHEN asignacion_turnos.hasta >= ? THEN 0 ELSE 1 END', [$hoy])
            ->orderByDesc('asignacion_turnos.desde')
            ->orderByDesc('asignacion_turnos.hasta');
    }

    /**
     * Turnos asignados a un funcionario: los que siguen en pie primero y,
     * dentro de cada grupo, por vigencia de la más reciente a la más vieja.
     * El día de la semana y la hora de entrada solo desempatan, porque un
     * mismo período suele traer una asignación por cada día que se trabaja y
     * es esa tanda —no el día suelto— la que el usuario lee junta. Con
     * `$incluirVencidas` se suman al final las que ya terminaron.
     *
     * El orden se resuelve en SQL con un join a `turnos` a propósito: ordenar
     * en memoria con `sortBy([...])` no sirve, porque ahí un closure se toma
     * como comparador de dos argumentos, no como extractor del valor a ordenar.
     */
    public function scopeDelFuncionario(Builder $query, string $ci, bool $incluirVencidas = false): Builder
    {
        $hoy = today();

        return $query
            ->select('asignacion_turnos.*')
            ->with('turno')
            ->join('turnos', 'turnos.id', '=', 'asignacion_turnos.turno_id')
            // El join saltea el borrado lógico de turnos: hay que excluirlos a mano.
            ->whereNull('turnos.deleted_at')
            ->where('asignacion_turnos.ci', $ci)
            ->unless($incluirVencidas, fn (Builder $sub) => $sub->where('asignacion_turnos.hasta', '>=', $hoy))
            ->orderByRaw('CASE WHEN asignacion_turnos.hasta >= ? THEN 0 ELSE 1 END', [$hoy])
            ->orderByDesc('asignacion_turnos.desde')
            ->orderByDesc('asignacion_turnos.hasta')
            ->orderBy('turnos.dia')
            ->orderBy('turnos.hEntrada');
    }

    /**
     * Situación de la asignación respecto de hoy: `vigente`, `vencida` (ya
     * terminó) o `futura` (todavía no empieza).
     */
    /**
     * Clave del período de vigencia, para juntar en un bloque las asignaciones
     * que comparten fechas. Sirve igual sobre las filas de
     * `periodosDelFuncionario`, que traen solo esas dos columnas.
     */
    public function getClavePeriodoAttribute(): string
    {
        return ($this->desde?->format('Y-m-d') ?? '').'|'.($this->hasta?->format('Y-m-d') ?? '');
    }

    public function getSituacionAttribute(): string
    {
        if ($this->hasta?->isBefore(today())) {
            return 'vencida';
        }

        return $this->desde?->isAfter(today()) ? 'futura' : 'vigente';
    }
}
