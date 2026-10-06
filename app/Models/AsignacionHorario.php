<?php

namespace App\Models;

use App\Services\AsignadorTurnos;
use App\Traits\RegistersUserEvents;
use Carbon\CarbonInterface;
use Database\Factories\AsignacionHorarioFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Asignación de horario en la base local (MySQL), migrada desde «AsignacionTurnos»
 * del SIA: el horario asignado a un funcionario en un rango de fechas.
 *
 * Conexión por defecto, con id propio, timestamps y eliminación lógica. El
 * carnet vive en `ci` (en el SIA era IdPersona). Se conserva `idHorario` (código
 * del SIA) y se agrega la FK `horario_id` → `horarios.id`, resuelta al copiar.
 *
 * Ya no se asignan horarios sueltos: lo nuevo es el detalle de un turno
 * asignado (`asignacion_turno_id`) y lo crea {@see AsignadorTurnos}. Lo del SIA
 * queda con esa columna en null, como historia.
 */
class AsignacionHorario extends Model
{
    /** @use HasFactory<AsignacionHorarioFactory> */
    use HasFactory, RegistersUserEvents, SoftDeletes;

    protected $table = 'asignacion_horarios';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'ci',
        'idHorario',
        'horario_id',
        'asignacion_turno_id',
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
            'desde' => 'date',
            'hasta' => 'date',
            'contrato_id' => 'integer',
            'asignacion_turno_id' => 'integer',
        ];
    }

    /**
     * El turno asignado del que esta fila es detalle; null en lo heredado del SIA.
     */
    public function asignacionTurno(): BelongsTo
    {
        return $this->belongsTo(AsignacionTurno::class, 'asignacion_turno_id')->withTrashed();
    }

    /**
     * Si la fila es detalle de un turno: se concluye y se elimina desde el turno.
     */
    public function getEsDeTurnoAttribute(): bool
    {
        return $this->asignacion_turno_id !== null;
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'ci', 'ci');
    }

    /**
     * El horario asignado. Se relaciona por `horario_id` (la FK real): `idHorario` es
     * solo el código histórico que traía el SIA y no sirve para vincular.
     */
    public function horario(): BelongsTo
    {
        return $this->belongsTo(Horario::class, 'horario_id');
    }

    /**
     * Filtra por CI, por nombre del funcionario o por nombre del horario. Cada
     * palabra del texto debe aparecer en alguno de los tres.
     *
     * Las dos tablas chicas se resuelven **antes** y entran como una lista, en
     * vez de ir como `whereHas`. Un `whereHas` es un `EXISTS` correlacionado:
     * MySQL lo evalúa una vez por cada una de las 420.721 filas de esta tabla,
     * dos veces por término. Medido, el conteo de la búsqueda tardaba 3,1 s y
     * traer la página otros 3,8 s.
     *
     * Preguntarle primero a `personas` (5.469 filas) y a `horarios` (757) qué
     * cruza el término, y después filtrar por esas listas, da exactamente el
     * mismo resultado en 150 ms.
     */
    public function scopeBuscar(Builder $query, string $texto): Builder
    {
        foreach (Persona::terminos($texto) as $termino) {
            $cis = Persona::query()
                ->where(fn (Builder $nombre) => $nombre->coincideNombre($termino))
                ->pluck('ci');

            $horarios = Horario::query()
                ->where('nombreHorario', 'like', "%{$termino}%")
                ->pluck('id');

            $query->where(fn (Builder $sub) => $sub
                ->where('ci', 'like', "%{$termino}%")
                ->orWhereIn('ci', $cis)
                ->orWhereIn('horario_id', $horarios));
        }

        return $query;
    }

    /**
     * Asignaciones que cubren la fecha dada (`desde` ≤ fecha ≤ `hasta`).
     *
     * Sin `whereDate()`: envolver la columna en `DATE()` anula el índice
     * `(hasta, desde)` y obliga a recorrer la tabla —medido, 109 ms contra 14—.
     * El día se acota por rango, igual sobre columnas `date`: desde
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
     * Son varias y no una: un horario semanal es un horario por cada día que se
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
     * El SIA reasigna el horario día por día: un mismo período trae una fila por
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
            ->select('asignacion_horarios.desde', 'asignacion_horarios.hasta')
            ->join('horarios', 'horarios.id', '=', 'asignacion_horarios.horario_id')
            ->whereNull('horarios.deleted_at')
            ->where('asignacion_horarios.ci', $ci)
            ->unless($incluirVencidas, fn (Builder $sub) => $sub->where('asignacion_horarios.hasta', '>=', $hoy))
            ->groupBy('asignacion_horarios.desde', 'asignacion_horarios.hasta')
            ->orderByRaw('CASE WHEN asignacion_horarios.hasta >= ? THEN 0 ELSE 1 END', [$hoy])
            ->orderByDesc('asignacion_horarios.desde')
            ->orderByDesc('asignacion_horarios.hasta');
    }

    /**
     * Horarios asignados a un funcionario: los que siguen en pie primero y,
     * dentro de cada grupo, por vigencia de la más reciente a la más vieja.
     * El día de la semana y la hora de entrada solo desempatan, porque un
     * mismo período suele traer una asignación por cada día que se trabaja y
     * es esa tanda —no el día suelto— la que el usuario lee junta. Con
     * `$incluirVencidas` se suman al final las que ya terminaron.
     *
     * El orden se resuelve en SQL con un join a `horarios` a propósito: ordenar
     * en memoria con `sortBy([...])` no sirve, porque ahí un closure se toma
     * como comparador de dos argumentos, no como extractor del valor a ordenar.
     */
    public function scopeDelFuncionario(Builder $query, string $ci, bool $incluirVencidas = false): Builder
    {
        $hoy = today();

        return $query
            ->select('asignacion_horarios.*')
            ->with('horario')
            ->join('horarios', 'horarios.id', '=', 'asignacion_horarios.horario_id')
            // El join saltea el borrado lógico de horarios: hay que excluirlos a mano.
            ->whereNull('horarios.deleted_at')
            ->where('asignacion_horarios.ci', $ci)
            ->unless($incluirVencidas, fn (Builder $sub) => $sub->where('asignacion_horarios.hasta', '>=', $hoy))
            ->orderByRaw('CASE WHEN asignacion_horarios.hasta >= ? THEN 0 ELSE 1 END', [$hoy])
            ->orderByDesc('asignacion_horarios.desde')
            ->orderByDesc('asignacion_horarios.hasta')
            ->orderBy('horarios.dia')
            ->orderBy('horarios.hEntrada');
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
