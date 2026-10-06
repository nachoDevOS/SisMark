<?php

namespace App\Models;

use App\Services\AsignadorTurnos;
use App\Traits\RegistersUserEvents;
use Carbon\CarbonInterface;
use Database\Factories\AsignacionTurnoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Turno asignado a un funcionario en un rango de fechas.
 *
 * Es la cabecera de la asignación. Sus horarios, uno por día, quedan como
 * detalle en `asignacion_horarios` con `asignacion_turno_id`: así el
 * procesador, las licencias y los reportes —que leen esa tabla— siguen igual.
 * Cabecera y detalle se mueven siempre juntos, por {@see AsignadorTurnos}.
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
            'desde' => 'date',
            'hasta' => 'date',
            'contrato_id' => 'integer',
        ];
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'ci', 'ci');
    }

    /**
     * El turno asignado, aunque después se haya eliminado: la asignación sigue
     * explicando qué jornada tenía la persona en ese período.
     */
    public function turno(): BelongsTo
    {
        return $this->belongsTo(Turno::class)->withTrashed();
    }

    /**
     * El detalle: un horario asignado por cada horario del turno.
     */
    public function horariosAsignados(): HasMany
    {
        return $this->hasMany(AsignacionHorario::class, 'asignacion_turno_id');
    }

    /**
     * Filtra por CI, por nombre del funcionario o por nombre del turno. Cada
     * palabra del texto debe aparecer en alguno de los tres. Las listas de
     * personas y turnos se resuelven antes, como en {@see AsignacionHorario::scopeBuscar()}.
     */
    public function scopeBuscar(Builder $query, string $texto): Builder
    {
        foreach (Persona::terminos($texto) as $termino) {
            $cis = Persona::query()
                ->where(fn (Builder $nombre) => $nombre->coincideNombre($termino))
                ->pluck('ci');

            $turnos = Turno::withTrashed()
                ->where('nombre', 'like', "%{$termino}%")
                ->pluck('id');

            $query->where(fn (Builder $sub) => $sub
                ->where('ci', 'like', "%{$termino}%")
                ->orWhereIn('ci', $cis)
                ->orWhereIn('turno_id', $turnos));
        }

        return $query;
    }

    /**
     * Asignaciones que cubren la fecha dada (`desde` ≤ fecha ≤ `hasta`), por
     * rango y sin `DATE()` para no anular el índice `(hasta, desde)`.
     */
    public function scopeVigenteEn(Builder $query, CarbonInterface $fecha): Builder
    {
        $dia = $fecha->copy()->startOfDay();

        return $query
            ->where('desde', '<', $dia->copy()->addDay())
            ->where('hasta', '>=', $dia);
    }

    /**
     * Asignaciones que se cruzan con el rango `[desde, hasta]`.
     */
    public function scopeSolapadas(Builder $query, CarbonInterface $desde, CarbonInterface $hasta): Builder
    {
        return $query
            ->where('desde', '<=', $hasta->copy()->startOfDay())
            ->where('hasta', '>=', $desde->copy()->startOfDay());
    }

    /**
     * Las asignaciones que nacieron de un contrato de Mamoré.
     */
    public function scopeDelContrato(Builder $query, int $contratoId): Builder
    {
        return $query->where('contrato_id', $contratoId);
    }

    /**
     * Situación de la asignación respecto de hoy: `vigente`, `vencida` (ya
     * terminó) o `futura` (todavía no empieza).
     */
    public function getSituacionAttribute(): string
    {
        if ($this->hasta?->isBefore(today())) {
            return 'vencida';
        }

        return $this->desde?->isAfter(today()) ? 'futura' : 'vigente';
    }
}
