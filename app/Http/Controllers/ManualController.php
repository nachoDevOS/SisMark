<?php

namespace App\Http\Controllers;

use App\Services\ManualUsuario;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Ayuda: entrega el video, la portada y la vista previa de cada tutorial. Se
 * sirven con sesión y no desde public/ porque muestran el sistema por dentro.
 */
class ManualController extends Controller
{
    /**
     * Video, portada o vista previa — GET /manual/{tutorial}.{extension}
     */
    public function archivo(Request $request, string $tutorial, string $extension): BinaryFileResponse
    {
        abort_unless(ManualUsuario::puedeVer($request->user(), $tutorial), 404);

        $archivo = ManualUsuario::archivo($tutorial, $extension);
        abort_unless(is_file($archivo), 404);

        // BinaryFileResponse responde los pedidos por rango (206): sin eso el
        // reproductor no puede saltar a un paso del video.
        return response()->file($archivo, ['Cache-Control' => 'private, max-age=86400']);
    }
}
