<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

/**
 * ¿La petición la hizo un script y no la barra de direcciones?
 *
 * Importa cuando vence la sesión. A una visita normal hay que redirigirla al
 * login, pero a un `fetch` no: `fetch` sigue la redirección sin avisar, y la
 * pantalla de ingreso termina dibujada **adentro** de la tabla que se estaba
 * cargando —el formulario donde iban los datos, con el sidebar y el nombre del
 * usuario todavía arriba—. Parece un error del listado y no lo es.
 *
 * Las diecisiete llamadas del sistema no se anuncian todas igual: nueve mandan
 * `X-Requested-With`, tres piden `Accept: application/json` y las que traen una
 * tabla ya armada piden `Accept: text/html`, que es indistinguible de una
 * visita. Por eso el tercer criterio es `Sec-Fetch-Dest`, que el propio
 * navegador pone en cada pedido: `document` cuando navega y `empty` cuando el
 * pedido sale de un script. Si el navegador es viejo y no manda la cabecera,
 * se cae en la redirección de siempre, que es el comportamiento seguro.
 *
 * Va como closure y no como función con nombre porque este archivo se evalúa
 * una vez por aplicación levantada, y la suite levanta cientos.
 */
$esSegundoPlano = static fn (Request $request): bool => $request->ajax()
    || $request->expectsJson()
    || $request->header('Sec-Fetch-Dest') === 'empty';

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // API para los sistemas externos (hoy Mamoré). Va sin sesión ni CSRF:
        // se autentica con un token de Sanctum emitido sobre un
        // `App\Models\SistemaExterno`, y el prefijo `api/` es el que ya activa
        // las respuestas JSON de errores más abajo.
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // `abilities` viene de Sanctum y no se registra solo: exige que el token
        // traiga **todos** los alcances que pide la ruta. Los alcances están
        // declarados en `SistemaExterno::ALCANCES`.
        $middleware->alias([
            'abilities' => CheckAbilities::class,
        ]);

        // Los invitados van al login propio del sitio (routes/web.php).
        $middleware->redirectGuestsTo(fn () => route('login'));

        // El HTTPS lo termina quien está adelante: un túnel local (cloudflared,
        // ngrok) en desarrollo, que llega por loopback, o Traefik en Coolify,
        // que llega desde la red privada de Docker. Se confía solo en esos
        // orígenes, nunca en internet. Sin esto Laravel no ve el
        // `X-Forwarded-Proto: https` y arma las URLs en http, y el navegador
        // bloquea las llamadas AJAX de los listados por contenido mixto.
        //
        // Los rangos privados son los mismos en los que confiaba NGINX Unit
        // (su bloque `forwarded`); con FrankenPHP la decisión es de Laravel.
        $middleware->trustProxies(at: [
            '127.0.0.1', '::1',
            '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) use ($esSegundoPlano): void {
        // Ojo: este callback **reemplaza** al `expectsJson()` que Laravel usa
        // por defecto. Todo lo que tenga que contestar JSON va acá adentro.
        //
        // De los errores, solo cambia el de autenticación: los demás siguen
        // devolviendo su página HTML como hasta ahora.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e) => $request->is('api/*')
                || ($e instanceof AuthenticationException && $esSegundoPlano($request)),
        );
    })->create();
