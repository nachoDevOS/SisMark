<?php

namespace App\Models;

use App\Http\Requests\GuardarPlazoLicenciaRequest;
use App\Services\PlazoLicencias;
use App\Traits\ManejaHorasDelDia;
use App\Traits\RegistersUserEvents;
use Carbon\CarbonInterface;
use Database\Factories\ConfiguracionPlazoLicenciaFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Plazo para registrar licencias: hasta cuántos días **atrás** de hoy y hasta
 * cuántos **adelante** se puede pedir una. Rige para las dos puertas por las
 * que entra una licencia —la solicitud del funcionario desde Mamoré y el alta
 * de Recursos Humanos en SisMark—; lo controla {@see PlazoLicencias}.
 *
 * Cada límite en 0 significa sin límite. Sin ninguna fila cargada no hay plazo.
 *
 * **Vigencia por día.** Igual que la Configuración pero con día y no mes: cada
 * cambio agrega una fila que rige desde `vigente_desde` hasta `vigente_hasta`.
 * La que rige hoy no tiene fin; cargar la siguiente la cierra sola el día
 * anterior. Queda el registro de qué plazo regía cada día, quién lo cargó y
 * por qué. Solo se carga desde hoy en adelante: lo que ya rigió no se toca.
 *
 * El plazo que cuenta es el del día en que se registra la licencia, no el de
 * las fechas licenciadas: es una regla sobre cuándo se puede pedir.
 *
 * @property int $dias_anticipacion
 * @property int $dias_atraso
 * @property bool $dias_habiles
 * @property Carbon $vigente_desde
 * @property ?Carbon $vigente_hasta
 * @property string $motivo
 */
class ConfiguracionPlazoLicencia extends Model
{
    /** @use HasFactory<ConfiguracionPlazoLicenciaFactory> */
    use HasFactory, ManejaHorasDelDia, RegistersUserEvents;

    protected $table = 'configuracion_plazos_licencia';

    /**
     * Tope de cada límite, en días: más de un año no es un plazo.
     */
    public const MAX_DIAS = 366;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'dias_anticipacion',
        'dias_atraso',
        'dias_habiles',
        'vigente_desde',
        'vigente_hasta',
        'motivo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dias_anticipacion' => 'integer',
            'dias_atraso' => 'integer',
            'dias_habiles' => 'boolean',
        ];
    }

    /**
     * Día desde el que rige: columna `date`, sin hora ({@see ManejaHorasDelDia}).
     */
    protected function vigenteDesde(): Attribute
    {
        return self::soloFecha();
    }

    /**
     * Último día en que rige, o null si rige en adelante.
     */
    protected function vigenteHasta(): Attribute
    {
        return self::soloFecha();
    }

    /**
     * Quién cargó esta vigencia.
     *
     * @return BelongsTo<User, $this>
     */
    public function registrador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registerUser_id');
    }

    /**
     * El plazo que rige en la fecha dada (hoy si no se da), o `null` si no
     * había ninguno cargado.
     */
    public static function vigente(?CarbonInterface $fecha = null): ?self
    {
        $dia = Carbon::instance($fecha ?? today())->toDateString();

        return static::query()
            ->where('vigente_desde', '<=', $dia)
            ->where(fn ($hasta) => $hasta->whereNull('vigente_hasta')->orWhere('vigente_hasta', '>=', $dia))
            ->orderByDesc('vigente_desde')
            ->first();
    }

    /**
     * Carga un plazo desde el día dado y acomoda las fechas de fin:
     *
     * - el plazo anterior se cierra el día previo;
     * - el nuevo rige en adelante o, si ya había uno programado más adelante,
     *   hasta el día previo a ese.
     *
     * Si ya había uno programado para ese mismo día lo reemplaza: todavía no
     * rigió, así que no hay historia que perder. Que no sea un día que ya
     * empezó lo controla {@see GuardarPlazoLicenciaRequest}.
     *
     * @param  array{dias_anticipacion: int, dias_atraso: int, dias_habiles: bool}  $valores
     */
    public static function guardar(array $valores, CarbonInterface $desde, string $motivo): self
    {
        $dia = Carbon::instance($desde)->startOfDay();
        $usuario = Auth::user();

        return DB::transaction(function () use ($valores, $dia, $motivo, $usuario): self {
            $siguiente = static::query()
                ->where('vigente_desde', '>', $dia->toDateString())
                ->orderBy('vigente_desde')
                ->first();

            static::query()
                ->where('vigente_desde', '<', $dia->toDateString())
                ->orderByDesc('vigente_desde')
                ->first()
                ?->update(['vigente_hasta' => $dia->copy()->subDay()]);

            $fila = static::query()->where('vigente_desde', $dia->toDateString())->first()
                ?? new static(['vigente_desde' => $dia]);

            $fila->fill($valores + [
                'motivo' => $motivo,
                'vigente_hasta' => $siguiente?->vigente_desde->copy()->subDay(),
            ]);
            // Al reemplazar uno programado, la carga pasa a ser de quien lo cambió.
            $fila->registerUser_id = $usuario instanceof User ? $usuario->getKey() : null;
            $fila->save();

            return $fila;
        });
    }

    /**
     * La primera fecha que se puede licenciar si se pide el día `$hoy`, o
     * `null` si no hay límite hacia atrás.
     */
    public function primeraFecha(CarbonInterface $hoy): ?Carbon
    {
        return $this->dias_atraso > 0 ? $this->correr($hoy, -$this->dias_atraso) : null;
    }

    /**
     * La última fecha que se puede licenciar si se pide el día `$hoy`, o
     * `null` si no hay límite hacia adelante.
     */
    public function ultimaFecha(CarbonInterface $hoy): ?Carbon
    {
        return $this->dias_anticipacion > 0 ? $this->correr($hoy, $this->dias_anticipacion) : null;
    }

    /**
     * ¿Pone algún límite?
     */
    public function limita(): bool
    {
        return $this->dias_atraso > 0 || $this->dias_anticipacion > 0;
    }

    /**
     * Cada límite en palabras: «3 días hábiles», «Sin límite».
     */
    public function legible(int $dias): string
    {
        if ($dias <= 0) {
            return 'Sin límite';
        }

        return $dias.($dias === 1 ? ' día' : ' días').($this->dias_habiles ? ' hábil'.($dias === 1 ? '' : 'es') : ' corrido'.($dias === 1 ? '' : 's'));
    }

    /**
     * Mueve `$hoy` tantos días como diga `$dias` (negativo: hacia atrás).
     *
     * En hábiles solo cuentan de lunes a viernes: hoy miércoles, 3 días hábiles
     * atrás es el viernes anterior. Los feriados no se descuentan.
     */
    private function correr(CarbonInterface $hoy, int $dias): Carbon
    {
        $fecha = Carbon::instance($hoy)->startOfDay();

        if (! $this->dias_habiles) {
            return $fecha->addDays($dias);
        }

        $paso = $dias < 0 ? -1 : 1;

        for ($restan = abs($dias); $restan > 0;) {
            $fecha->addDays($paso);

            if (! $fecha->isWeekend()) {
                $restan--;
            }
        }

        return $fecha;
    }
}
