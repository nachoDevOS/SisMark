<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TurnoAsignadoResource;
use App\Models\AsignacionTurno;
use App\Models\Turno;
use App\Services\ProcesadorAsistencia;
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
 * **Queda atada al contrato que la originó**, en `contrato_id`. Un contrato se
 * renueva por adenda, se concluye antes de tiempo o se le corre la fecha de fin,
 * y cuando eso pasa hay que mover estas filas con él: sin el vínculo, la única
 * referencia era el texto de `observacion`, que cualquiera puede editar desde la
 * pantalla de Turnos. Se guarda el **id** y no el código porque Mamoré regenera
 * el código cuando cambia el año de inicio o la dirección administrativa.
 *
 * **La cédula de la URL no autoriza nada.** Igual que en el resto de esta API,
 * la clave identifica al *sistema*, no a la persona. Ver la advertencia en
 * {@see AsistenciaFuncionarioController}.
 */
class AsignacionTurnoApiController extends Controller
{
    /**
     * El horario asignado al funcionario, agrupado por período de vigencia.
     *
     * Es el único método de lectura del controlador y va con `turnos:read`, no
     * con `turnos:write`: mostrarle a alguien su propio horario no es asignarlo.
     *
     * **No recibe rango de fechas**, a diferencia del resto de la API. Una
     * asignación no es un dato de un día sino un período propio, y recortarla
     * contra un mes le mostraría al funcionario «desde el 1 hasta el 31» cuando
     * su turno rige todo el año. Se devuelve lo que está en pie y lo que todavía
     * no empezó; lo ya vencido solo si lo piden con `vencidas=1`, porque es
     * historial y en una carrera larga tapa lo que la persona viene a mirar.
     *
     * Lista vacía no es un error: un funcionario sin turno asignado es
     * exactamente lo que hay que poder ver —sin turno,
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

        $asignaciones = AsignacionTurno::query()
            ->delFuncionario($ci, $request->boolean('vencidas'))
            ->get();

        return response()->json([
            'data' => TurnoAsignadoResource::agrupar($asignaciones)->map->toArray($request)->all(),
        ]);
    }

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
            // El contrato que originó la asignación, del lado del consumidor.
            // Queda guardado para poder mover estas filas cuando esa vigencia
            // cambie —una adenda que renueva, una conclusión anticipada—, que
            // sin esto no se podía: la única referencia era el texto de
            // `observacion`.
            'contratoId' => ['nullable', 'integer', 'min:1'],
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
            ->get()
            ->keyBy(fn (AsignacionTurno $asignacion): string => trim((string) $asignacion->idTurno));

        $contratoId = $datos['contratoId'] ?? null;

        $creados = [];
        $omitidos = [];
        $revividos = [];

        DB::transaction(function () use ($turnos, $existentes, $contratoId, $ci, $desde, $hasta, $datos, &$creados, &$omitidos, &$revividos): void {
            foreach ($turnos as $turno) {
                $idTurno = trim((string) $turno->idTurno);
                $existente = $existentes->get($idTurno);

                if ($existente !== null) {
                    // Una fila dada de baja del **mismo contrato** se revive en
                    // vez de omitirse. Pasa cuando el contrato se anula y se
                    // vuelve a cargar: sin esto la fila muerta bloquea el alta
                    // —la única del índice no distingue `deleted_at`— y la
                    // persona quedaría sin horario, o sea sin control de
                    // asistencia, sin que nadie lo note.
                    if ($existente->trashed() && $contratoId !== null && (int) $existente->contrato_id === (int) $contratoId) {
                        $existente->restore();
                        $existente->update(['hasta' => $hasta, 'observacion' => $datos['observacion'] ?? null]);

                        $revividos[] = $idTurno;

                        continue;
                    }

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
                    'contrato_id' => $datos['contratoId'] ?? null,
                    'observacion' => $datos['observacion'] ?? null,
                ]);

                $creados[] = $idTurno;
            }
        });

        // Revivir cuenta como crear: del otro lado se pidió que ese contrato
        // tuviera horario y ahora lo tiene. Se informa aparte, en `revividos`,
        // para que quede claro que la fila es la de antes y no una nueva.
        $puestas = array_merge($creados, $revividos);

        return response()->json([
            'message' => $puestas === []
                ? 'El funcionario ya tenía asignado ese horario en esas fechas.'
                : 'Horario asignado en SisMark.',
            'creados' => count($puestas),
            'revividos' => count($revividos),
            'omitidos' => count($omitidos),
            'turnos' => $puestas,
        ], $puestas === [] ? 200 : 201);
    }

    /**
     * Mueve la vigencia de las asignaciones que nacieron de un contrato, cuando
     * ese contrato cambia de fechas del otro lado.
     *
     * ---
     * **Por qué hace falta.** Un contrato no se queda quieto: se renueva por
     * adenda, se concluye antes de tiempo, o alguien le corrige la fecha de
     * inicio. Hasta que existió `contrato_id` no había forma de saber cuáles de
     * las asignaciones de un funcionario había que mover con él, así que no se
     * movía ninguna.
     *
     * El daño era asimétrico y por eso pasaba desapercibido. Una **adenda que
     * extiende** deja al contrato cubriendo días que el turno ya no cubre:
     * `ProcesadorAsistencia` los resuelve como «no laborable» y esa persona
     * queda sin control de asistencia hasta que alguien lea el reporte. Una
     * **conclusión anticipada** es inofensiva —el contrato es la primera puerta
     * del procesador, así que un día sin contrato no se procesa aunque sobre el
     * turno—, pero deja filas mintiendo en la solapa Turnos.
     * ---
     *
     * **Crea lo que falte.** Si el contrato no tiene ninguna asignación —porque
     * es anterior a esta integración, o porque el alta falló y nadie lo
     * reintentó— este endpoint las crea con el horario sugerido. Es el camino de
     * recuperación: un contrato viejo que recibe una adenda termina quedando
     * bien sin que nadie lo cargue a mano.
     */
    public function update(Request $request, string $ci): JsonResponse
    {
        $datos = $request->validate([
            'contratoId' => ['required', 'integer', 'min:1'],
            'desde' => ['required', 'date'],
            'hasta' => ['required', 'date', 'after_or_equal:desde'],
        ], [
            'hasta.after_or_equal' => 'La fecha «Hasta» no puede ser anterior a «Desde».',
        ]);

        $ci = trim($ci);
        $desde = Carbon::parse($datos['desde'])->startOfDay();
        $hasta = Carbon::parse($datos['hasta'])->startOfDay();

        $delContrato = AsignacionTurno::query()->delContrato($datos['contratoId'])->get();

        // Se filtra también por cédula: el contrato manda, pero si del otro lado
        // mandaran una cédula que no es la de esas filas, moverlas sería tocarle
        // el horario a otra persona.
        $asignaciones = $delContrato->where('ci', $ci)->values();

        if ($asignaciones->isEmpty() && $delContrato->isNotEmpty()) {
            // El contrato tiene horario, pero a nombre de otro. Crear más sería
            // dejar dos personas cobrando el mismo contrato en SisMark; se corta
            // y que alguien mire qué pasó.
            return response()->json([
                'message' => 'Ese contrato tiene el horario asignado a otra cédula en SisMark. Revisalo antes de mover la vigencia.',
                'actualizados' => 0,
                'creados' => 0,
            ], 422);
        }

        if ($asignaciones->isEmpty()) {
            return $this->crearParaContrato($request, $ci, $datos, $desde, $hasta);
        }

        // `desde` es parte de la clave única `(ci, idTurno, desde)`. Si se
        // corrió la fecha de inicio, la fila nueva puede chocar con otra que ya
        // exista para esa terna —una asignación cargada a mano, u otro contrato
        // del mismo funcionario que arranca ese día—.
        $choque = AsignacionTurno::withTrashed()
            ->where('ci', $ci)
            ->whereIn('idTurno', $asignaciones->pluck('idTurno')->map(fn (string $id): string => trim($id))->all())
            ->where('desde', $desde)
            ->whereNotIn('id', $asignaciones->pluck('id')->all())
            ->exists();

        if ($choque) {
            return response()->json([
                'message' => 'El funcionario ya tiene otra asignación que arranca ese día con el mismo turno. Revisala en SisMark antes de mover el contrato.',
                'actualizados' => 0,
            ], 422);
        }

        DB::transaction(function () use ($asignaciones, $desde, $hasta): void {
            foreach ($asignaciones as $asignacion) {
                $asignacion->update(['desde' => $desde, 'hasta' => $hasta]);
            }
        });

        return response()->json([
            'message' => 'Vigencia del horario actualizada en SisMark.',
            'actualizados' => $asignaciones->count(),
            'creados' => 0,
        ]);
    }

