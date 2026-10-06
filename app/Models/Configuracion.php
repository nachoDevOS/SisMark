<?php

namespace App\Models;

use App\Http\Requests\ActualizarConfiguracionRequest;
use App\Services\ProcesadorAsistencia;
use App\Services\TopePermisos;
use App\Traits\ManejaHorasDelDia;
use App\Traits\RegistersUserEvents;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Un parámetro del sistema: `clave` → `valor`.
 *
 * Los parámetros que existen se declaran en {@see PARAMETROS}, agrupados por
 * {@see GRUPOS}: la pantalla de Configuración se arma sola a partir de esas dos
 * listas, así que sumar un parámetro es sumar una entrada ahí —y su tipo de
 * campo, si es uno nuevo—, sin tocar el controlador ni la vista.
 *
 * Se lee y se escribe siempre por clave con {@see valor()} y {@see guardar()};
 * nadie arma consultas a mano sobre la tabla.
 *
 * **Vigencia por mes.** Un parámetro no tiene un valor sino una historia: cada
 * cambio agrega una fila que rige desde el día 1 de un mes (`vigente_desde`)
 * hasta el último día de otro (`vigente_hasta`). La que rige hoy no tiene fin
 * (`vigente_hasta` null); cargar la siguiente la cierra sola, el último día del
 * mes anterior. Así {@see valor()} contesta qué regía en cualquier fecha —el
 * tope de septiembre se sigue contando con el de septiembre aunque después se
 * cambie— y queda el registro de desde y hasta cuándo, quién lo cargó y con qué
 * motivo.
 *
 * Solo se carga desde el mes en curso en adelante: lo que ya rigió no se toca.
 *
 * Toda la pantalla pide un solo permiso, `Update:Configuracion`: quien lo
 * tiene ve y cambia todos los parámetros.
 */
class Configuracion extends Model
{
    use ManejaHorasDelDia, RegistersUserEvents;

    protected $table = 'configuraciones';

    /**
     * Tope de permisos por horas que puede usar un funcionario en el mes, por
     * contrato, en minutos. Sin fila, vacío o en 0: sin tope.
     *
     * @see TopePermisos
     */
    public const TOPE_PERMISO_MENSUAL = 'licencias.tope_permiso_mensual';

    /**
     * Cómo se reparte el tope cuando el funcionario tiene más de un contrato en
     * el mes: {@see TOPE_POR_CONTRATO} o {@see TOPE_POR_MES}.
     *
     * @see TopePermisos
     */
    public const TOPE_PERMISO_ALCANCE = 'licencias.tope_permiso_alcance';

    /**
     * Cada contrato del mes tiene su propio tope.
     */
    public const TOPE_POR_CONTRATO = 'contrato';

    /**
     * Un solo tope para el mes, tenga los contratos que tenga.
     */
    public const TOPE_POR_MES = 'mes';

    /**
     * Una cantidad de tiempo, guardada en minutos y editada en horas y minutos.
     */
    public const TIPO_DURACION = 'duracion';

    /**
     * Una opción de una lista cerrada (`opciones`: valor → etiqueta).
     */
    public const TIPO_OPCIONES = 'opciones';

    /**
     * Secciones de la pantalla, en el orden en que se muestran.
     *
     * @var array<string, array{titulo: string, descripcion: string, icono: string}>
     */
    public const GRUPOS = [
        'licencias' => [
            'titulo' => 'Licencias y permisos',
            'descripcion' => 'Límites que se controlan cuando el funcionario pide un permiso desde Mamoré y cuando Recursos Humanos lo anota desde SisMark.',
            'icono' => 'heroicon-o-clipboard-document-check',
        ],
    ];

    /**
     * Los parámetros configurables.
     *
     * - `grupo`: en qué sección de {@see GRUPOS} va.
     * - `corta`: nombre corto, para la columna del historial.
     * - `tipo`: qué campo lo edita; cada tipo tiene su parcial en
     *   `resources/views/configuracion/campos/` y sus reglas en
     *   {@see ActualizarConfiguracionRequest}.
     * - `maximo`: tope del valor, en la unidad en que se guarda (duración).
     * - `opciones`: valor → etiqueta de cada opción (opciones).
     * - `defecto`: el valor que rige mientras nadie lo configuró.
     *
     * @var array<string, array{grupo: string, etiqueta: string, corta: string, ayuda: string, tipo: string, maximo?: int, opciones?: array<string, string>, defecto?: string}>
     */
    public const PARAMETROS = [
        self::TOPE_PERMISO_MENSUAL => [
            'grupo' => 'licencias',
            'etiqueta' => 'Tope mensual de permisos por horas',
            'corta' => 'Tope mensual',
            'ayuda' => 'Cuánto tiempo de permiso por horas puede sumar un funcionario en el mes. Cada mes '
                .'arranca de cero. Cuentan los permisos personales aprobados y los pendientes: no se puede pedir '
                .'más de lo que cabe. Las licencias de horario completo y las institucionales no descuentan. En 0 '
                .'horas y 0 minutos no hay tope.',
            'tipo' => self::TIPO_DURACION,
            // Un mes laboral ronda las 176 horas: más que eso no es un tope.
            'maximo' => 176 * 60,
        ],
        self::TOPE_PERMISO_ALCANCE => [
            'grupo' => 'licencias',
            'etiqueta' => 'Cómo se cuenta el tope con más de un contrato en el mes',
            'corta' => 'Se cuenta',
            'ayuda' => 'Con un solo contrato en el mes las dos opciones dan lo mismo. Con dos —por ejemplo, del 1 '
                .'al 15 y del 16 al 30— cambia cuánto tiempo tiene el funcionario.',
            'tipo' => self::TIPO_OPCIONES,
            'opciones' => [
                self::TOPE_POR_CONTRATO => 'Por contrato: un tope por cada contrato del mes. Si en el mes tiene '
                    .'2 contratos, tiene 2 topes. Ej.: con un tope de 1 h 30 y contratos del 1 al 15 y del 16 al '
                    .'30, tiene 1 h 30 para los días del primero y otra 1 h 30 para los del segundo. Lo que no '
                    .'usa en un contrato no pasa al otro.',
                self::TOPE_POR_MES => 'Por mes: un solo tope para todo el mes, aunque tenga 2 contratos. Ej.: con '
                    .'un tope de 1 h 30, tiene 1 h 30 en total en el mes.',
            ],
            'defecto' => self::TOPE_POR_CONTRATO,
        ],
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'clave',
        'valor',
        'vigente_desde',
        'vigente_hasta',
        'motivo',
    ];

