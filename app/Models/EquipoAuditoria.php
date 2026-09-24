<?php

namespace App\Models;

use App\Traits\RegistersUserEvents;
use Database\Factories\EquipoAuditoriaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una entrada de la bitácora de equipos biométricos.
 *
 * Se escribe sola cada vez que alguien exporta el CSV, sincroniza el reloj,
 * importa un CSV de marcaciones, vacía el reloj o da de baja un equipo. No se
 * edita ni se borra: es el registro de quién hizo qué y por qué.
 */
class EquipoAuditoria extends Model
{
    /** @use HasFactory<EquipoAuditoriaFactory> */
    use HasFactory, RegistersUserEvents;

    /** Bajó el historial del equipo en un CSV. */
    public const ACCION_EXPORTAR = 'exportar';

    /** Mandó las marcaciones a la tabla local de asistencias. */
    public const ACCION_SINCRONIZAR = 'sincronizar';

    /** Vació el buffer de marcaciones del reloj. */
    public const ACCION_LIMPIAR = 'limpiar';

    /** Dio de baja el equipo del sistema. */
    public const ACCION_ELIMINAR = 'eliminar';

    /** Subió a la base un CSV con marcaciones del equipo, con motivo. */
    public const ACCION_IMPORTAR = 'importar';

    /**
     * Etiquetas legibles de cada acción, para las pantallas.
     *
     * @var array<string, string>
     */
    public const ETIQUETAS = [
        self::ACCION_EXPORTAR => 'Exportó CSV',
        self::ACCION_SINCRONIZAR => 'Envió a la BD',
        self::ACCION_LIMPIAR => 'Limpió el equipo',
        self::ACCION_ELIMINAR => 'Eliminó el equipo',
        self::ACCION_IMPORTAR => 'Importó CSV',
    ];

    /**
     * Acciones que guardan marcaciones en `asistencias`. Cada marcación que
     * insertan lleva el id de la entrada en `equipo_auditoria_id`.
     *
     * @var list<string>
     */
    public const ACCIONES_QUE_GUARDAN = [
        self::ACCION_SINCRONIZAR,
        self::ACCION_IMPORTAR,
    ];

