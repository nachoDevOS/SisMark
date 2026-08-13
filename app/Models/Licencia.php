<?php

namespace App\Models;

use App\Traits\RegistersUserEvents;
use Database\Factories\LicenciaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Licencia/permiso en la base local (MySQL), migrada desde «Licencias» del SIA.
 *
 * Conexión por defecto (MySQL), con id propio, timestamps y eliminación lógica.
 * El carnet vive en `ci` (en el SIA era IdPersona). `lEntra`/`lSale` guardan la
 * hora sobre la fecha base 1899-12-30, como el SIA real.
 *
 * El horario se referencia siempre por la FK `turno_id`. La columna `idTurno`
 * (código del SIA) sobrevive solo como dato histórico de lo migrado: no se
 * escribe ni se consulta desde el sistema.
 */
class Licencia extends Model
{
    /** @use HasFactory<LicenciaFactory> */
    use HasFactory, RegistersUserEvents, SoftDeletes;

    protected $table = 'licencias';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'fechaPedido',
        'usuario',
        'fecha',
        'ci',
        // `idTurno` queda fuera a propósito: es histórico del SIA, el sistema
        // referencia el horario por la FK `turno_id`.
        'turno_id',
        'lEntra',
        'lSale',
        'tCompleto',
        'motivo',
        'goceHaberes',
        'observacion',
        'estado',
        // Respaldo que justifica la licencia: la ruta dentro del disco `s3` y
        // el nombre con el que lo subieron, que es el que se le muestra a quien
        // lo descarga.
        'adjunto',
        'adjuntoNombre',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fechaPedido' => 'datetime',
            'fecha' => 'date',
            'lEntra' => 'datetime',
            'lSale' => 'datetime',
            'tCompleto' => 'boolean',
            'goceHaberes' => 'boolean',
        ];
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'ci', 'ci');
    }

    public function turno(): BelongsTo
    {
        return $this->belongsTo(Turno::class, 'turno_id');
    }

    /**
     * Filtra por CI, motivo o nombre del funcionario: cada palabra debe cruzar
     * en alguna de las tres, igual que el buscador de funcionarios.
     *
     * El `coincideNombre` va envuelto en un `where` anidado: sus `orWhere` sin
     * agrupar se mezclarían con la correlación del `whereHas` y el subquery
     * daría verdadero para cualquier fila (el filtro no filtraría nada).
     */
    public function scopeBuscar(Builder $query, string $texto): Builder
    {
        foreach (Persona::terminos($texto) as $termino) {
            $query->where(fn (Builder $sub) => $sub
                ->where('ci', 'like', "%{$termino}%")
                ->orWhere('motivo', 'like', "%{$termino}%")
                ->orWhereHas('persona', fn (Builder $persona) => $persona
                    ->where(fn (Builder $nombre) => $nombre->coincideNombre($termino))));
        }

        return $query;
    }

    /**
     * Etiqueta legible del horario licenciado: «MIE: 08:00 – 16:00».
     */
    public function getResumenTurnoAttribute(): string
    {
        $turno = $this->turno;

        if (! $turno instanceof Turno) {
            return '—';
        }

        return trim((string) $turno->nombreTurno).': '
            .($turno->hEntrada?->format('H:i') ?? '—').' – '
            .($turno->hSalida?->format('H:i') ?? '—');
    }
}