    /**
     * Día 1 del mes desde el que rige: columna `date`, sin hora
     * ({@see ManejaHorasDelDia}).
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
     * La fila que rige para la clave en la fecha dada (hoy si no se da): la de
     * `vigente_desde` más reciente que ya empezó. `null` si nunca se configuró
     * para esa fecha.
     */
    public static function filaVigente(string $clave, ?CarbonInterface $fecha = null): ?self
    {
        $mes = Carbon::instance($fecha ?? today())->startOfMonth();

        return static::query()
            ->where('clave', $clave)
            ->where('vigente_desde', '<=', $mes->toDateString())
            ->where(fn ($hasta) => $hasta
                ->whereNull('vigente_hasta')
                ->orWhere('vigente_hasta', '>=', $mes->toDateString()))
            ->orderByDesc('vigente_desde')
            ->first();
    }

    /**
     * Valor que regía para la clave en la fecha dada (hoy si no se da), o `null`
     * si nunca se configuró para esa fecha.
     */
    public static function valor(string $clave, ?CarbonInterface $fecha = null): ?string
    {
        return static::filaVigente($clave, $fecha)?->valor;
    }

    /**
     * Valor que rige para un parámetro declarado en la fecha dada: el cargado o,
     * si no había ninguno, su `defecto`.
     */
    public static function vigente(string $clave, ?CarbonInterface $fecha = null): ?string
    {
        return static::valor($clave, $fecha) ?? (self::PARAMETROS[$clave]['defecto'] ?? null);
    }

    /**
     * Carga el valor de la clave desde el mes dado, con su motivo, y acomoda
     * las fechas de fin:
     *
     * - la vigencia anterior se cierra el último día del mes previo;
     * - la nueva rige en adelante, o —si ya había una programada más
     *   adelante— hasta el último día del mes anterior a esa.
     *
     * Si ya había una vigencia programada para ese mismo mes, la reemplaza:
     * todavía no rigió, así que no hay historia que perder. Que no sea un mes
     * que ya empezó lo controla {@see ActualizarConfiguracionRequest}.
     */
    public static function guardar(string $clave, ?string $valor, CarbonInterface $desde, string $motivo): void
    {
        $usuario = Auth::user();
        $mes = Carbon::instance($desde)->startOfMonth();

        DB::transaction(function () use ($clave, $valor, $mes, $motivo, $usuario): void {
            $siguiente = static::query()
                ->where('clave', $clave)
                ->where('vigente_desde', '>', $mes->toDateString())
                ->orderBy('vigente_desde')
                ->first();

            static::query()
                ->where('clave', $clave)
                ->where('vigente_desde', '<', $mes->toDateString())
                ->orderByDesc('vigente_desde')
                ->first()
                ?->update(['vigente_hasta' => $mes->copy()->subDay()]);

            $fila = static::query()
                ->where('clave', $clave)
                ->where('vigente_desde', $mes->toDateString())
                ->first() ?? new static(['clave' => $clave, 'vigente_desde' => $mes]);

            $fila->fill([
                'valor' => $valor,
                'motivo' => $motivo,
                'vigente_hasta' => $siguiente?->vigente_desde->copy()->subDay(),
            ]);
            // Al reemplazar una programada, la carga pasa a ser de quien la cambió.
            $fila->registerUser_id = $usuario instanceof User ? $usuario->getKey() : null;
            $fila->save();
        });
    }

    /**
     * El valor guardado, para leer en pantalla: «1 h 30», «Sin tope», «Por
     * contrato».
     */
    public static function legible(string $clave, ?string $valor): string
    {
        $parametro = self::PARAMETROS[$clave] ?? null;

        return match ($parametro['tipo'] ?? null) {
            self::TIPO_DURACION => (int) $valor > 0
                ? ProcesadorAsistencia::duracion((int) $valor * 60)
                : 'Sin tope',
            // La etiqueta de cada opción es una explicación larga: se toma lo
            // que va antes de los dos puntos («Por contrato: …»).
            self::TIPO_OPCIONES => Str::before($parametro['opciones'][$valor] ?? (string) $valor, ':'),
            default => (string) $valor,
        };
    }

    /**
     * Los parámetros agrupados por sección, en el orden de {@see GRUPOS}.
     *
     * @return array<string, array<string, array{grupo: string, etiqueta: string, corta: string, ayuda: string, tipo: string, maximo?: int, opciones?: array<string, string>, defecto?: string}>>
     */
    public static function porGrupo(): array
    {
        $grupos = array_fill_keys(array_keys(self::GRUPOS), []);

        foreach (self::PARAMETROS as $clave => $parametro) {
            $grupos[$parametro['grupo']][$clave] = $parametro;
        }

        return array_filter($grupos);
    }

    /**
     * Nombre del campo del formulario para una clave. Las claves llevan punto,
     * que la validación leería como un nivel de anidamiento.
     */
    public static function campo(string $clave): string
    {
        return str_replace('.', '_', $clave);
    }
}
