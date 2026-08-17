<?php

namespace App\Models;

use App\Services\RegistroLicencia;
use App\Traits\RegistersUserEvents;
use Database\Factories\LicenciaFactory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Pagination\LengthAwarePaginator as Paginador;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;

/**
 * Licencia/permiso en la base local (MySQL), migrada desde «Licencias» del SIA.
 *
 * Conexión por defecto (MySQL), con id propio, timestamps y eliminación lógica.
 * El carnet vive en `ci` (en el SIA era IdPersona). `lEntra`/`lSale` guardan la
 * hora sobre la fecha base 1899-12-30, como el SIA real.
 *
 * El horario se referencia siempre por la FK `turno_id`. La columna `idTurno`
 * (código del SIA) sobrevive solo como dato histórico de lo migrado: no se
 * escribe ni se consulta desde el sistema.
 */
class Licencia extends Model
{
    /** @use HasFactory<LicenciaFactory> */
    use HasFactory, RegistersUserEvents, SoftDeletes;

    /**
     * Estado de aprobación. Solo «Pendiente» espera una decisión: es el estado
     * con el que nacen las solicitudes que hace el propio funcionario desde
     * Mamoré. Lo que carga Recursos Humanos acá nace «Aprobado» y surte efecto
     * en el acto, como siempre.
     */
    public const PENDIENTE = 'Pendiente';

    public const APROBADO = 'Aprobado';

    public const RECHAZADO = 'Rechazado';

    /**
     * @var list<string>
     */
    public const ESTADOS = [self::PENDIENTE, self::APROBADO, self::RECHAZADO];

    /**
     * Por dónde entró la licencia.
     *
     * Se guarda en vez de deducirse de `registerUser_id` nulo, que es lo que se
     * hacía antes: esa columna dice quién dio el alta, no por dónde entró, y
     * queda nula por más de un motivo.
     */
    public const ORIGEN_PROPIO = 'propio';

    public const ORIGEN_MAMORE = 'mamore';

    public const ORIGEN_SIA = 'sia';

    /**
     * Cómo se escribe cada origen en pantalla.
     *
     * @var array<string, string>
     */
    public const ORIGENES = [
        self::ORIGEN_PROPIO => 'Propio',
        self::ORIGEN_MAMORE => 'Mamoré',
        self::ORIGEN_SIA => 'SIA',
    ];

