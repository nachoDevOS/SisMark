<?php

namespace App\Http\Middleware;

use App\Services\RegistroActividad;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Registra cada pedido en la actividad de usuarios (docs/ACTIVIDAD-DE-USUARIOS.md).
 *
 * Global: pantallas, AJAX y API, con o sin sesión. Escribe en `terminate()`,
 * cuando la respuesta ya salió, así nadie espera por el registro.
 */
class RegistrarActividad
{
    /** Cuándo empezó el pedido, para medir cuánto tardó. */
    private const INICIO = 'actividad.inicio';

    /**
     * Lo que no se registra: el chequeo de salud que Coolify pide cada pocos
     * segundos (sin esto la tabla se llenaría de «/up» y nada más) y la propia
     * pantalla de actividad, que se anotaría a sí misma con cada filtro.
     *
     * @var list<string>
     */
    private const IGNORADAS = ['up', 'actividades'];

    public function __construct(private RegistroActividad $registro) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(self::INICIO, hrtime(true));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (in_array($request->path(), self::IGNORADAS, true)) {
            return;
        }

        $inicio = $request->attributes->get(self::INICIO, hrtime(true));

        $this->registro->registrarPedido(
            $request,
            $response->getStatusCode(),
            (int) round((hrtime(true) - $inicio) / 1_000_000),
        );
    }
}
