<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TurnoSugeridoResource;
use App\Models\Turno;
use Illuminate\Http\JsonResponse;

/**
 * El horario sugerido de la institución, para los sistemas que dan de alta un
 * contrato y necesitan proponer un turno sin conocer las 757 filas de `turnos`.
 *
 * Hoy la consume Mamoré al crear un contrato: muestra la sugerencia y, si se
 * acepta, la manda de vuelta a {@see AsignacionTurnoApiController}.
 *
 * Es el primer endpoint de esta API que **no cuelga de una cédula**: no es un
 * dato de una persona sino un catálogo. Va con la misma clave compartida y el
 * mismo limitador que el resto.
 */
class TurnoSugeridoController extends Controller
{
    /**
     * Los horarios marcados como sugeridos, agrupados en horarios semanales.
     *
     * Devuelve una lista y no un único elemento aunque hoy haya una sola
     * sugerencia: nada impide que Recursos Humanos marque un segundo horario
     * —el continuo y el de verano, por ejemplo— y el consumidor no tendría que
     * cambiar para soportarlo.
     *
     * Sin ningún turno marcado contesta `data: []` y no un error: es un estado
     * legítimo —nadie eligió todavía— y el consumidor tiene que poder seguir
     * dando de alta el contrato sin sugerencia.
     */
    public function index(): JsonResponse
    {
        $sugeridos = Turno::query()->sugeridos()->get();

        return response()->json([
            'data' => TurnoSugeridoResource::agrupar($sugeridos)->map->toArray(request())->all(),
        ]);
    }
}