    protected $table = 'licencias';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'fechaPedido',
        // De qué alta salió la fila. Todas las de un mismo pedido —un rango
        // expandido a un día y turno por fila— comparten este valor, que es lo
        // que permite mostrarlas como una sola licencia. En lo migrado del SIA
        // cada fila lleva la suya, porque allá la fila es la licencia: ver la
        // migración `agregar_solicitud_a_licencias`.
        'solicitud',
        // Por dónde entró: «propio», «mamore» o «sia».
        'origen',
        'usuario',
        'fecha',
        'ci',
        // `idTurno` queda fuera a propósito: es histórico del SIA, el sistema
        // referencia el horario por la FK `turno_id`.
        'turno_id',
        'lEntra',
        'lSale',
        'tCompleto',
        'motivo',
        'goceHaberes',
        // Nota de la revisión: por qué Recursos Humanos rechazó (o aprobó) la
        // solicitud. Es lo que el funcionario necesita leer para entender en qué
        // quedó su pedido.
        'observacion',
        'estado',
        // Quién resolvió la solicitud y cuándo. Distinto de `registerUser_id`,
        // que es quién dio el alta: en lo que llega de Mamoré viene nulo porque
        // el funcionario no tiene usuario en SisMark.
        'revisadoPor_id',
        'revisadoEn',
        // Respaldo que justifica la licencia: la ruta dentro del disco `s3` y
        // el nombre con el que lo subieron, que es el que se le muestra a quien
        // lo descarga.
        'adjunto',
        'adjuntoNombre',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fechaPedido' => 'datetime',
            'fecha' => 'date',
            'lEntra' => 'datetime',
            'lSale' => 'datetime',
            'revisadoEn' => 'datetime',
            'tCompleto' => 'boolean',
            'goceHaberes' => 'boolean',
        ];
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'ci', 'ci');
    }

    public function turno(): BelongsTo
    {
        return $this->belongsTo(Turno::class, 'turno_id');
    }

    /**
     * Quién de Recursos Humanos resolvió la solicitud.
     */
    public function revisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revisadoPor_id');
    }

    /**
     * Las filas hermanas de una misma solicitud, la propia incluida.
     *
     * Un alta expande el rango a una fila por día y turno, así que «la licencia»
     * que pidió el funcionario son varias filas. `solicitud` las marca a todas
     * con el mismo valor: lo escribe {@see RegistroLicencia} una vez por alta y
     * por carnet.
     *
     * Lo migrado del SIA lleva su propio id como solicitud, así que cada fila
     * histórica es su propia licencia. Agruparlas por `ci` + `fechaPedido` —que
     * fue el primer intento— junta cosas que no van juntas: el 62% de esas filas
     * viene sin hora en `fechaPedido`, y así un grupo llegaba a 10.308 filas
     * separadas por hasta 20 años.
     *
     * El `null` no debería existir —la migración rellenó lo viejo y los dos
     * escritores de la tabla la escriben—, pero si una fila se cuela sin valor
     * se la trata sola en vez de juntarla con todas las demás sin valor.
     */
    public function scopeDeLaSolicitud(Builder $query, self $licencia): Builder
    {
        return $licencia->solicitud === null
            ? $query->whereKey($licencia->getKey())
            : $query->where('solicitud', $licencia->solicitud);
    }

    /**
     * De cada solicitud, la fila que la abre: la del primer día.
     *
     * **Se usa siempre acotada a un puñado de solicitudes** —las de la página—,
     * con un `whereIn('solicitud', …)` delante. Suelta, sobre la tabla entera, la
     * subconsulta se evalúa fila por fila: sin filtros anda porque el `LIMIT 10`
     * corta temprano, pero con una búsqueda poco frecuente recorre el millón de
     * filas y no vuelve (>45 s medidos). Acotada corre diez veces y es instantánea.
     *
     * Se compara por `id` y no por `fecha` porque un turno partido deja dos filas
     * del mismo pedido en el mismo día: comparando la fecha entrarían las dos y la
     * licencia saldría repetida.
     *
     * El `deleted_at IS NULL` va explícito: el scope de eliminación lógica alcanza
     * a la consulta de afuera, no a la subconsulta.
     */
    public function scopeIniciosDeSolicitud(Builder $query): Builder
    {
        $tabla = $this->getTable();

        return $query->whereRaw(
            "{$tabla}.id = (SELECT s.id FROM {$tabla} AS s"
            ." WHERE s.solicitud = {$tabla}.solicitud AND s.deleted_at IS NULL"
            .' ORDER BY s.fecha, s.id LIMIT 1)'
        );
    }

    /**
     * Pagina una consulta **por solicitud** y no por fila: una licencia de cinco
     * días cuenta como una, y lo que sale en pantalla es la fila que la abre.
     *
     * Va en dos pasos:
     *
     * 1. Qué solicitudes cruzan el filtro, con su fecha más reciente, agrupando
     *    solo lo filtrado. Eso es lo que se cuenta y lo que se pagina.
     * 2. La fila que abre cada una de las diez, ya acotada con un `whereIn`.
     *
     * El segundo paso **tiene que ir acotado**: la subconsulta de
     * `iniciosDeSolicitud()` suelta sobre la tabla entera se evalúa fila por fila
     * y con una búsqueda poco frecuente no vuelve. Ver la migración
     * `agregar_solicitud_a_licencias`.
     *
     * @param  Builder  $filtrada  la consulta ya filtrada, sin ordenar ni paginar
     * @return LengthAwarePaginator<int, static>
     */
    public static function paginarPorSolicitud(Builder $filtrada, int $porPagina): LengthAwarePaginator
    {
        $solicitudes = (clone $filtrada)
            ->selectRaw('solicitud, MAX(fecha) as ultima')
            ->groupBy('solicitud')
            ->orderByDesc('ultima')
            ->paginate($porPagina);

        $claves = $solicitudes->pluck('solicitud')->all();

        $inicios = $claves === []
            ? collect()
            : static::query()
                ->with('turno')
                ->whereIn('solicitud', $claves)
                ->iniciosDeSolicitud()
                ->get()
                ->keyBy('solicitud');

        // El orden lo manda el primer paso; el segundo solo trae las filas.
        $filas = collect($claves)
            ->map(fn (string $clave) => $inicios->get($clave))
            ->filter()
            ->values();

        return new Paginador($filas, $solicitudes->total(), $solicitudes->perPage(), $solicitudes->currentPage(), [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => 'page',
        ]);
    }

    /**
     * Hasta qué día llega cada solicitud y cuántos días abarca.
     *
     * Se consulta solo para las solicitudes de la página —diez—, así que va por
     * el índice `(solicitud, fecha)` y no toca el resto de la tabla.
     *
     * @param  Collection<int, string>|list<string>  $solicitudes
     * @return Collection<string, object>
     */
    public static function resumenDe(iterable $solicitudes): Collection
    {
        $claves = collect($solicitudes)->filter()->unique()->values();

        if ($claves->isEmpty()) {
            return collect();
        }

        return static::query()
            ->whereIn('solicitud', $claves->all())
            ->selectRaw('solicitud, MAX(fecha) as hasta, COUNT(*) as dias, COUNT(DISTINCT estado) as estados')
            ->groupBy('solicitud')
            ->get()
            ->keyBy('solicitud');
    }

    /**
     * Cuántas **solicitudes** esperan decisión, no cuántas filas: un pedido de
     * cinco días es uno solo.
     *
     * Es lo que rotula el aviso de la barra superior, así que corre en cada
     * pantalla: va por el índice `(estado, fecha)` y cuenta solo lo pendiente,
     * que son unos pocos registros —lo aprobado, que es el millón, ni se toca—.
     */
    public static function solicitudesPendientes(): int
    {
        return static::query()->pendientes()->distinct()->count('solicitud');
    }

    /**
     * Solo las que esperan una decisión de Recursos Humanos.
     */
    public function scopePendientes(Builder $query): Builder
    {
        return $query->where('estado', self::PENDIENTE);
    }

    /**
     * Cómo se escribe el origen en pantalla. Lo desconocido se muestra tal cual
     * en vez de quedar en blanco.
     */
    public function getOrigenEtiquetaAttribute(): string
    {
        return self::ORIGENES[$this->origen] ?? (string) $this->origen;
    }

    /**
     * ¿La rechazó Recursos Humanos?
     */
    public function getEsRechazadaAttribute(): bool
    {
        return $this->estado === self::RECHAZADO;
    }

    /**
     * ¿La pidió el propio funcionario desde su perfil?
     */
    public function getEsDeMamoreAttribute(): bool
    {
        return $this->origen === self::ORIGEN_MAMORE;
    }

    /**
     * ¿Se puede dar de baja desde este sistema?
     */
    public function getEsEliminableAttribute(): bool
    {
        return $this->motivoParaNoEliminar() === null;
    }

    /**
     * Por qué esta licencia no se elimina, o `null` si sí se puede.
     *
     * Vive en el modelo y no en la policy porque el `Gate::before` de
     * `AppServiceProvider` le concede todo al rol super_admin sin llegar a
     * ejecutarla: una regla de estado escrita ahí se saltearía justamente para
     * quien más usa la pantalla. Así el controlador y las vistas comparten el
     * mismo criterio y el mismo texto.
     */
    public function motivoParaNoEliminar(): ?string
    {
        // Lo que pidió el funcionario se resuelve, no se borra. La baja de su
        // propio pedido la tiene él en su perfil, mientras siga «Pendiente»;
        // desde acá se aprueba o se rechaza, y en los dos casos queda registro
        // de qué se decidió.
        if ($this->esDeMamore) {
            return 'Esta licencia la pidió el funcionario desde Mamoré: se aprueba o se rechaza, no se elimina.';
        }

        // La fila rechazada **es** la constancia de que se pidió y se negó, con
        // su motivo. Borrarla deja al funcionario sin saber qué pasó.
        if ($this->esRechazada) {
            return 'Una licencia rechazada no se elimina: es la constancia de que se pidió y se negó.';
        }

        return null;
    }

    /**
     * ¿Espera una decisión? Es lo único sobre lo que se puede aprobar o
     * rechazar: lo ya resuelto no se revierte desde la ficha.
     */
    public function getEsPendienteAttribute(): bool
    {
        return $this->estado === self::PENDIENTE;
    }

    /**
     * Filtra por CI, motivo o nombre del funcionario: cada palabra debe cruzar
     * en alguna de las tres, igual que el buscador de funcionarios.
     *
     * El `coincideNombre` va envuelto en un `where` anidado: sus `orWhere` sin
     * agrupar se mezclarían con la correlación del `whereHas` y el subquery
     * daría verdadero para cualquier fila (el filtro no filtraría nada).
     */
    public function scopeBuscar(Builder $query, string $texto): Builder
    {
        foreach (Persona::terminos($texto) as $termino) {
            $query->where(fn (Builder $sub) => $sub
                ->where('ci', 'like', "%{$termino}%")
                ->orWhere('motivo', 'like', "%{$termino}%")
                ->orWhereHas('persona', fn (Builder $persona) => $persona
                    ->where(fn (Builder $nombre) => $nombre->coincideNombre($termino))));
        }

        return $query;
    }

    /**
     * Etiqueta legible del horario licenciado: «MIE: 08:00 – 16:00».
     */
    public function getResumenTurnoAttribute(): string
    {
        $turno = $this->turno;

        if (! $turno instanceof Turno) {
            return '—';
        }

        return trim((string) $turno->nombreTurno).': '
            .($turno->hEntrada?->format('H:i') ?? '—').' – '
            .($turno->hSalida?->format('H:i') ?? '—');
    }
}
