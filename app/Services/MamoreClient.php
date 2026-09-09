<?php

namespace App\Services;

use App\Exceptions\MamoreException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Cliente HTTP de la API externa de Datos Personales «Mamoré» (solo lectura).
 *
 * La API tiene un único listado: `/people` devuelve todas las personas, y cada
 * una trae su contrato firmado en la clave `contrato` (o `null` si no tiene).
 *
 * ---
 * **Autenticación: token de Sanctum que emite Mamoré**, en `MAMORE_TOKEN`.
 * Se saca de su pantalla `/admin/tokens-api`, con el alcance `personal:read`.
 *
 * Antes era una clave compartida en `X-API-KEY`, la misma para todos los
 * consumidores de Mamoré: rotarla los cortaba a todos y no quedaba registro de
 * cuál pidió qué. Con el token, Mamoré nos puede cortar el acceso sin tocar a
 * los demás y cada consulta deja su fila del otro lado.
 *
 * **Va con `Origin`.** Mamoré compara esa cabecera contra el dominio con el que
 * registró el sistema y contesta 403 si falta. No es la puerta —el `Origin` se
 * forja— sino una baranda: atrapa el token pegado en el sistema equivocado, que
 * si no funcionaría perfecto y dejaría los logs mintiendo durante meses. Sale de
 * `MAMORE_ORIGIN`, o de `APP_URL` si no está.
 * ---
 */
class MamoreClient
{
    /**
     * ¿Están cargadas la URL y el token para poder consultar la API?
     */
    public function configurado(): bool
    {
        return filled(config('services.mamore.url')) && filled(config('services.mamore.token'));
    }

    /**
     * Lista paginada de personas (con búsqueda). Devuelve el JSON tal cual
     * (`data`, `meta`, `links`).
     *
     * El filtro `$contrato` («todos», «con» o «sin») lo resuelve la propia API
     * con el parámetro `?contrato=`, así que la paginación sigue siendo la suya.
     *
     * `$direccion` filtra por dirección administrativa. Con `$desde`/`$hasta`
     * ese filtro pasa a preguntar **quién estuvo ahí durante el rango** —cuenta
     * también los contratos concluidos— en vez de quién está hoy; sin fechas,
     * solo mira los firmados. Es la diferencia entre que el reporte por
     * dirección de un mes pasado incluya a quien se fue a mitad de período o lo
     * pierda en silencio.
     *
     * @return array{data?: array<int, array<string, mixed>>, meta?: array<string, mixed>, links?: array<string, mixed>}
     */
    public function people(
        int $page,
        int $limit,
        string $search = '',
        string $contrato = 'todos',
        ?int $direccion = null,
        ?string $desde = null,
        ?string $hasta = null,
        ?int $unidad = null,
    ): array {
        $parametros = ['page' => $page, 'limit' => $limit];

        if ($search !== '') {
            $parametros['search'] = $search;
        }

        if (in_array($contrato, ['con', 'sin'], true)) {
            $parametros['contrato'] = $contrato;
        }

        if ($direccion !== null) {
            $parametros['direccion'] = $direccion;
        }

        if ($unidad !== null) {
            $parametros['unidad'] = $unidad;
        }

        if ($desde !== null) {
            $parametros['desde'] = $desde;
        }

        if ($hasta !== null) {
            $parametros['hasta'] = $hasta;
        }

        return $this->pedir('/people', $parametros);
    }

    /**
     * Direcciones y unidades administrativas, para el combo del reporte por
     * dirección. Son pocas filas (70 direcciones, 452 unidades) y vienen juntas
     * en una sola petición.
     *
     * Con `$desde`/`$hasta` los conteos y el filtro `$conContratos` se calculan
     * sobre los contratos que tocan el rango, así el «(47)» del combo coincide
     * con las filas que después trae el reporte.
     *
     * @return array{direcciones: list<array<string, mixed>>, unidades: list<array<string, mixed>>}
     */
    public function catalogos(bool $activas = false, bool $conContratos = false, ?string $desde = null, ?string $hasta = null): array
    {
        $parametros = array_filter([
            'activas' => $activas ? 1 : null,
            'con_contratos' => $conContratos ? 1 : null,
            'desde' => $desde,
            'hasta' => $hasta,
        ], fn (mixed $valor): bool => $valor !== null);

        $respuesta = $this->pedir('/catalogos', $parametros);

        return [
            'direcciones' => $respuesta['data']['direcciones'] ?? [],
            'unidades' => $respuesta['data']['unidades'] ?? [],
        ];
    }

    /**
     * Detalle de una persona por su cédula. `null` si no existe (404).
     *
     * @return array<string, mixed>|null
     */
    public function personByCi(string $ci): ?array
    {
        try {
            $respuesta = $this->http()->get('/people/ci/'.rawurlencode($ci));
        } catch (ConnectionException) {
            throw new MamoreException('No se pudo conectar con la API de Mamoré.');
        }

        if ($respuesta->status() === 404) {
            return null;
        }

        if ($respuesta->failed()) {
            throw new MamoreException($this->motivo($respuesta->status()));
        }

        return $respuesta->json('data');
    }