    /**
     * Da de baja las asignaciones que nacieron de un contrato, cuando ese
     * contrato se anula del otro lado.
     *
     * **Baja lógica.** Se marca `deleted_at` y la fila se queda: el turno es el
     * respaldo de por qué a esa persona se le exigió marcar en esas fechas, y
     * borrarlo de verdad dejaría sin explicación los atrasos y las faltas que ya
     * se le imputaron. Un contrato anulado no borra la historia de lo que pasó
     * mientras estuvo vigente.
     *
     * De todas formas el reporte ya no los cuenta: `ProcesadorAsistencia` mira
     * primero el contrato, y sin contrato que cubra el día no procesa nada. Esta
     * baja es para que el horario no siga apareciendo en la solapa Turnos como
     * si la persona tuviera que ir a trabajar.
     *
     * **Es idempotente.** Anular dos veces el mismo contrato —o reintentar
     * porque la red cortó la respuesta— contesta 200 y `eliminados: 0`, no un
     * error: el estado final es el que se pidió.
     */
    public function destroy(Request $request, string $ci): JsonResponse
    {
        $datos = $request->validate([
            'contratoId' => ['required', 'integer', 'min:1'],
            'observacion' => ['nullable', 'string', 'max:255'],
        ]);

        $ci = trim($ci);

        // Igual que al mover la vigencia, la cédula filtra junto con el
        // contrato: sin eso, una cédula equivocada del otro lado le daría de
        // baja el horario a quien no corresponde.
        $asignaciones = AsignacionTurno::query()
            ->delContrato($datos['contratoId'])
            ->where('ci', $ci)
            ->get();

        if ($asignaciones->isEmpty()) {
            return response()->json([
                'message' => 'Ese contrato no tiene horario asignado en SisMark.',
                'eliminados' => 0,
            ]);
        }

        DB::transaction(function () use ($asignaciones, $datos): void {
            foreach ($asignaciones as $asignacion) {
                // El motivo se guarda **antes** y con su propio `save()`: la baja
                // lógica de Laravel escribe solo `deleted_at` con un UPDATE
                // propio, así que un atributo dejado sucio en el modelo no
                // llegaría a la base. Va con `forceFill` porque
                // `deleteObservacion` no es asignable en masa, y con razón: lo
                // escribe el sistema, no un formulario.
                //
                // `deleteUser_id` queda nulo a propósito: del otro lado el
                // autenticado es un sistema, no una persona de SisMark.
                $asignacion->forceFill([
                    'deleteObservacion' => $datos['observacion'] ?? 'Contrato anulado en Mamoré.',
                ])->save();

                $asignacion->delete();
            }
        });

        return response()->json([
            'message' => 'Horario dado de baja en SisMark.',
            'eliminados' => $asignaciones->count(),
        ]);
    }

    /**
     * El contrato no tenía ninguna asignación: se crean ahora, reutilizando el
     * alta. Pasa con los contratos anteriores a esta integración y con los que
     * se dieron de alta mientras SisMark no respondía.
     *
     * @param  array<string, mixed>  $datos
     */
    private function crearParaContrato(Request $request, string $ci, array $datos, Carbon $desde, Carbon $hasta): JsonResponse
    {
        $respuesta = $this->store($request->merge([
            'desde' => $desde->toDateString(),
            'hasta' => $hasta->toDateString(),
            'contratoId' => $datos['contratoId'],
        ]), $ci);

        $cuerpo = $respuesta->getData(true);

        // Se devuelve como actualización y no como alta: del otro lado esto fue
        // una edición de contrato, y el mensaje tiene que decir lo que pasó.
        return response()->json([
            'message' => ($cuerpo['creados'] ?? 0) > 0
                ? 'El contrato no tenía horario en SisMark: se le asignó el sugerido con la vigencia nueva.'
                : ($cuerpo['message'] ?? 'No se pudo asignar el horario.'),
            'actualizados' => 0,
            'creados' => $cuerpo['creados'] ?? 0,
        ], $respuesta->getStatusCode() === 422 ? 422 : 200);
    }
}
