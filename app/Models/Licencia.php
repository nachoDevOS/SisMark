<?php

namespace App\Models;

use App\Services\RegistroLicencia;
use App\Services\ResumenEscritorio;
use App\Traits\ManejaHorasDelDia;
use App\Traits\RegistersUserEvents;
use Database\Factories\LicenciaFactory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Pagination\LengthAwarePaginator as Paginador;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Licencia/permiso en la base local (MySQL), migrada desde «Licencias» del SIA.
 *
 * Conexión por defecto (MySQL), con id propio, timestamps y eliminación lógica.
 * El carnet vive en `ci` (en el SIA era IdPersona). `lEntra`/`lSale` son
 * columnas `time`: guardan **solo la hora**, porque el día ya lo pone `fecha`.
 * El cast sigue siendo `datetime` para que se sigan leyendo como Carbon —todo
 * el sistema las consume con `->format('H:i')`—; la fecha que Carbon les pone
 * es la de hoy y no se usa en ninguna parte.
 *
 * El horario se referencia siempre por la FK `turno_id`. La columna `idTurno`
 * (código del SIA) sobrevive solo como dato histórico de lo migrado: no se
 * escribe ni se consulta desde el sistema.
 */
class Licencia extends Model
{
    /** @use HasFactory<LicenciaFactory> */
    use HasFactory, ManejaHorasDelDia, RegistersUserEvents, SoftDeletes;

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
            // `lEntra` y `lSale` las maneja {@see ManejaHorasDelDia}.
            'revisadoEn' => 'datetime',
            'tCompleto' => 'boolean',
            'goceHaberes' => 'boolean',
        ];
    }

    /**
     * Tramo horario de la licencia: columnas `time`, Carbon al leer y H:i:s al
     * escribir.
     */
    protected function lEntra(): Attribute
    {
        return self::horaDelDia();
    }

    protected function lSale(): Attribute
    {
        return self::horaDelDia();
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
    /**
     * Con qué clave se agrupa una licencia.
     *
     * Lo pedido desde SisMark o Mamoré comparte `solicitud`. Lo migrado del SIA
     * la trae en **null** —no fue un pedido sino una licencia ya otorgada—, y
     * ahí cada fila es su propia licencia: se la agrupa por su `id`.
     *
     * Un `id` numérico nunca choca con una `solicitud`, que es un ULID o un
     * hash de 26 caracteres.
     */
    public const CLAVE_AGRUPADORA = 'COALESCE(solicitud, id)';

    /**
     * Minutos que se cachea el total de filas del listado.
     *
     * Es largo a propósito: lo cacheado es **el número de páginas**, no las
     * filas, y el listado sale de la base en cada carga. Un TTL corto haría que
     * casi toda visita pagara el conteo entero —la pantalla no se mira cada
     * cinco minutos—, que es justo lo que se quiere evitar. Mismo criterio que
     * {@see ResumenEscritorio} con el panel de calidad de datos.
     */
    private const CACHE_CONTEO_MINUTOS = 60;

    /**
     * La misma clave, resuelta sobre una fila ya cargada.
     */
    public function getClaveAgrupadoraAttribute(): string
    {
        return (string) ($this->solicitud ?? $this->getKey());
    }

    /**
     * Separa un puñado de claves entre las que son una solicitud de verdad y
     * las que son el `id` de una fila del SIA.
     *
     * Se separan porque cada una se busca por su propio índice: las primeras
     * por `(solicitud, fecha)`, las segundas por la clave primaria. Buscarlas
     * todas juntas con un `COALESCE` obligaría a recorrer la tabla entera.
     *
     * @param  list<mixed>  $claves
     * @return array{0: list<string>, 1: list<int>}
     */
    private static function separarClaves(array $claves): array
    {
        $solicitudes = [];
        $ids = [];

        foreach ($claves as $clave) {
            if (ctype_digit((string) $clave)) {
                $ids[] = (int) $clave;
            } else {
                $solicitudes[] = (string) $clave;
            }
        }

        return [$solicitudes, $ids];
    }

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
     * ---
     * **No se agrupa la tabla entera, y esa es la razón de todo lo que sigue.**
     *
     * Agrupar por `COALESCE(solicitud, id)` es agrupar por una *expresión*, y
     * ninguna expresión puede resolverse por índice: MySQL leía el millón de
     * filas, armaba un millón de grupos en una tabla temporal y los ordenaba
     * para devolver diez. Medido sobre la base de desarrollo, 6,9 s esa consulta
     * y 7,6 s el `COUNT` que la paginación arma encima —**catorce segundos de
     * SQL por cada carga del listado**—.
     *
     * Lo que lo hace evitable es la forma de los datos: de 1.112.274 filas, solo
     * las que nacieron de un pedido tienen `solicitud`. El resto es la copia del
     * SIA, donde cada fila **es** su propia solicitud y no hay nada que agrupar.
     * Así que las dos ramas van por caminos distintos:
     *
     * 1. **Los pedidos** se agrupan por `solicitud` —índice `(solicitud, fecha)`,
     *    y son un puñado—, y se traen enteros.
     * 2. **Las sueltas** salen ordenadas por el índice de `fecha`, sin agrupar y
     *    sin tabla temporal, pidiendo nada más que la ventana de esta página.
     * 3. Las dos se mezclan acá, en PHP.
     * 4. Recién entonces se busca la fila que abre cada clave de la página, con
     *    un `whereIn` acotado.
     *
     * El último paso **tiene que ir acotado**: la subconsulta de
     * `iniciosDeSolicitud()` suelta sobre la tabla entera se evalúa fila por fila
     * y con una búsqueda poco frecuente no vuelve. Ver la migración
     * `agregar_solicitud_a_licencias`.
     * ---
     *
     * @param  Builder  $filtrada  la consulta ya filtrada, sin ordenar ni paginar
     * @return LengthAwarePaginator<int, static>
     */
    public static function paginarPorSolicitud(Builder $filtrada, int $porPagina): LengthAwarePaginator
    {
        $pagina = max(1, (int) Paginator::resolveCurrentPage());
        $desde = ($pagina - 1) * $porPagina;

        $pedidos = static::pedidosAgrupados($filtrada);

        $claves = static::clavesDeLaPagina($filtrada, $pedidos, $desde, $porPagina);

        // Las filas se indexan **después** de juntarlas. Juntar dos colecciones
        // ya indexadas con `merge()` no sirve acá: por debajo es `array_merge`,
        // que renumera las claves enteras, y la clave de una licencia del SIA es
        // su `id`. Las del SIA quedaban bajo 0, 1, 2… y ninguna aparecía en
        // pantalla, aunque el total las siguiera contando.
        $inicios = static::aperturasDe($claves)->keyBy(
            fn (self $licencia): string => $licencia->clave_agrupadora
        );

        // El orden lo manda el primer paso; el segundo solo trae las filas.
        $filas = collect($claves)
            ->map(fn ($clave) => $inicios->get((string) $clave))
            ->filter()
            ->values();

        return new Paginador($filas, static::cuantasSolicitudes($filtrada, $pedidos), $porPagina, $pagina, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => 'page',
        ]);
    }

    /**
     * La fila que abre cada clave, con el turno ya cargado: el día más temprano
     * de cada pedido, y la fila misma en lo migrado del SIA, donde cada fila
     * **es** su propia licencia.
     *
     * Son dos consultas por índice en vez de una por expresión: los pedidos
     * salen por `(solicitud, fecha)` y las del SIA por su clave primaria.
     * Buscarlas todas juntas con un `COALESCE` obligaría a recorrer la tabla.
     *
     * @param  list<mixed>  $claves
     * @return Collection<int, static>
     */
    public static function aperturasDe(array $claves): Collection
    {
        [$conSolicitud, $delSia] = static::separarClaves($claves);

        $filas = collect();

        if ($conSolicitud !== []) {
            $filas = static::iniciosDe($conSolicitud);
        }

        if ($delSia !== []) {
            $filas = $filas->concat(static::query()->with('turno')->whereKey($delSia)->get());
        }

        return $filas;
    }

    /**
     * La fila que abre cada una de las solicitudes dadas: su día más temprano,
     * con el turno ya cargado.
     *
     * No usa `iniciosDeSolicitud()`, y la razón está medida. Ese scope compara
     * el `id` de la fila contra un subquery correlacionado que termina en
     * `ORDER BY s.fecha, s.id LIMIT 1`, y MySQL lo resuelve **recorriendo el
     * índice de `fecha` entero** —1.112.274 entradas, 1,7 s— en vez de ir por
     * `(solicitud, fecha)`: cree que caminando por fecha va a toparse con la
     * fila enseguida y así se ahorra el orden, pero las solicitudes son lo
     * último que se cargó y aparecen recién al final del recorrido.
     *
     * Traer los días de un puñado de solicitudes y quedarse con el primero de
     * cada una es un rango sobre `(solicitud, fecha)`: 1 ms. Son pocas filas
     * —los días de las diez solicitudes de la página— y el orden lo pone el
     * mismo índice.
     *
     * @param  list<string>  $solicitudes
     * @return Collection<int, static>
     */
    private static function iniciosDe(array $solicitudes): Collection
    {
        return static::query()
            ->with('turno')
            ->whereIn('solicitud', $solicitudes)
            ->orderBy('solicitud')
            ->orderBy('fecha')
            ->orderBy('id')
            ->get()
            ->groupBy('solicitud')
            ->map(fn (Collection $dias): self => $dias->first())
            ->values();
    }

    /**
     * Las solicitudes de verdad —las que nacieron de un pedido— con su día más
     * reciente, ya ordenadas.
     *
     * Se traen **enteras** y no de a una página: son las únicas que abarcan
     * varios días, salen por el índice `(solicitud, fecha)` y hoy son un puñado
     * contra el millón de filas migradas. Mezclarlas después en PHP es lo que
     * permite que la otra rama, la grande, no tenga que agruparse nunca.
     *
     * @return Collection<int, static>
     */
    private static function pedidosAgrupados(Builder $filtrada): Collection
    {
        return (clone $filtrada)
            ->whereNotNull('solicitud')
            ->selectRaw('solicitud as clave, MAX(fecha) as ultima')
            ->groupBy('solicitud')
            ->get()
            ->sortByDesc(static fn (self $fila): string => static::ordenDe($fila))
            ->values();
    }

    /**
     * Cuántas filas del SIA cruzan el filtro, que son las que valen una
     * solicitud cada una.
     *
     * Se calcula **restando**, y no preguntando por `solicitud IS NULL`: ese
     * predicado manda al optimizador al índice `(solicitud, fecha)`, que guarda
     * un `char(26)` por fila, y contar el millón por ahí tarda 1,8 s. Contar la
     * tabla filtrada entera va por un índice angosto —250 ms— y lo que hay que
     * descontarle son las filas con pedido, que se cuentan por rango sobre ese
     * mismo índice y son cuatro.
     */
    private static function cuantasSolicitudes(Builder $filtrada, Collection $pedidos): int
    {
        $sueltas = static::cuantasFilas($filtrada)
            - (clone $filtrada)->whereNotNull('solicitud')->count();

        return max(0, $sueltas) + $pedidos->count();
    }

    /**
     * Cuántas filas sueltas hay **hasta donde importa**, no en toda la tabla.
     *
     * El `LIMIT` es lo que la hace barata: contar cuesta lo que la página
     * necesita y no lo que mide la tabla. El número exacto solo hace falta
     * cuando las sueltas se acabaron antes de esta página, y en ese caso son
     * menos que el tope y el conteo acotado da el número entero igual.
     *
     * No se reusa el conteo cacheado de {@see self::cuantasFilas()}: ese puede
     * venir de hace unos minutos y acá se lo restaría contra una cantidad de
     * pedidos recién leída, y dos números de momentos distintos dan un
     * corrimiento que saltea o repite filas.
     */
    private static function sueltasHasta(Builder $filtrada, int $tope): int
    {
        $acotada = (clone $filtrada)
            ->whereNull('solicitud')
            ->select(DB::raw('1'))
            ->limit($tope)
            ->toBase();

        return DB::query()->fromSub($acotada, 'acotada')->count();
    }

    /**
     * Cuántas filas cruzan el filtro, cacheado unos minutos.
     *
     * Contar un millón de filas cuesta lo que cuesta y no hay índice que lo
     * evite: 250 ms sin filtro, y 1,9 s filtrando por estado, donde el
     * optimizador se va al índice `(estado, fecha)` —un `varchar(12)` por
     * entrada— porque hay una igualdad, aunque ese estado sea el de casi todas
     * las filas.
     *
     * Lo que se cachea es **el total, no las filas**: el listado sigue saliendo
     * de la base en cada carga y lo único que puede quedar unos minutos viejo es
     * la cantidad de páginas. Por eso el TTL es corto y no hace falta invalidarlo
     * al anotar una licencia: el pedido nuevo se ve igual, arriba de todo.
     *
     * La clave sale de la consulta ya armada, así que cada combinación de
     * filtros y de búsqueda cuenta la suya.
     */
    private static function cuantasFilas(Builder $filtrada): int
    {
        $consulta = clone $filtrada;
        $clave = 'licencias.conteo.'.sha1($consulta->toSql().'|'.serialize($consulta->getBindings()));

        return Cache::remember(
            $clave,
            now()->addMinutes(self::CACHE_CONTEO_MINUTOS),
            fn (): int => (clone $filtrada)->count(),
        );
    }

    /**
     * Las claves de esta página, mezclando las dos ramas.
     *
     * De la rama suelta se pide **solo la ventana necesaria**. Traer todo hasta
     * la página pedida sería cargar el millón de filas en memoria cuando alguien
     * hace clic en el último número de la paginación.
     *
     * La ventana se corre `$pedidos` lugares hacia atrás porque esa es la
     * cantidad máxima de filas que la otra rama puede intercalar por delante:
     * más atrás que eso, ningún pedido puede empujar a una suelta.
     *
     * @param  Collection<int, static>  $pedidos
     * @return list<mixed>
     */
    private static function clavesDeLaPagina(Builder $filtrada, Collection $pedidos, int $desde, int $porPagina): array
    {
        $cuantosPedidos = $pedidos->count();
        $ventanaDesde = max(0, $desde - $cuantosPedidos);

        $deLaVentana = (clone $filtrada)
            ->whereNull('solicitud')
            ->selectRaw('id as clave, fecha as ultima')
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->offset($ventanaDesde)
            ->limit($desde + $porPagina - $ventanaDesde)
            ->get();

        // La página empieza después de la última fila suelta: lo que queda son
        // pedidos, y son los últimos de la lista ya ordenada.
        if ($deLaVentana->isEmpty()) {
            $sueltas = static::sueltasHasta($filtrada, $desde + $porPagina);

            return $pedidos->slice(max(0, $desde - $sueltas), $porPagina)->pluck('clave')->all();
        }

        // Ventana desde cero: no falta ninguna fila por delante, así que la
        // mezcla **es** el listado y se corta en el lugar pedido. Descartar acá
        // los pedidos que van arriba de la primera suelta —como sí hay que
        // hacer más adelante— los borraría de la página uno.
        if ($ventanaDesde === 0) {
            return static::mezclar($pedidos, $deLaVentana)
                ->slice($desde, $porPagina)
                ->pluck('clave')
                ->all();
        }

        // De acá en más sí falta lo de antes de la ventana: las sueltas que se
        // saltearon y los pedidos que van por delante de ellas. Esos pedidos ya
        // salieron en páginas anteriores, así que no se mezclan, pero sí se
        // cuentan: son los lugares que corren a la ventana dentro del listado
        // completo, y sin ese corrimiento la página repetiría filas.
        $corte = static::ordenDe($deLaVentana->first());
        $porDelante = $pedidos->filter(fn (self $p): bool => static::ordenDe($p) > $corte)->count();

        return static::mezclar($pedidos->reject(fn (self $p): bool => static::ordenDe($p) > $corte), $deLaVentana)
            ->slice($desde - $ventanaDesde - $porDelante, $porPagina)
            ->pluck('clave')
            ->all();
    }

    /**
     * Las dos ramas en una sola lista, en el orden del listado.
     *
     * @param  Collection<int, static>  $pedidos
     * @param  Collection<int, static>  $sueltas
     * @return Collection<int, static>
     */
    private static function mezclar(Collection $pedidos, Collection $sueltas): Collection
    {
        return $pedidos
            ->concat($sueltas)
            ->sortByDesc(static fn (self $fila): string => static::ordenDe($fila))
            ->values();
    }

    /**
     * Cómo se ordena una fila del listado: el día más reciente primero y, a
     * igualdad de día, la clave.
     *
     * El desempate no es un detalle: sin él, dos filas del mismo día quedan en
     * el orden que devuelva MySQL, que puede cambiar entre una página y la
     * siguiente y hacer que una licencia aparezca dos veces o ninguna.
     *
     * El `id` se rellena a los 26 caracteres de una `solicitud` para que
     * comparar los dos como texto dé el mismo orden que compararlos como
     * números, que es el que pidió la consulta.
     */
    private static function ordenDe(self $fila): string
    {
        return ((string) $fila->ultima).'|'.str_pad((string) $fila->clave, 26, '0', STR_PAD_LEFT);
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

        [$conSolicitud, $delSia] = static::separarClaves($claves->all());

        $resumen = collect();

        if ($conSolicitud !== []) {
            $resumen = static::query()
                ->whereIn('solicitud', $conSolicitud)
                ->selectRaw('solicitud as clave, MAX(fecha) as hasta, COUNT(*) as dias, COUNT(DISTINCT estado) as estados')
                ->groupBy('solicitud')
                ->get();
        }

        // Una fila del SIA es su propia licencia: un día y un solo estado.
        if ($delSia !== []) {
            $resumen = $resumen->concat(
                static::query()->whereKey($delSia)
                    ->selectRaw('id as clave, fecha as hasta, 1 as dias, 1 as estados')
                    ->get()
            );
        }

        // Indexar al final y no antes, por lo mismo que en `paginarPorSolicitud`:
        // `merge()` sobre claves enteras las renumera y el resumen del SIA se
        // perdía, dejando el período y la cantidad de días en blanco.
        return $resumen->keyBy(fn (self $fila): string => (string) $fila->clave);
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
        return (int) static::query()->pendientes()
            ->selectRaw('COUNT(DISTINCT '.self::CLAVE_AGRUPADORA.') as total')
            ->value('total');
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
     * Etiqueta legible del turno licenciado: «MIE: 08:00 – 16:00».
     *
     * Los turnos que vienen del SIA **ya se llaman con su horario** —el
     * `nombreTurno` es literalmente «MIE: 08:00 - 16:00»—, así que pegarle las
     * horas otra vez daba «MIE: 08:00 - 16:00: 08:00 – 16:00». Si el nombre ya
     * trae una hora adentro se lo usa tal cual; si no, se le agrega el horario,
     * que es lo que hace falta para los turnos con nombre propio.
     */
    public function getResumenTurnoAttribute(): string
    {
        $turno = $this->turno;

        if (! $turno instanceof Turno) {
            return '—';
        }

        $nombre = trim((string) $turno->nombreTurno);
        $horario = ($turno->hEntrada?->format('H:i') ?? '—').' – '
            .($turno->hSalida?->format('H:i') ?? '—');

        if ($nombre === '') {
            return $horario;
        }

        // ¿El nombre ya dice la hora? Basta con encontrar un «08:00» adentro.
        return preg_match('/\d{1,2}:\d{2}/', $nombre) === 1
            ? $nombre
            : "{$nombre}: {$horario}";
    }

    /**
     * Qué parte del turno cubre la licencia de **este día**.
     *
     * Cada fila es un día y puede tener su propio alcance: el alta expande el
     * rango, pero nada obliga a que todos los días se pidan iguales. Por eso se
     * lee de la fila y no de la solicitud.
     *
     * `null` cuando cubre el turno entero, para que quien la muestre decida cómo
     * decirlo.
     */
    public function getAlcanceDelDiaAttribute(): ?string
    {
        if ($this->tCompleto) {
            return null;
        }

        $entra = $this->lEntra?->format('H:i');
        $sale = $this->lSale?->format('H:i');

        // Sin horas cargadas no se puede afirmar qué parte cubre. Es el caso de
        // lo migrado del SIA que vino marcado como parcial y sin `LEntra`.
        if ($entra === null && $sale === null) {
            return 'Sin horario cargado';
        }

        return ($entra ?? '—').' – '.($sale ?? '—');
    }
}