    /**
     * Contratos de una persona: los firmados y los ya concluidos, en orden
     * cronológico.
     *
     * **No se cachea, a propósito.** De estos contratos depende que un día de
     * asistencia se procese o no, y procesar sobre una copia vieja significaría
     * marcar faltas en días que ya estaban cubiertos, o al revés. La ficha de
     * identidad sí se cachea un día —nombre, cargo y foto no cambian—, pero esto
     * se pregunta siempre.
     *
     * `$desde` y `$hasta` recortan del lado del servidor a los contratos que
     * tocan el rango, para no traer un historial de años cuando se reporta un mes.
     *
     * Devuelve lista vacía si la persona no está en Mamoré: es distinto de que
     * la API falle, que levanta {@see MamoreException}.
     *
     * @return list<array<string, mixed>>
     *
     * @throws MamoreException
     */
    public function contractsByCi(string $ci, ?string $desde = null, ?string $hasta = null): array
    {
        $parametros = array_filter([
            'desde' => $desde,
            'hasta' => $hasta,
        ], fn (?string $valor): bool => $valor !== null);

        try {
            $respuesta = $this->http()->get('/people/ci/'.rawurlencode($ci).'/contracts', $parametros);
        } catch (ConnectionException) {
            throw new MamoreException('No se pudo conectar con la API de Mamoré.');
        }

        if ($respuesta->status() === 404) {
            return [];
        }

        if ($respuesta->failed()) {
            throw new MamoreException($this->motivo($respuesta->status()));
        }

        return $respuesta->json('data') ?? [];
    }

    /**
     * Cédulas por pedido en el endpoint de a varios. Es el tope que impone
     * Mamoré; una dirección de la Gobernación no llega, pero el reporte pagina
     * igual para no depender de eso.
     */
    private const CIS_POR_LOTE = 500;

    /**
     * Los contratos de **varias** personas de una vez, agrupados por cédula.
     *
     * Misma forma que {@see contractsByCi} para cada persona, pero en una sola
     * petición: el reporte por dirección necesita los contratos de toda la
     * plantilla, y de a uno eran cientos de viajes en serie —medido del otro
     * lado, cerca de cuatro minutos de red para milisegundos de cálculo—.
     *
     * Las cédulas que Mamoré no conoce vuelven con lista vacía, no omitidas: no
     * es lo mismo «no tiene contratos» que «no vino en la respuesta».
     *
     * **No se cachea**, igual que el de a uno: de estos contratos depende que un
     * día de asistencia se procese, y una renovación cargada hoy tiene que
     * verse hoy.
     *
     * @param  list<string>  $cis
     * @return array<string, list<array<string, mixed>>>
     *
     * @throws MamoreException
     */
    public function contractsByCis(array $cis, ?string $desde = null, ?string $hasta = null): array
    {
        $cis = array_values(array_unique(array_filter(array_map(
            fn (string $ci): string => trim($ci),
            $cis
        ))));

        if ($cis === []) {
            return [];
        }

        $parametros = array_filter([
            'desde' => $desde,
            'hasta' => $hasta,
        ], fn (?string $valor): bool => $valor !== null);

        $porCi = [];

        foreach (array_chunk($cis, self::CIS_POR_LOTE) as $lote) {
            try {
                $respuesta = $this->http()->post('/people/contracts?'.http_build_query($parametros), ['ci' => $lote]);
            } catch (ConnectionException) {
                throw new MamoreException('No se pudo conectar con la API de Mamoré.');
            }

            if ($respuesta->failed()) {
                throw new MamoreException($this->motivo($respuesta->status()));
            }

            foreach ($respuesta->json('data') ?? [] as $ci => $contratos) {
                $porCi[(string) $ci] = $contratos;
            }
        }

        return $porCi;
    }

    /**
     * Pedidos en vuelo al mismo tiempo dentro de un pool.
     *
     * La API limita a 60 pedidos por minuto (cabecera `X-RateLimit-Limit`) y
     * responde 429 al pasarse. De a cinco se gana la mayor parte del paralelismo
     * sin vaciar la cuota de golpe y dejar sin nombre al resto de la pantalla.
     */
    private const PEDIDOS_EN_PARALELO = 5;

    /**
     * Detalle de varias personas por cédula, en paralelo.
     *
     * La API no tiene un endpoint por lote, así que sigue habiendo un pedido por
     * cédula. En serie, una página de 10 marcaciones con la caché fría tardaba
     * 2.461 ms —diez viajes de ~250 ms, uno atrás del otro—, y con 50 filas por
     * página el listado se quedaba en «Cargando…».
     *
     * Esto sirve para el puñado de cédulas que la caché no tiene; la pantalla no
     * debería depender de pedir el padrón entero fila por fila (son 4.599
     * personas contra un límite de 60 pedidos por minuto).
     *
     * Cada cédula vuelve con su resultado: `persona` es el detalle (null si la
     * API contestó 404) y `error` marca los pedidos que no llegaron a responder
     * —incluido el 429 del límite—, para que quien llama no confunda «no existe»
     * con «no se pudo preguntar».
     *
     * @param  list<string>  $cis
     * @return array<string, array{persona: ?array<string, mixed>, error: bool}>
     */
    public function personasPorCi(array $cis): array
    {
        if ($cis === []) {
            return [];
        }

        if (! $this->configurado()) {
            throw new MamoreException('La API de Mamoré no está configurada (MAMORE_URL / MAMORE_TOKEN en el .env).');
        }

        $respuestas = Http::pool(fn (Pool $pool): array => array_map(
            fn (string $ci) => $this->configurar($pool->as($ci))->get('/people/ci/'.rawurlencode($ci)),
            $cis
        ), self::PEDIDOS_EN_PARALELO);

        $resultados = [];

        foreach ($cis as $ci) {
            $resultados[$ci] = $this->resultadoDelPool($respuestas[$ci] ?? null);
        }

        return $resultados;
    }

