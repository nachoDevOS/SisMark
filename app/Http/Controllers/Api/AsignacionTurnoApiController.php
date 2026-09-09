<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AsignacionTurno;
use App\Models\Turno;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Asignación de turno que llega de un sistema externo al dar de alta un
 * contrato.
 *
 * Hoy la usa Mamoré: cuando Recursos Humanos registra un contrato, el horario
 * sugerido ({@see TurnoSugeridoController}) queda asignado en SisMark sin que
 * nadie lo vuelva a cargar a mano de este lado. El rango de la asignación es el
 * del contrato, que es exactamente el período en que esa persona debe cumplir
 * una jornada.
 *
 * ---
 * **Por qué esta escritura sí surte efecto sola.**
 *
 * La otra escritura de esta API —la solicitud de licencia— nace «Pendiente» a
 * propósito, porque una licencia *justifica una ausencia*: si surtiera efecto
 * sola, cualquiera podría borrarse una falta. Asignar un turno es lo contrario.
 * Un turno **crea** la obligación de marcar; sin él, `ProcesadorAsistencia`
 * resuelve el día como «no laborable» y no controla nada. Una asignación
 * indebida no le borra una falta a nadie: se la inventa, y eso se ve en el
 * reporte del funcionario el mismo día.
 *
 * El riesgo real es el inverso —que el contrato quede sin turno y esos días no
 * se controlen—, así que acá conviene que se registre solo.
 * ---
 *
 * **La cédula de la URL no autoriza nada.** Igual que en el resto de esta API,
 * la clave identifica al *sistema*, no a la persona. Ver la advertencia en
 * {@see AsistenciaFuncionarioController}.
 */
class AsignacionTurnoApiController extends Controller
{
    /**
     * Asigna al funcionario los turnos indicados durante el rango del contrato.
     *
     * **Es idempotente.** La tabla tiene única `(ci, idTurno, desde)`, y acá se
     * respeta esa misma terna: reintentar la petición —porque el contrato se
     * guardó dos veces, o porque la red cortó la respuesta y el consumidor
     * reintentó— no duplica ni pisa nada. Lo ya asignado se informa aparte, en
     * `omitidos`, para que del otro lado se distinga «ya estaba» de «se creó».
     */
    public function store(Request $request, string $ci): JsonResponse
    {
        $datos = $request->validate([
            'desde' => ['required', 'date'],
            'hasta' => ['required', 'date', 'after_or_equal:desde'],
            // Opcional a propósito: sin lista, se asignan los turnos que SisMark
            // tenga marcados como sugeridos. Así el consumidor puede limitarse a
            // mandar la cédula y las fechas del contrato, sin llevar cuenta de
            // qué turnos son los buenos.
            'turnoIds' => ['nullable', 'array'],
            'turnoIds.*' => ['integer'],
            'observacion' => ['nullable', 'string', 'max:255'],
        ], [
            'hasta.after_or_equal' => 'La fecha «Hasta» no puede ser anterior a «Desde».',
        ]);

        $ci = trim($ci);
        $desde = Carbon::parse($datos['desde'])->startOfDay();
        $hasta = Carbon::parse($datos['hasta'])->startOfDay();

        $turnos = isset($datos['turnoIds'])
            ? Turno::query()->whereIn('id', $datos['turnoIds'])->orderBy('dia')->get()
            : Turno::query()->sugeridos()->get();

        if ($turnos->isEmpty()) {
            return response()->json([
                'message' => isset($datos['turnoIds'])
                    ? 'Ninguno de los turnos indicados existe.'
                    : 'SisMark no tiene ningún horario marcado como sugerido. Cargalo desde Horarios o indicá los turnos.',
                'creados' => 0,
                'omitidos' => 0,
            ], 422);
        }

        // Las que ya existen para esa terna, incluidas las dadas de baja: la
        // única del índice no distingue `deleted_at`, así que insertar sobre una
        // asignación eliminada reventaría con un error de clave duplicada en vez
        // de con un mensaje.
        $existentes = AsignacionTurno::withTrashed()
            ->where('ci', $ci)
            ->whereIn('idTurno', $turnos->pluck('idTurno')->all())
            ->where('desde', $desde)
            ->pluck('idTurno')
            ->map(fn (string $id): string => trim($id))
            ->all();

        $creados = [];
        $omitidos = [];

        DB::transaction(function () use ($turnos, $existentes, $ci, $desde, $hasta, $datos, &$creados, &$omitidos): void {
            foreach ($turnos as $turno) {
                $idTurno = trim((string) $turno->idTurno);

                if (in_array($idTurno, $existentes, true)) {
                    $omitidos[] = $idTurno;

                    continue;
                }

                AsignacionTurno::create([
                    'ci' => $ci,
                    'turno_id' => $turno->id,
                    // Se copia el código histórico del SIA porque es parte de la
                    // clave única de la tabla, igual que en el alta manual.
                    'idTurno' => $idTurno,
                    'desde' => $desde,
                    'hasta' => $hasta,
                    'observacion' => $datos['observacion'] ?? null,
                ]);

                $creados[] = $idTurno;
            }
        });

        return response()->json([
            'message' => $creados === []
                ? 'El funcionario ya tenía asignado ese horario en esas fechas.'
                : 'Horario asignado en SisMark.',
            'creados' => count($creados),
            'omitidos' => count($omitidos),
            'turnos' => $creados,
        ], $creados === [] ? 200 : 201);
    }
}
