<?php

namespace App\Models\Sia;

use Database\Factories\Sia\AsistenciaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marcación de asistencia registrada en el sistema SIA.
 *
 * Tabla legada con clave primaria compuesta (IdPersona, Fecha, Hora), por eso
 * $primaryKey es null. `Fecha` guarda solo la fecha (medianoche) y `Hora` solo
 * la hora (sobre la fecha base 1899-12-30, patrón clásico de SQL Server 2008).
 *
 * **Solo lectura.** El sistema nunca escribe en el SIA: las marcaciones se
 * guardan en la tabla local `asistencias` (MySQL) y el SQL Server es únicamente
 * el origen del que se copia con `sia:migrar-marcaciones`. El servicio que
 * escribía acá se eliminó.
 */
class Asistencia extends Model
{
    /** @use HasFactory<AsistenciaFactory> */
    use HasFactory;

    public const TIPO_RELOJ = 'R';

    public const TIPO_MANUAL = 'M';

    public const TIPO_A = 'A';

    protected $connection = 'sia';

    protected $table = 'Asistencia';

    protected $primaryKey = null;

    public $incrementing = false;

    public $timestamps = false;

    /**
     * Formato ISO 8601 con "T": el único que SQL Server 2008 interpreta igual
     * con cualquier SET LANGUAGE. Con "Y-m-d H:i:s" y el login en español, el
     * servidor lee año-día-mes y revienta con fechas válidas (ej. 2026-07-16
     * lo toma como día 07 mes 16). Mismo motivo que en Persona::$dateFormat.
     */
    protected $dateFormat = 'Y-m-d\TH:i:s';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'IdPersona',
        'Fecha',
        'Hora',
        'Tipo',
    ];

    /**
     * Casts de atributos.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Fecha' => 'datetime',
            'Hora' => 'datetime',
        ];
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'IdPersona', 'IdPersona');
    }

    /**
     * Filtra por CI o por nombre del funcionario. Cada palabra del texto debe
     * aparecer en el CI de la marcación o en algún nombre del funcionario, así
     * "ignacio molina" cruza Nombres + Paterno aunque estén en columnas
     * distintas (antes una búsqueda de dos palabras no devolvía nada).
     */
    public function scopeBuscar(Builder $query, string $texto): Builder
    {
        foreach (Persona::terminos($texto) as $termino) {
            $query->where(fn (Builder $sub) => $sub
                ->where('IdPersona', 'like', "%{$termino}%")
                ->orWhereHas('persona', fn (Builder $persona) => $persona
                    ->where(fn (Builder $nombre) => $nombre->coincideNombre($termino))));
        }

        return $query;
    }
}
