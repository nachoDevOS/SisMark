<?php

namespace App\Models;

use App\Traits\RegistersUserEvents;
use Database\Factories\TurnoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Turno: la jornada semanal de una persona, armada con los horarios de cada día.
 *
 * `horarios` guarda un día por fila, así que lunes a viernes son cinco horarios;
 * el turno los junta bajo un nombre y es lo que se asigna.
 *
 * **No se edita.** Solo se crea y se elimina: la asistencia se calcula en vivo,
 * y cambiarle los horarios a un turno ya asignado reescribiría los reportes
 * pasados de todos los que lo tuvieron. Para cambiar la jornada se crea otro
 * turno. Lo único que se cambia después es la marca de sugerido, que no toca
 * ningún cálculo.
 */
class Turno extends Model
{
    /** @use HasFactory<TurnoFactory> */
    use HasFactory, RegistersUserEvents, SoftDeletes;

    protected $table = 'turnos';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'nombre',
        'sugerido',
        'observacion',
        'estado',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sugerido' => 'boolean',
        ];
    }

    /**
     * Los horarios del turno, en orden de día de la semana y hora de entrada.
     *
     * Incluye los horarios eliminados: el turno no se edita, así que si uno de
     * sus horarios se da de baja, el turno sigue siendo lo que se creó.
     */
    public function horarios(): BelongsToMany
    {
        return $this->belongsToMany(Horario::class, 'horario_turno')
            ->withTrashed()
            ->withTimestamps()
            ->orderBy('dia')
            ->orderBy('hEntrada');
    }

    /**
     * Los turnos que se ofrecen al asignar desde Mamoré.
     */
    public function scopeSugeridos(Builder $query): Builder
    {
        return $query->where('sugerido', true)->orderBy('nombre');
    }

    /**
     * Horas semanales del turno: la suma de las horas trabajadas de sus horarios.
     */
    public function getHorasSemanalesAttribute(): float
    {
        return (float) $this->horarios->sum(fn (Horario $horario): float => (float) $horario->hTrabajadas);
    }

    /**
     * Días que cubre el turno, abreviados y en orden («Lun, Mar, Mié»).
     */
    public function getDiasCubiertosAttribute(): string
    {
        return $this->horarios
            ->pluck('dia')
            ->map(fn (string $dia): int => (int) $dia)
            ->unique()
            ->sort()
            ->map(fn (int $dia): string => mb_substr(Horario::DIAS[$dia] ?? '?', 0, 3))
            ->implode(', ');
    }
}
