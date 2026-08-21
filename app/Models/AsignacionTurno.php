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
     */
    public function scopeBuscar(Builder $query, string $texto): Builder
    {
        foreach (Persona::terminos($texto) as $termino) {
            $query->where(fn (Builder $sub) => $sub
                ->where('ci', 'like', "%{$termino}%")
                ->orWhereHas('persona', fn (Builder $persona) => $persona
                    ->where(fn (Builder $nombre) => $nombre->coincideNombre($termino)))
                ->orWhereHas('turno', fn (Builder $turno) => $turno
                    ->where('nombreTurno', 'like', "%{$termino}%")));
        }

        return $query;
    }

    /**
     * Asignaciones que cubren la fecha dada (`desde` ≤ fecha ≤ `hasta`).
     */
    public function scopeVigenteEn(Builder $query, CarbonInterface $fecha): Builder
    {
        return $query->whereDate('desde', '<=', $fecha)->whereDate('hasta', '>=', $fecha);
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