    /**
     * Acciones que destruyen información y por eso exigen motivo.
     *
     * @var list<string>
     */
    public const ACCIONES_DESTRUCTIVAS = [
        self::ACCION_LIMPIAR,
        self::ACCION_ELIMINAR,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'equipo_id',
        'accion',
        'motivo',
        'datos_equipo',
        // Cuántas dice el reloj que tiene guardadas (su propio contador).
        'en_equipo',
        // Cuántas llegaron a SisMark, y qué pasó con ellas.
        'total_marcaciones',
        'nuevas',
        'repetidas',
        'sin_funcionario',
        'fallidas',
        'desde',
        'hasta',
        'detalle',
        'exito',
        'ip_usuario',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'datos_equipo' => 'array',
            'exito' => 'boolean',
            'en_equipo' => 'integer',
            'total_marcaciones' => 'integer',
            'nuevas' => 'integer',
            'repetidas' => 'integer',
            'sin_funcionario' => 'integer',
            'fallidas' => 'integer',
        ];
    }

    /**
     * Anota una acción en la bitácora.
     *
     * Toma sola la foto del equipo y la IP del request; el usuario lo pone el
     * trait RegistersUserEvents en `registerUser_id`. Quien llama solo pasa lo
     * propio de la acción.
     *
     * El equipo va en null en la importación de CSV: el archivo no dice de qué
     * reloj salió, así que no hay equipo que anotar ni foto que sacar.
     *
     * @param  array{motivo?: ?string, en_equipo?: ?int, total_marcaciones?: ?int, nuevas?: ?int, repetidas?: ?int, sin_funcionario?: ?int, fallidas?: ?int, desde?: ?string, hasta?: ?string, detalle?: ?string, exito?: bool}  $extra
     */
    public static function registrar(?Equipo $equipo, string $accion, array $extra = []): self
    {
        return static::create([
            'equipo_id' => $equipo?->id,
            'accion' => $accion,
            'datos_equipo' => $equipo ? static::fotoDelEquipo($equipo) : [],
            'ip_usuario' => request()->ip(),
            ...$extra,
        ]);
    }

    /**
     * Copia de los datos del equipo tal como estaban al momento de la acción.
     *
     * Se guarda la foto en vez de depender del join: si después le cambian la
     * IP o lo dan de baja, la bitácora sigue mostrando cómo estaba entonces.
     *
     * Se omite `comm_key` a propósito: es la contraseña del reloj y no tiene
     * por qué quedar duplicada en una tabla que se lee desde una pantalla.
     *
     * @return array<string, mixed>
     */
    private static function fotoDelEquipo(Equipo $equipo): array
    {
        return [
            'id' => $equipo->id,
            'nombre' => $equipo->nombre,
            'ip' => $equipo->ip,
            'puerto' => $equipo->puerto,
            'ubicacion' => $equipo->ubicacion,
            'algoritmo' => $equipo->algoritmo,
            'en_linea' => (bool) $equipo->en_linea,
            'activo' => (bool) $equipo->activo,
            'ultima_sync' => $equipo->ultima_sync?->toDateTimeString(),
        ];
    }

    /**
     * Usuario que ejecutó la acción.
     *
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registerUser_id');
    }

    /**
     * Equipo afectado. Puede venir null si el equipo ya no existe: en ese caso
     * se muestran los datos guardados en `datos_equipo`.
     *
     * @return BelongsTo<Equipo, $this>
     */
    public function equipo(): BelongsTo
    {
        return $this->belongsTo(Equipo::class);
    }

    /**
     * Marcaciones que insertó esta sincronización o importación: las nuevas y
     * las sin funcionario. Las repetidas no están, porque ya estaban cargadas.
     *
     * @return HasMany<Asistencia, $this>
     */
    public function asistencias(): HasMany
    {
        return $this->hasMany(Asistencia::class, 'equipo_auditoria_id');
    }

    /**
     * Cuántas marcaciones guardó esta carga en la base, con funcionario o sin
     * él. Es lo que se ve al abrir sus marcaciones desde la bitácora.
     */
    public function marcacionesGuardadas(): int
    {
        return (int) $this->nuevas + (int) $this->sin_funcionario;
    }

    /**
     * Etiqueta legible de la acción.
     */
    public function etiquetaAccion(): string
    {
        return self::ETIQUETAS[$this->accion] ?? $this->accion;
    }

    /**
     * Nombre del equipo tal como estaba al momento de la acción. Una
     * importación de CSV no tiene equipo: el archivo no dice de qué reloj salió.
     */
    public function nombreEquipo(): string
    {
        if ($this->accion === self::ACCION_IMPORTAR && empty($this->datos_equipo)) {
            return 'Archivo CSV';
        }

        return $this->datos_equipo['nombre'] ?? 'Equipo eliminado';
    }

    /**
     * Nombre de quien ejecutó la acción. "Sistema" si no vino de una sesión
     * (por ejemplo, una tarea de consola) o si el usuario ya no existe.
     */
    public function nombreUsuario(): string
    {
        return $this->usuario?->name ?? 'Sistema';
    }

    /**
     * Cuántas marcaciones tenía el reloj que no llegaron a SisMark.
     *
     * `null` cuando no se puede comprobar: la acción no leyó el buffer
     * (exportar, limpiar, eliminar), el reloj no contestó, o su firmware no
     * expone el contador. Eso es distinto de cero, que afirma que no se perdió
     * nada.
     */
    public function marcacionesPerdidas(): ?int
    {
        if ($this->en_equipo === null || $this->total_marcaciones === null) {
            return null;
        }

        // Nunca negativo: si el reloj declara menos de lo que entregó, el raro
        // es el contador, no la transferencia. Ver transferenciaCompleta().
        return max(0, $this->en_equipo - $this->total_marcaciones);
    }

    /**
     * ¿Llegó a SisMark todo lo que el reloj declaró tener?
     *
     * `null` cuando no hay con qué comparar, y en ese caso quien muestra el
     * dato no afirma ni desmiente: la corrida no se marca incompleta por no
     * haber podido comprobarla.
     *
     * Un contador **menor** que lo entregado cuenta como completa. Pasa con
     * firmware viejo después de un corte de luz, y no es motivo para dudar de
     * marcaciones que sí llegaron.
     */
    public function transferenciaCompleta(): ?bool
    {
        $perdidas = $this->marcacionesPerdidas();

        return $perdidas === null ? null : $perdidas === 0;
    }
}
