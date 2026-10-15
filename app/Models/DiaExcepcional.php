<?php

namespace App\Models;

use App\Traits\ManejaHorasDelDia;
use App\Traits\RegistersUserEvents;
use Illuminate\Database\Eloquent\Builder;
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
 *
 * El SIA trajo una fila por cada día del calendario: solo las que tienen
 * `motivoInasistencia` son días excepcionales. De esas, solo las activas
 * cuentan en los reportes; dar de baja un día lo saca del control sin
 * eliminarlo.
 */
class DiaExcepcional extends Model
{
    use HasFactory, ManejaHorasDelDia, RegistersUserEvents, SoftDeletes;

    public const ACTIVO = 1;

    public const INACTIVO = 0;

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

    /**
     * ¿Cuenta en los reportes?
     */
    protected function activo(): Attribute
    {
        return Attribute::get(fn (): bool => (int) $this->estado === self::ACTIVO);
    }

    /**
     * Los días que sí son excepcionales: con motivo cargado.
     *
     * @param  Builder<DiaExcepcional>  $query
     */
    public function scopeConMotivo(Builder $query): Builder
    {
        return $query->whereNotNull('motivoInasistencia');
    }

    /**
     * Los que cuentan en los reportes: con motivo y activos.
     *
     * @param  Builder<DiaExcepcional>  $query
     */
    public function scopeVigentes(Builder $query): Builder
    {
        return $query->conMotivo()->where('estado', self::ACTIVO);
    }
}
