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
 * Consulta la lista y el detalle de personas con el header X-API-KEY.
 *
 * La API tiene un único listado: `/people` devuelve todas las personas, y cada
 * una trae su contrato firmado en la clave `contrato` (o `null` si no tiene).
 */
class MamoreClient
{
    /**
     * ¿Están cargadas la URL y la clave para poder consultar la API?
     */
    public function configurado(): bool
    {
        return filled(config('services.mamore.url')) && filled(config('services.mamore.key'));
    }

    /**
     * Lista paginada de personas (con búsqueda). Devuelve el JSON tal cual
     * (`data`, `meta`, `links`).
     *
     * El filtro `$contrato` («todos», «con» o «sin») lo resuelve la propia API
     * con el parámetro `?contrato=`, así que la paginación sigue siendo la suya.
     *
     * @return array{data?: array<int, array<string, mixed>>, meta?: array<string, mixed>, links?: array<string, mixed>}
     */
    public function people(int $page, int $limit, string $search = '', string $contrato = 'todos'): array
    {
        $parametros = ['page' => $page, 'limit' => $limit];

        if ($search !== '') {
            $parametros['search'] = $search;
        }

        if (in_array($contrato, ['con', 'sin'], true)) {
            $parametros['contrato'] = $contrato;
        }

        return $this->pedir('/people', $parametros);
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
            throw new MamoreException('La API de Mamoré no está configurada (MAMORE_API_URL / MAMORE_API_KEY en el .env).');
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
            throw new MamoreException('La API de Mamoré no está configurada (MAMORE_API_URL / MAMORE_API_KEY en el .env).');
        }

        return $this->configurar(Http::createPendingRequest());
    }

    /**
     * URL, clave y tiempo de espera de la API. Lo comparten el pedido suelto y
     * cada pedido del pool, que no puede salir del mismo `http()`.
     */
    private function configurar(PendingRequest $peticion): PendingRequest
    {
        return $peticion
            ->baseUrl(rtrim((string) config('services.mamore.url'), '/'))
            ->withHeaders(['X-API-KEY' => config('services.mamore.key')])
            ->acceptJson()
            ->timeout(10);
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
