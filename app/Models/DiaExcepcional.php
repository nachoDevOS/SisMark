<?php

namespace App\Models;

use App\Traits\ManejaHorasDelDia;
use App\Traits\RegistersUserEvents;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Día excepcional (feriado, tolerancia, motivo de inasistencia) en la base
 * local (MySQL), migrado desde «Calendario» del SIA.
 *
 * Conexión por defecto, con id propio, timestamps y eliminación lógica. Una
 * fila por fecha, y `fecha` guarda solo el día.
 */
class DiaExcepcional extends Model
{
    use HasFactory, ManejaHorasDelDia, RegistersUserEvents, SoftDeletes;

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
     * Día excepcional: columna `date`, sin hora ({@see ManejaHorasDelDia}).
     */
    protected function fecha(): Attribute
    {
        return self::soloFecha();
    }
}