    /**
     * Traduce una respuesta del pool. Un 404 es «no existe» (sin error); una
     * excepción de conexión o cualquier otro estado fallido es un error, y el
     * pool las devuelve como excepción en vez de como respuesta.
     *
     * @return array{persona: ?array<string, mixed>, error: bool}
     */
    private function resultadoDelPool(mixed $respuesta): array
    {
        if (! $respuesta instanceof Response) {
            return ['persona' => null, 'error' => true];
        }

        if ($respuesta->status() === 404) {
            return ['persona' => null, 'error' => false];
        }

        if ($respuesta->failed()) {
            return ['persona' => null, 'error' => true];
        }

        return ['persona' => $respuesta->json('data'), 'error' => false];
    }

    /**
     * GET a la API traduciendo fallos de red y estados de error a MamoreException.
     *
     * @param  array<string, mixed>  $parametros
     * @return array<string, mixed>
     */
    private function pedir(string $ruta, array $parametros): array
    {
        try {
            $respuesta = $this->http()->get($ruta, $parametros);
        } catch (ConnectionException) {
            throw new MamoreException('No se pudo conectar con la API de Mamoré.');
        }

        if ($respuesta->failed()) {
            throw new MamoreException($this->motivo($respuesta->status()));
        }

        return $respuesta->json();
    }

    private function http(): PendingRequest
    {
        if (! $this->configurado()) {
            throw new MamoreException('La API de Mamoré no está configurada (MAMORE_URL / MAMORE_TOKEN en el .env).');
        }

        return $this->configurar(Http::createPendingRequest());
    }

    /**
     * Intentos totales ante un corte de conexión, y la espera entre ellos.
     *
     * Un solo reintento alcanza para lo que se ve en la práctica: dos pedidos
     * nuestros que se pisan —el catálogo del combo y el reporte, por ejemplo— y
     * encuentran al servidor de Mamoré atendiendo el otro. El segundo vuelve
     * como «no se pudo conectar» sin que haya nada roto, y a los 250 ms sale
     * bien.
     *
     * **Solo se reintentan los cortes de conexión**, nunca una respuesta con
     * error: un 429 reintentado gastaría cuota contra el límite que justamente
     * acaba de avisar, y un 401 o un 403 van a fallar igual la segunda vez.
     */
    private const INTENTOS = 2;

    private const ESPERA_ENTRE_INTENTOS_MS = 250;

    /**
     * URL, clave y tiempo de espera de la API. Lo comparten el pedido suelto y
     * cada pedido del pool, que no puede salir del mismo `http()`.
     */
    private function configurar(PendingRequest $peticion): PendingRequest
    {
        return $peticion
            ->baseUrl(rtrim((string) config('services.mamore.url'), '/'))
            ->withToken((string) config('services.mamore.token'))
            // Mamoré exige `Origin` y lo compara con el dominio registrado; sin
            // esta cabecera contesta 403 «origen_requerido».
            ->withHeaders(['Origin' => $this->origen()])
            ->acceptJson()
            // `throw: false` es obligatorio: por defecto `retry()` convierte
            // cualquier respuesta fallida en excepción, y acá los estados de
            // error se leen con `failed()` para traducirlos a un mensaje propio
            // —«la clave es inválida», «Mamoré no tiene la suya configurada»—.
            ->retry(
                self::INTENTOS,
                self::ESPERA_ENTRE_INTENTOS_MS,
                fn (\Throwable $e): bool => $e instanceof ConnectionException,
                throw: false,
            )
            ->timeout(10);
    }

    /**
     * El dominio con el que Mamoré tiene registrado a SisMark.
     *
     * Se declara aparte de `APP_URL` porque no siempre coinciden: el sistema
     * puede servirse detrás de un túnel o de un dominio interno distinto del que
     * Recursos Humanos cargó en la ficha del sistema. Si no se declara, se cae en
     * `APP_URL`, que es lo correcto en el caso normal.
     */
    private function origen(): string
    {
        return (string) (config('services.mamore.origen') ?: config('app.url'));
    }

    private function motivo(int $status): string
    {
        return match ($status) {
            401 => 'La clave de la API de Mamoré es inválida.',
            503 => 'La API de Mamoré no tiene la clave configurada en su servidor.',
            default => "La API de Mamoré respondió con un error ({$status}).",
        };
    }
}
