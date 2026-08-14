<?php

namespace App\Models;

use App\Traits\RegistersUserEvents;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Día excepcional (feriado, tolerancia, motivo de inasistencia) en la base
 * local (MySQL), migrado desde «Calendario» del SIA.
 *
 * Conexión por defecto, con id propio, timestamps y eliminación lógica. Una
 * fila por fecha.
 */
class DiaExcepcional extends Model
{
    use HasFactory, RegistersUserEvents, SoftDeletes;

    protected $table = 'dias_excepcionales';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'fecha',
        'motivoInasistencia',
        // Respaldo que justifica el día: el decreto, la resolución o el
        // memorándum. La ruta dentro del disco `s3` y el nombre con el que lo
        // subieron, que es el que se le muestra a quien lo descarga.
        'adjunto',
        'adjuntoNombre',
        'observacion',
        'estado',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha' => 'datetime',
        ];
    }
}
