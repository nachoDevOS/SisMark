<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Quien entra con una contraseña que le dio otro (alta o reseteo desde
 * Usuarios) no puede usar el sistema hasta poner una propia: cualquier pantalla
 * lo devuelve a la de cambio. Solo quedan libres esa pantalla y cerrar sesión.
 */
class ExigirCambioPassword
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->cambiar_password || $request->routeIs('password.cambiar*', 'logout')) {
            return $next($request);
        }

        return redirect()->route('password.cambiar');
    }
}
