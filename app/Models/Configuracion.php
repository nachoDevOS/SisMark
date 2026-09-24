<?php

namespace App\Models;

use App\Http\Requests\ActualizarConfiguracionRequest;
use App\Services\TopePermisos;
use App\Traits\RegistersUserEvents;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

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
 * Toda la pantalla pide un solo permiso, `Update:Configuracion`: quien lo
 * tiene ve y cambia todos los parámetros.
 */
class Configuracion extends Model
{
    use RegistersUserEvents;

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
     * - `tipo`: qué campo lo edita; cada tipo tiene su parcial en
     *   `resources/views/configuracion/campos/` y sus reglas en
     *   {@see ActualizarConfiguracionRequest}.
     * - `maximo`: tope del valor, en la unidad en que se guarda (duración).
     * - `opciones`: valor → etiqueta de cada opción (opciones).
     * - `defecto`: el valor que rige mientras nadie lo configuró.
     *
     * @var array<string, array{grupo: string, etiqueta: string, ayuda: string, tipo: string, maximo?: int, opciones?: array<string, string>, defecto?: string}>
     */
    public const PARAMETROS = [
        self::TOPE_PERMISO_MENSUAL => [
            'grupo' => 'licencias',
            'etiqueta' => 'Tope mensual de permisos por horas',
            'ayuda' => 'Cuánto tiempo de permiso por horas puede sumar un funcionario en el mes. Cada mes '
                .'arranca de cero. Cuentan los permisos personales aprobados y los pendientes: no se puede pedir '
                .'más de lo que cabe. Las licencias de turno completo y las institucionales no descuentan. En 0 '
                .'horas y 0 minutos no hay tope.',
            'tipo' => self::TIPO_DURACION,
            // Un mes laboral ronda las 176 horas: más que eso no es un tope.
            'maximo' => 176 * 60,
        ],
        self::TOPE_PERMISO_ALCANCE => [
            'grupo' => 'licencias',
            'etiqueta' => 'Cómo se cuenta el tope con más de un contrato en el mes',
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
        'updateUser_id',
    ];

    /**
     * Quién cambió el valor por última vez.
     *
     * @return BelongsTo<User, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updateUser_id');
    }

    /**
     * Valor guardado para la clave, o `null` si nunca se configuró.
     */
    public static function valor(string $clave): ?string
    {
        return static::query()->where('clave', $clave)->value('valor');
    }

    /**
     * Valor que rige para un parámetro declarado: el guardado o, si nunca se
     * configuró, su `defecto`.
     */
    public static function vigente(string $clave): ?string
    {
        return static::valor($clave) ?? (self::PARAMETROS[$clave]['defecto'] ?? null);
    }

    /**
     * Guarda el valor de la clave, creando la fila la primera vez.
     */
    public static function guardar(string $clave, ?string $valor): void
    {
        $usuario = Auth::user();

        static::query()->updateOrCreate(['clave' => $clave], [
            'valor' => $valor,
            'updateUser_id' => $usuario instanceof User ? $usuario->getKey() : null,
        ]);
    }

    /**
     * Los parámetros agrupados por sección, en el orden de {@see GRUPOS}.
     *
     * @return array<string, array<string, array{grupo: string, etiqueta: string, ayuda: string, tipo: string, maximo?: int, opciones?: array<string, string>, defecto?: string}>>
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
