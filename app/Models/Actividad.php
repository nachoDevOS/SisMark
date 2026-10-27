<?php

namespace App\Models;

use Database\Factories\ActividadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Una fila de la actividad de usuarios: un pedido al sistema o un evento
 * importante (entró, miró una ficha, buscó, bajó un Excel…). Ver
 * docs/ACTIVIDAD-DE-USUARIOS.md.
 *
 * Inmutable: no se edita ni se borra desde la aplicación. La única baja es la
 * de retención, con una consulta directa.
 *
 * No es la Bitácora de biométricos ({@see EquipoAuditoria}).
 */
class Actividad extends Model
{
    /** @use HasFactory<ActividadFactory> */
    use HasFactory;

    protected $table = 'actividades';

    public const UPDATED_AT = null;

    /** Un pedido cualquiera, sin frase: el detalle. */
    public const PEDIDO = 'pedido';

    public const INGRESO = 'ingreso';

    public const INGRESO_FALLIDO = 'ingreso_fallido';

    public const INGRESO_RECHAZADO = 'ingreso_rechazado';

    public const BLOQUEO = 'bloqueo';

    public const SALIDA = 'salida';

    public const CAMBIO_PASSWORD = 'cambio_password';

    public const VER = 'ver';

    public const BUSCAR = 'buscar';

    public const REPORTE = 'reporte';

    public const IMPRIMIR = 'imprimir';

    public const EXPORTAR = 'exportar';

    public const VER_TUTORIAL = 'ver_tutorial';

    public const CREAR = 'crear';

    public const EDITAR = 'editar';

    public const ELIMINAR = 'eliminar';

    /** Un aviso de cambio que no cambió nada: lo pedido ya estaba así. */
    public const SIN_CAMBIOS = 'sin_cambios';

    /**
     * Etiqueta de cada acción y el tipo al que pertenece, para la pantalla.
     *
     * @var array<string, array{etiqueta: string, tipo: string}>
     */
    public const ACCIONES = [
        self::INGRESO => ['etiqueta' => 'Entró', 'tipo' => 'acceso'],
        self::INGRESO_FALLIDO => ['etiqueta' => 'Intento fallido', 'tipo' => 'acceso'],
        self::INGRESO_RECHAZADO => ['etiqueta' => 'Ingreso rechazado', 'tipo' => 'acceso'],
        self::BLOQUEO => ['etiqueta' => 'Bloqueo', 'tipo' => 'acceso'],
        self::SALIDA => ['etiqueta' => 'Salió', 'tipo' => 'acceso'],
        self::CAMBIO_PASSWORD => ['etiqueta' => 'Cambió su contraseña', 'tipo' => 'acceso'],
        self::VER => ['etiqueta' => 'Miró', 'tipo' => 'consulta'],
        self::BUSCAR => ['etiqueta' => 'Buscó', 'tipo' => 'busqueda'],
        self::REPORTE => ['etiqueta' => 'Generó reporte', 'tipo' => 'reporte'],
        self::IMPRIMIR => ['etiqueta' => 'Imprimió', 'tipo' => 'reporte'],
        self::EXPORTAR => ['etiqueta' => 'Bajó en Excel', 'tipo' => 'reporte'],
        self::VER_TUTORIAL => ['etiqueta' => 'Miró un tutorial', 'tipo' => 'ayuda'],
        self::CREAR => ['etiqueta' => 'Registró', 'tipo' => 'cambio'],
        self::EDITAR => ['etiqueta' => 'Cambió', 'tipo' => 'cambio'],
        self::ELIMINAR => ['etiqueta' => 'Dio de baja', 'tipo' => 'cambio'],
        self::SIN_CAMBIOS => ['etiqueta' => 'Sin cambios', 'tipo' => 'aviso'],
        self::PEDIDO => ['etiqueta' => 'Pedido', 'tipo' => 'detalle'],
    ];

    /**
     * Las acciones que son problemas de acceso: van en rojo y alimentan el
     * filtro «solo intentos fallidos».
     *
     * @var list<string>
     */
    public const FALLIDAS = [self::INGRESO_FALLIDO, self::INGRESO_RECHAZADO, self::BLOQUEO];

    /** @var list<string> */
    protected $fillable = [
        'user_id', 'sistema_externo_id', 'email', 'importante', 'accion', 'modulo', 'descripcion',
        'ci', 'datos', 'ruta', 'url', 'metodo', 'estado', 'duracion_ms', 'ip', 'navegador',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('La actividad de usuarios no se edita.'));
        static::deleting(fn () => throw new LogicException('La actividad de usuarios no se borra.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'datos' => 'array',
            'importante' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    /**
     * @return BelongsTo<SistemaExterno, $this>
     */
    public function sistemaExterno(): BelongsTo
    {
        return $this->belongsTo(SistemaExterno::class, 'sistema_externo_id')->withTrashed();
    }

    /**
     * Quién: el usuario, el sistema externo de la API o el correo escrito.
     */
    public function quien(): string
    {
        return $this->usuario?->name
            ?? $this->sistemaExterno?->nombre
            ?? $this->email
            ?? 'Sin sesión';
    }

    /**
     * La CI del funcionario sobre el que fue el pedido: la guardada o, en lo
     * registrado antes de que se guardara en los pedidos de detalle, la que
     * trae la URL (`/api/v1/funcionarios/{ci}/…` o `?ci=`).
     */
    public function ciDelFuncionario(): ?string
    {
        if (filled($this->ci)) {
            return $this->ci;
        }

        $url = (string) $this->url;

        if (preg_match('#^/api/v1/funcionarios/([^/?]+)#', $url, $coincidencia) === 1) {
            $ci = rawurldecode($coincidencia[1]);
        } else {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $ci = is_string($query['ci'] ?? null) ? $query['ci'] : '';
        }

        return mb_substr(trim($ci), 0, 20) ?: null;
    }

    public function etiquetaAccion(): string
    {
        return self::ACCIONES[$this->accion]['etiqueta'] ?? $this->accion;
    }

    public function esFallida(): bool
    {
        return in_array($this->accion, self::FALLIDAS, true);
    }
}
