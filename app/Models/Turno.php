<?php

namespace App\Models;

use App\Http\Resources\TurnoSugeridoResource;
use App\Traits\RegistersUserEvents;
use Database\Factories\TurnoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Horario (turno) en la base local (MySQL), migrado desde «DiaTurnos» del SIA.
 *
 * Conexión por defecto, con id propio, timestamps y eliminación lógica. Las
 * horas van como datetime sobre la fecha base 1899-12-30 (solo importa la hora),
 * como el SIA real.
 */
class Turno extends Model
{
    /** @use HasFactory<TurnoFactory> */
    use HasFactory, RegistersUserEvents, SoftDeletes;

    /**
     * Días de la semana según el número que guarda la columna `dia`
     * (1 = Domingo … 7 = Sábado, igual que DATEPART(dw) por defecto en el SIA).
     *
     * @var array<int, string>
     */
    public const DIAS = [
        1 => 'Domingo',
        2 => 'Lunes',
        3 => 'Martes',
        4 => 'Miércoles',
        5 => 'Jueves',
        6 => 'Viernes',
        7 => 'Sábado',
    ];

    protected $table = 'turnos';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'idTurno',
        'dia',
        'nombreTurno',
        'hEntrada',
        'hSalida',
        'hTolerancia',
        'eMinima',
        'eMaxima',
        'sMinima',
        'sMaxima',
        'sTolerancia',
        'hTrabajadas',
        'siguienteDia',
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
            'hEntrada' => 'datetime',
            'hSalida' => 'datetime',
            'hTolerancia' => 'datetime',
            'eMinima' => 'datetime',
            'eMaxima' => 'datetime',
            'sMinima' => 'datetime',
            'sMaxima' => 'datetime',
            'sTolerancia' => 'datetime',
            'hTrabajadas' => 'decimal:2',
            'siguienteDia' => 'boolean',
            'sugerido' => 'boolean',
        ];
    }

    /**
     * Nombre legible del día de la semana del turno.
     */
    public function getNombreDiaAttribute(): string
    {
        return self::DIAS[(int) $this->dia] ?? '—';
    }

    /**
     * Ordena por día de la semana y luego por nombre del turno.
     */
    public function scopeOrdenado(Builder $query): Builder
    {
        return $query->orderBy('dia')->orderBy('nombreTurno');
    }

    /**
     * Los turnos que forman el horario sugerido de la institución, en orden de
     * día de la semana.
     *
     * Son varias filas y no una: `turnos` guarda **un día por fila**, así que
     * un horario semanal de lunes a viernes son cinco turnos distintos. Quien
     * los consuma tiene que tratarlos como un juego, no como cinco opciones
     * ({@see TurnoSugeridoResource}).
     */
    public function scopeSugeridos(Builder $query): Builder
    {
        return $query->where('sugerido', true)->orderBy('dia')->orderBy('hEntrada');
    }

    /**
     * Clave que junta en un mismo horario semanal a los turnos que solo se
     * diferencian por el día.
     *
     * Se arma con los campos que definen la jornada y **no** con `nombreTurno`,
     * que trae el día pegado adelante («LUN: 08:00 - 16:00») y además se repite
     * entre turnos distintos: hay nombres con once filas sobre cinco días, así
     * que agrupar por texto mezclaría horarios que no son el mismo.
     */
    public function getClaveHorarioAttribute(): string
    {
        return implode('|', [
            $this->hEntrada?->format('H:i:s'),
            $this->hSalida?->format('H:i:s'),
            $this->hTolerancia?->format('H:i:s'),
            $this->eMinima?->format('H:i:s'),
            $this->eMaxima?->format('H:i:s'),
            $this->sMinima?->format('H:i:s'),
            $this->sMaxima?->format('H:i:s'),
            $this->sTolerancia?->format('H:i:s'),
            $this->hTrabajadas,
            $this->siguienteDia ? '1' : '0',
        ]);
    }
}
