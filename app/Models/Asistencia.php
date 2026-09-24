<?php

namespace App\Models;

use App\Traits\ManejaHorasDelDia;
use App\Traits\RegistersUserEvents;
use Database\Factories\AsistenciaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Marcación en la base local (MySQL), migrada desde «Asistencia» del SIA.
 *
 * Usa la conexión por defecto (MySQL), con id propio y timestamps. A diferencia
 * del resto de los modelos NO usa SoftDeletes: las marcaciones no se dan de baja
 * desde el sistema y el `deleted_at IS NULL` costaba caro sobre 4,4 millones de
 * filas. El carnet vive en la columna `ci` (en el SIA era IdPersona). `hora`
 * guarda solo la hora y `fecha` solo el día.
 */
class Asistencia extends Model
{
    /** @use HasFactory<AsistenciaFactory> */
    use HasFactory, ManejaHorasDelDia, RegistersUserEvents;

    public const TIPO_RELOJ = 'R';

    public const TIPO_MANUAL = 'M';

    public const TIPO_A = 'A';

    /**
     * Etiqueta legible de cada tipo, para los filtros y las tablas. La letra es
     * lo que guarda la columna `tipo`; sola no le dice nada a nadie.
     *
     * El origen de `A` (1,8 millones de filas migradas del SIA) no está
     * documentado en el sistema viejo, así que se rotula por lo que se sabe y no
     * por una suposición. Ver docs/REPORTE-PROCESADO-ASISTENCIA.md.
     *
     * @var array<string, string>
     */
    public const TIPOS = [
        self::TIPO_RELOJ => 'Reloj',
        self::TIPO_A => 'Sin identificar',
        self::TIPO_MANUAL => 'Manual',
    ];

    protected $table = 'asistencias';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'ci',
        'fecha',
        'hora',
        'tipo',
        // De qué reloj salió. Null en lo migrado del SIA, en el alta manual y en
        // el CSV, que no dice de qué equipo se exportó.
        'equipo_id',
        // Qué sincronización o importación de CSV la insertó. Null en lo
        // migrado del SIA y en el alta manual.
        'equipo_auditoria_id',
        'observacion',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // `fecha` tampoco: la maneja {@see ManejaHorasDelDia}.
            // `hora` no va acá: la maneja {@see ManejaHorasDelDia}, porque el
            // cast `datetime` de Eloquent escribiría un datetime entero en una
            // columna `time`.
        ];
    }

    /**
     * Hora de la marcación: columna `time`, se lee como Carbon y se escribe H:i:s.
     */
    /**
     * Día de la marcación: columna `date`, sin hora.
     */
    protected function fecha(): Attribute
    {
        return self::soloFecha();
    }

    protected function hora(): Attribute
    {
        return self::horaDelDia();
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'ci', 'ci');
    }

    /**
     * Reloj del que salió la marcación.
     *
     * Null cuando no vino de ninguno, o cuando el equipo se borró físicamente
     * de la base (la FK es `nullOnDelete`: la marcación es del funcionario y no
     * se va con el aparato).
     *
     * @return BelongsTo<Equipo, $this>
     */
    public function equipo(): BelongsTo
    {
        return $this->belongsTo(Equipo::class);
    }

    /**
     * Entrada de la bitácora de la sincronización que insertó la marcación.
     *
     * Null en lo migrado del SIA, en el alta manual y en el CSV. Una marcación
     * que después vuelve a llegar como repetida conserva la de la primera
     * corrida: esa es la que la trajo.
     *
     * @return BelongsTo<EquipoAuditoria, $this>
     */
    public function sincronizacion(): BelongsTo
    {
        return $this->belongsTo(EquipoAuditoria::class, 'equipo_auditoria_id');
    }

    /**
     * Acota `fecha` a un rango de días, con los dos extremos incluidos. Cada
     * extremo es opcional: vacío o nulo no filtra por ese lado.
     *
     * Va por `>= desde` y `< hasta + 1 día`, nunca por `whereDate()`. Envolver la
     * columna en `DATE()` anula el índice `(fecha, ci)` y MySQL recorre los 4,4
     * millones de filas: medido sobre una página del listado de marcaciones
     * (rango de 4 días), 8.652 ms contra 15 ms, y el `count(*)` que arma la
     * paginación, 5.871 ms contra 0,5 ms.
     *
     * El corte de arriba va por el día siguiente y no por `hasta 23:59:59`: hoy
     * `fecha` guarda el día a medianoche, pero si alguna fila trajera hora, con
     * el día siguiente sigue entrando.
     */
    public function scopeEnRango(Builder $query, Carbon|string|null $desde, Carbon|string|null $hasta): Builder
    {
        return $query
            ->when(
                $desde !== null && $desde !== '',
                // Se comparan cadenas `Y-m-d` y no objetos Carbon: la columna es
                // `date`, y un Carbon se enlaza como «2026-07-06 00:00:00», que
                // contra «2026-07-06» no cruza.
                fn (Builder $sub) => $sub->where('fecha', '>=', Carbon::parse($desde)->toDateString())
            )
            ->when(
                $hasta !== null && $hasta !== '',
                fn (Builder $sub) => $sub->where('fecha', '<', Carbon::parse($hasta)->startOfDay()->addDay()->toDateString())
            );
    }

    /**
     * Filtra por CI de la marcación o por nombre del funcionario. Cada palabra
     * del texto debe aparecer en el CI o en algún nombre, así "ignacio molina"
     * cruza nombres + paterno aunque estén en columnas distintas.
     */
    public function scopeBuscar(Builder $query, string $texto): Builder
    {
        foreach (Persona::terminos($texto) as $termino) {
            $query->where(fn (Builder $sub) => $sub
                ->where('ci', 'like', "%{$termino}%")
                ->orWhereHas('persona', fn (Builder $persona) => $persona
                    ->where(fn (Builder $nombre) => $nombre->coincideNombre($termino))));
        }

        return $query;
    }
}
