<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * Quien fue inhabilitado con la sesión abierta (o con «Recordarme») queda
 * afuera en la próxima pantalla que pida. Se lanza la misma excepción que una
 * sesión vencida, así una llamada AJAX recibe el 401 de siempre y la pantalla
 * se recarga hacia el ingreso, donde ve el aviso.
 */
class ExpulsarUsuarioInhabilitado
{
    public const AVISO = 'Tu cuenta está inhabilitada. Consultá con el administrador del sistema.';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() === null || $request->user()->activo) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->flash('errors', (new ViewErrorBag)->put('default', new MessageBag(['email' => self::AVISO])));

        throw new AuthenticationException;
    }
}
