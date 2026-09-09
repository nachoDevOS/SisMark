<?php

use App\Exceptions\MamoreException;
use App\Services\MamoreClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Un corte de conexión con Mamoré se reintenta una vez
|--------------------------------------------------------------------------
|
| El caso real: dos pedidos nuestros que se pisan —el catálogo del combo del
| reporte por dirección y el reporte en sí— y encuentran al servidor de Mamoré
| atendiendo el otro. El segundo volvía como «No se pudo conectar con la API de
| Mamoré» sin que hubiera nada roto, y el aviso quedaba en pantalla al lado de
| una dirección perfectamente seleccionada.
|
| Lo que **no** se reintenta es una respuesta con error: un 429 reintentado
| gastaría cuota contra el límite que justamente acaba de avisar, y un 401 va a
| fallar igual la segunda vez.
|
*/

/**
 * Configura la API sin falsear ningún endpoint, para que cada prueba arme el
 * suyo.
 */
function configurarMamore(): void
{
    config()->set('services.mamore.url', 'http://mamore.test/api/externo/personal');
    config()->set('services.mamore.token', 'secreta');
    config()->set('services.mamore.origen', 'http://sismark.test');
}

test('un corte de conexión se reintenta y la segunda vez sale bien', function () {
    configurarMamore();

    Http::fake(['mamore.test/*' => Http::sequence()
        ->pushFailedConnection()
        ->push(['data' => ['direcciones' => [['id' => 7, 'nombre' => 'RRHH', 'sigla' => 'RRHH']], 'unidades' => []]]),
    ]);

    $catalogos = app(MamoreClient::class)->catalogos(conContratos: true);

    expect($catalogos['direcciones'])->toHaveCount(1);
    Http::assertSentCount(2);
});

test('si el corte se repite, el error sube como MamoreException', function () {
    // No se insiste para siempre: el reporte prefiere no emitirse antes que
    // procesar sobre contratos que no se pudieron verificar.
    configurarMamore();

    // Los intentos se cuentan a mano: el fake no registra como «enviado» el
    // pedido que termina en excepción de conexión, así que `assertSentCount()`
    // daría cero.
    $intentos = 0;

    Http::fake(['mamore.test/*' => function () use (&$intentos) {
        $intentos++;

        throw new ConnectionException('sin ruta');
    }]);

    expect(fn () => app(MamoreClient::class)->catalogos())
        ->toThrow(MamoreException::class, 'No se pudo conectar con la API de Mamoré.');

    expect($intentos)->toBe(2);
});

test('una respuesta con error no se reintenta', function () {
    // Reintentar un 429 gastaría cuota contra el límite que acaba de avisar.
    configurarMamore();

    Http::fake(['mamore.test/*' => Http::response(['message' => 'demasiados'], 429)]);

    expect(fn () => app(MamoreClient::class)->catalogos())->toThrow(MamoreException::class);

    Http::assertSentCount(1);
});

test('un 401 sigue traduciéndose a su mensaje propio y no a una excepción de Laravel', function () {
    // `retry()` convierte por defecto toda respuesta fallida en RequestException,
    // que se comía los mensajes propios del cliente. Por eso va con `throw: false`.
    configurarMamore();

    Http::fake(['mamore.test/*' => Http::response(['message' => 'no'], 401)]);

    expect(fn () => app(MamoreClient::class)->catalogos())
        ->toThrow(MamoreException::class, 'La clave de la API de Mamoré es inválida.');
});
