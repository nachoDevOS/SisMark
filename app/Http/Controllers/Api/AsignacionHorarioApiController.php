<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\HorarioAsignadoResource;
use App\Models\AsignacionHorario;
use App\Services\ProcesadorAsistencia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * El horario asignado de un funcionario, para que lo vea desde el sistema del
 * consumidor (hoy Mamoré).
 *
 * Solo lectura. La escritura ya no es por horario sino por turno
 * ({@see AsignacionTurnoApiController}); sus horarios día por día son los que se
 * leen acá, junto con lo heredado del sistema anterior.
 *
 * **La cédula de la URL no autoriza nada.** Igual que en el resto de esta API,
 * la clave identifica al *sistema*, no a la persona. Ver la advertencia en
 * {@see AsistenciaFuncionarioController}.
 */
class AsignacionHorarioApiController extends Controller
{
    /**
     * El horario asignado al funcionario, agrupado por período de vigencia.
     *
     * Es el único método de lectura del controlador y va con `horarios:read`, no
     * con `horarios:write`: mostrarle a alguien su propio horario no es asignarlo.
     *
     * **No recibe rango de fechas**, a diferencia del resto de la API. Una
     * asignación no es un dato de un día sino un período propio, y recortarla
     * contra un mes le mostraría al funcionario «desde el 1 hasta el 31» cuando
     * su horario rige todo el año. Se devuelve lo que está en pie y lo que todavía
     * no empezó; lo ya vencido solo si lo piden con `vencidas=1`, porque es
     * historial y en una carrera larga tapa lo que la persona viene a mirar.
     *
     * Lista vacía no es un error: un funcionario sin horario asignado es
     * exactamente lo que hay que poder ver —sin horario,
     * {@see ProcesadorAsistencia} resuelve todos sus días como «no
     * laborable» y esa persona queda sin control de asistencia—.
     */
    public function index(Request $request, string $ci): JsonResponse
    {
        $request->validate([
            'vencidas' => ['nullable', 'boolean'],
        ]);

        $ci = trim($ci);

        if ($ci === '') {
            return response()->json(['message' => 'La cédula es obligatoria.'], 422);
        }

        $asignaciones = AsignacionHorario::query()
            ->delFuncionario($ci, $request->boolean('vencidas'))
            ->with('asignacionTurno.turno')
            ->get();

        return response()->json([
            'data' => HorarioAsignadoResource::agrupar($asignaciones)->map->toArray($request)->all(),
        ]);
    }
}
