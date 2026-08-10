<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Api\AsistenciaFuncionarioController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Valida la clave de la API de asistencia, que consumen los sistemas externos
 * (hoy Mamoré, para que cada funcionario vea sus propias marcaciones).
 *
 * El consumidor manda la clave en el header:
 *   X-API-KEY: <clave>
 * o como Bearer token:
 *   Authorization: Bearer <clave>
 *
 * Se define en `.env` como SISMARK_API_KEY. Es el mismo mecanismo que usa
 * Mamoré para su API de datos personales, a propósito: los dos sistemas se
 * autentican igual en las dos direcciones.
 *
 * Autentica al **sistema**, no a la persona. Quién es el funcionario lo decide
 * el consumidor a partir de su propia sesión; ver la advertencia en
 * {@see AsistenciaFuncionarioController}.
 */
class VerifyApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.sismark_api.key');

        // Sin clave configurada no se atiende a nadie: un servidor recién
        // desplegado y sin configurar no puede quedar abierto.
        if (empty($expected)) {
            return response()->json([
                'message' => 'API key no configurada en el servidor.',
            ], 503);
        }

        $provided = $request->header('X-API-KEY') ?: $request->bearerToken();

        // Comparación en tiempo constante, para no filtrar la clave por el
        // tiempo que tarda en fallar.
        if (empty($provided) || ! hash_equals($expected, $provided)) {
            return response()->json([
                'message' => 'No autorizado. API key inválida o ausente.',
            ], 401);
        }

        return $next($request);
    }
}
