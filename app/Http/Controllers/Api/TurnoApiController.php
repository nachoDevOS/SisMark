<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TurnoApiResource;
use App\Models\Turno;
use Illuminate\Http\JsonResponse;

/**
 * Los turnos que se ofrecen al dar de alta un contrato: los marcados como
 * sugeridos. «Sugerido» es justamente eso, que se ofrece del otro lado.
 *
 * Hoy lo consume Mamoré al crear un contrato: muestra estos turnos para elegir
 * la jornada y manda el `turnoId` elegido a {@see AsignacionTurnoApiController}.
 *
 * Es un catálogo y no el dato de una persona, por eso no cuelga de una cédula.
 */
class TurnoApiController extends Controller
{
    /**
     * Los turnos sugeridos con sus horarios.
     *
     * Sin ninguno contesta `data: []` y no un error: es un estado legítimo
     * —nadie marcó turnos todavía— y el consumidor decide qué hacer.
     */
    public function index(): JsonResponse
    {
        $turnos = Turno::query()->sugeridos()->with('horarios')->get();

        return response()->json([
            'data' => TurnoApiResource::collection($turnos)->resolve(request()),
        ]);
    }
}
