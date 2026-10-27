<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Actividad;
use App\Models\AsignacionHorario;
use App\Models\AsignacionTurno;
use App\Models\Turno;
use App\Services\AsignadorTurnos;
use App\Services\ProcesadorAsistencia;
use App\Services\RegistroActividad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Asignación de turno que llega de un sistema externo al dar de alta un
 * contrato.
 *
 * Hoy la usa Mamoré: cuando Recursos Humanos registra un contrato, el turno
 * elegido —o el sugerido ({@see TurnoApiController})— queda asignado en
 * SisMark con el rango del contrato, sin que nadie lo vuelva a cargar a mano.
 *
 * ---
 * **Por qué esta escritura sí surte efecto sola.**
 *
 * La solicitud de licencia nace «Pendiente» porque *justifica una ausencia*: si
 * surtiera efecto sola, cualquiera podría borrarse una falta. Asignar un turno
 * es lo contrario: **crea** la obligación de marcar. Sin él,
 * {@see ProcesadorAsistencia} resuelve el día como «no laborable» y no controla
 * nada. El riesgo real es que el contrato quede sin turno, así que acá conviene
 * que se registre solo.
 * ---
 *
 * **Queda atada al contrato que la originó**, en `contrato_id`, para moverla con
 * él cuando se renueva por adenda, se concluye antes o se anula. Los contratos
 * anteriores a los turnos tienen horarios sueltos con ese mismo `contrato_id`:
 * mover y anular los siguen moviendo, para que nada quede colgado.
 *
 * Toda escritura pasa por {@see AsignadorTurnos}, que mueve la cabecera junto
 * con su detalle. **La cédula de la URL no autoriza nada**: la clave identifica
 * al *sistema*, no a la persona.
 *
 * Cada escritura que sale bien queda en la actividad con lo que pasó —qué turno,
 * qué fechas, antes → después, o que no cambió nada—: el pedido solo dice qué
 * mandó el sistema externo, no qué hizo SisMark con eso.
 */
class AsignacionTurnoApiController extends Controller
{
    public function __construct(private AsignadorTurnos $asignador, private RegistroActividad $actividad) {}

    /**
     * Asigna el turno al funcionario durante el rango del contrato.
     *
     * **Es idempotente** sobre `(ci, turno, desde)`: reintentar la petición no
     * duplica nada y lo ya asignado se informa en `omitidos`. Si esa asignación
     * se había dado de baja con el mismo contrato —el contrato se anuló y se
     * volvió a cargar—, se revive en vez de omitirse.
     */
    public function store(Request $request, string $ci): JsonResponse
    {
        $datos = $request->validate([
            'desde' => ['required', 'date'],
            'hasta' => ['required', 'date', 'after_or_equal:desde'],
            // Opcional: sin él se asigna el turno sugerido, si hay uno solo.
            'turnoId' => ['nullable', 'integer'],
            'contratoId' => ['nullable', 'integer', 'min:1'],
            'observacion' => ['nullable', 'string', 'max:255'],
        ], [
            'hasta.after_or_equal' => 'La fecha «Hasta» no puede ser anterior a «Desde».',
        ]);

        $ci = trim($ci);
        $desde = Carbon::parse($datos['desde'])->startOfDay();
        $hasta = Carbon::parse($datos['hasta'])->startOfDay();
        $contratoId = $datos['contratoId'] ?? null;

        $turno = $this->turno($datos['turnoId'] ?? null);

        if (is_string($turno)) {
            return response()->json(['message' => $turno, 'creados' => 0, 'omitidos' => 0], 422);
        }

        $existente = AsignacionTurno::withTrashed()
            ->where('ci', $ci)
            ->where('turno_id', $turno->id)
            ->where('desde', $desde->toDateString())
            ->first();

        if ($existente !== null && ! $existente->trashed()) {
            $this->anotar(
                Actividad::SIN_CAMBIOS,
                "Pidió asignar el turno «{$turno->nombre}» a CI {$ci} desde el {$this->dia($desde)}{$this->delContrato($contratoId)}: ya lo tenía, sin cambios",
                $ci,
                $datos,
            );

            return response()->json([
                'message' => 'El funcionario ya tenía asignado ese turno desde esa fecha.',
                'creados' => 0,
                'revividos' => 0,
                'omitidos' => 1,
                'turno' => $turno->nombre,
            ]);
        }

        if ($existente !== null && $contratoId !== null && (int) $existente->contrato_id === (int) $contratoId) {
            $this->asignador->restaurar($existente, $hasta, $datos['observacion'] ?? null);

            $this->anotar(
                Actividad::CREAR,
                "Volvió a asignar el turno «{$turno->nombre}» a CI {$ci} del {$this->dia($desde)} al {$this->dia($hasta)}{$this->delContrato($contratoId)}: el contrato se había anulado y se volvió a cargar",
                $ci,
                $datos,
            );

            return response()->json([
                'message' => 'Turno asignado en SisMark.',
                'creados' => 1,
                'revividos' => 1,
                'omitidos' => 0,
                'turno' => $turno->nombre,
            ], 201);
        }

        $conflicto = $this->asignador->conflicto($ci, $turno, $desde, $hasta);

        if ($conflicto !== null) {
            return response()->json(['message' => $conflicto, 'creados' => 0, 'omitidos' => 0], 422);
        }

        $this->asignador->asignar($ci, $turno, $desde, $hasta, $contratoId, $datos['observacion'] ?? null);

        $this->anotar(
            Actividad::CREAR,
            "Asignó el turno «{$turno->nombre}» a CI {$ci} del {$this->dia($desde)} al {$this->dia($hasta)}{$this->delContrato($contratoId)}",
            $ci,
            $datos,
        );

        return response()->json([
            'message' => 'Turno asignado en SisMark.',
            'creados' => 1,
            'revividos' => 0,
            'omitidos' => 0,
            'turno' => $turno->nombre,
        ], 201);
    }

    /**
     * Mueve la vigencia de lo que nació de un contrato, cuando ese contrato
     * cambia de fechas del otro lado.
     *
     * Una **adenda que extiende** sin esto dejaría los días nuevos sin turno, o
     * sea sin control de asistencia, sin que nadie lo note. De los turnos solo
     * se mueve el fin ({@see self::moverFin()}): el último se estira hasta el
     * fin nuevo, aunque el contrato tenga varios por haberse regularizado de a
     * tramos. Si el contrato no
     * tiene nada asignado —es anterior a esta integración, o el alta falló—, se
     * le asigna ahora el turno sugerido: es el camino de recuperación.
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

        $turnos = AsignacionTurno::query()->with('turno.horarios')->delContrato($datos['contratoId'])->get();
        $heredados = AsignacionHorario::query()->whereNull('asignacion_turno_id')->delContrato($datos['contratoId'])->get();

        // Se filtra también por cédula: si del otro lado mandaran una cédula que
        // no es la de esas filas, moverlas sería tocarle la jornada a otra persona.
        if ($turnos->concat($heredados)->contains(fn ($fila): bool => trim((string) $fila->ci) !== $ci)) {
            return response()->json([
                'message' => 'Ese contrato tiene el turno asignado a otra cédula en SisMark. Revisalo antes de mover la vigencia.',
                'actualizados' => 0,
                'creados' => 0,
            ], 422);
        }

        if ($turnos->isNotEmpty()) {
            return $this->moverFin($turnos, $ci, $desde, $hasta, (int) $datos['contratoId']);
        }

        if ($heredados->isNotEmpty()) {
            return $this->moverHeredados($heredados, $ci, $desde, $hasta, (int) $datos['contratoId']);
        }

        return $this->crearParaContrato($request, $ci, $datos, $desde, $hasta);
    }

    /**
     * Baja lógica de lo que nació de un contrato, cuando ese contrato se anula.
     *
     * La fila se queda con `deleted_at`: el turno es el respaldo de por qué se
     * le exigió marcar a esa persona en esas fechas. **Es idempotente**: anular
     * dos veces contesta 200 y `eliminados: 0`.
     */
    public function destroy(Request $request, string $ci): JsonResponse
    {
        $datos = $request->validate([
            'contratoId' => ['required', 'integer', 'min:1'],
            'observacion' => ['nullable', 'string', 'max:255'],
        ]);

        $ci = trim($ci);
        $observacion = $datos['observacion'] ?? 'Contrato anulado en Mamoré.';
        $contrato = (int) $datos['contratoId'];

        $turnos = AsignacionTurno::query()->with('turno')->delContrato($datos['contratoId'])->where('ci', $ci)->get();
        $heredados = AsignacionHorario::query()
            ->whereNull('asignacion_turno_id')
            ->delContrato($datos['contratoId'])
            ->where('ci', $ci)
            ->get();

        if ($turnos->isEmpty() && $heredados->isEmpty()) {
            $this->anotar(
                Actividad::SIN_CAMBIOS,
                "Avisó la anulación del contrato {$contrato} de CI {$ci}: no tenía turno en SisMark, sin cambios",
                $ci,
                $datos,
            );

            return response()->json([
                'message' => 'Ese contrato no tiene turno asignado en SisMark.',
                'eliminados' => 0,
            ]);
        }

        DB::transaction(function () use ($turnos, $heredados, $observacion): void {
            foreach ($turnos as $asignacion) {
                $this->asignador->eliminar($asignacion, $observacion);
            }

            // `deleteUser_id` queda nulo: del otro lado el autenticado es un
            // sistema, no una persona de SisMark.
            foreach ($heredados as $asignacion) {
                $asignacion->forceFill(['deleteObservacion' => $observacion])->save();
                $asignacion->delete();
            }
        });

        $bajas = $turnos
            ->map(fn (AsignacionTurno $asignacion): string => "el turno «{$asignacion->turno?->nombre}» del {$this->dia($asignacion->desde)} al {$this->dia($asignacion->hasta)}")
            ->when($heredados->isNotEmpty(), fn (Collection $piezas): Collection => $piezas->push($this->cuantos($heredados->count(), 'horario', 'horarios').' del sistema anterior'));

        $this->anotar(
            Actividad::ELIMINAR,
            'Dio de baja '.$bajas->implode(' y ')." de CI {$ci}: contrato {$contrato} anulado",
            $ci,
            $datos,
        );

        return response()->json([
            'message' => 'Turno dado de baja en SisMark.',
            'eliminados' => $turnos->count() + $heredados->count(),
        ]);
    }

    /**
     * El turno pedido, o el sugerido si no se pidió ninguno. Devuelve el motivo
     * como texto cuando no hay uno que asignar.
     */
    private function turno(?int $turnoId): Turno|string
    {
        if ($turnoId !== null) {
            return Turno::query()->with('horarios')->find($turnoId)
                ?? 'El turno indicado no existe.';
        }

        $sugeridos = Turno::query()->sugeridos()->with('horarios')->get();

        return match ($sugeridos->count()) {
            0 => 'SisMark no tiene ningún turno sugerido. Crealo desde Turnos o indicá el turno.',
            1 => $sugeridos->first(),
            default => 'SisMark tiene varios turnos sugeridos. Indicá cuál asignar.',
        };
    }

    /**
     * Lleva los turnos del contrato a su fin nuevo **sin tocar sus inicios**.
     *
     * Un contrato puede tener varios turnos —se regularizó de a tramos—, así que
     * no se le copian las fechas del contrato a cada uno: eso los encimaba entre
     * sí y con los horarios del sistema anterior, y además corría hacia atrás el
     * inicio de un turno que arrancó más tarde, llenando los reportes de faltas.
     *
     * - Si el contrato se **extiende**, se estira solo el último turno (el que
     *   termina más tarde): los días nuevos siguen con la jornada que tenía.
     * - Si se **acorta**, los turnos que pasan del fin nuevo terminan ahí, y los
     *   que arrancaban después se dan de baja: quedaron fuera del contrato.
     *
     * Que cambie el inicio del contrato no mueve nada: un día fuera del contrato
     * no se procesa igual, y lo que quede sin turno al principio se regulariza
     * a mano desde la ficha.
     *
     * @param  Collection<int, AsignacionTurno>  $turnos
     */
    private function moverFin(Collection $turnos, string $ci, Carbon $desde, Carbon $hasta, int $contratoId): JsonResponse
    {
        $ultimo = $turnos->sortByDesc(fn (AsignacionTurno $asignacion): int => $asignacion->hasta->getTimestamp())->first();

        if ($hasta->greaterThan($ultimo->hasta)) {
            $conflicto = $this->asignador->conflicto($ci, $ultimo->turno, $ultimo->desde, $hasta, $ultimo);

            if ($conflicto !== null) {
                return response()->json(['message' => $conflicto, 'actualizados' => 0], 422);
            }

            $antes = $this->vigencia($ultimo->desde, $ultimo->hasta);
            $this->asignador->moverVigencia($ultimo, $ultimo->desde, $hasta);

            $this->anotar(
                Actividad::EDITAR,
                "Cambió la vigencia del turno «{$ultimo->turno->nombre}» de CI {$ci}: {$antes} → {$this->vigencia($ultimo->desde, $hasta)}{$this->delContrato($contratoId)}",
                $ci,
                ['contratoId' => $contratoId, 'antes' => $antes, 'despues' => $this->vigencia($ultimo->desde, $hasta)],
            );

            return response()->json([
                'message' => 'Vigencia del turno actualizada en SisMark.',
                'actualizados' => 1,
                'creados' => 0,
            ]);
        }

        $sePasan = $turnos->filter(fn (AsignacionTurno $asignacion): bool => $asignacion->hasta->greaterThan($hasta));

        if ($sePasan->isEmpty()) {
            $this->anotar(
                Actividad::SIN_CAMBIOS,
                "Avisó un cambio del contrato {$contratoId} de CI {$ci} (vigencia {$this->vigencia($desde, $hasta)}): el turno ya tenía esas fechas, sin cambios",
                $ci,
                ['contratoId' => $contratoId, 'desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString()],
            );

            return response()->json([
                'message' => 'El turno ya estaba al día en SisMark.',
                'actualizados' => 0,
                'creados' => 0,
            ]);
        }

        // Se arma antes de tocar nada: después las fechas viejas ya no están.
        $cambios = $sePasan->map(fn (AsignacionTurno $asignacion): string => $asignacion->desde->greaterThan($hasta)
            ? "«{$asignacion->turno->nombre}» {$this->vigencia($asignacion->desde, $asignacion->hasta)} dado de baja por quedar fuera"
            : "«{$asignacion->turno->nombre}» {$this->vigencia($asignacion->desde, $asignacion->hasta)} → {$this->vigencia($asignacion->desde, $hasta)}");

        DB::transaction(function () use ($sePasan, $hasta): void {
            foreach ($sePasan as $asignacion) {
                if ($asignacion->desde->greaterThan($hasta)) {
                    $this->asignador->eliminar($asignacion, 'El contrato se acortó en Mamoré y este turno quedó fuera de él.');
                } else {
                    $this->asignador->concluir($asignacion, $hasta);
                }
            }
        });

        $this->anotar(
            Actividad::EDITAR,
            "Acortó el contrato {$contratoId} de CI {$ci} al {$this->dia($hasta)}: ".$cambios->implode('; '),
            $ci,
            ['contratoId' => $contratoId, 'hasta' => $hasta->toDateString(), 'cambios' => $cambios->values()->all()],
        );

        return response()->json([
            'message' => 'Vigencia del turno actualizada en SisMark.',
            'actualizados' => $sePasan->count(),
            'creados' => 0,
        ]);
    }

    /**
     * Contratos anteriores a los turnos: sus horarios sueltos se mueven como
     * antes, fila por fila.
     *
     * @param  Collection<int, AsignacionHorario>  $heredados
     */
    private function moverHeredados($heredados, string $ci, Carbon $desde, Carbon $hasta, int $contratoId): JsonResponse
    {
        // `desde` es parte de la única `(ci, horario_id, desde)`: si se corrió la
        // fecha de inicio, puede chocar con otra fila de esa terna.
        $choque = AsignacionHorario::withTrashed()
            ->where('ci', $ci)
            ->whereIn('horario_id', $heredados->pluck('horario_id')->all())
            ->where('desde', $desde->toDateString())
            ->whereNotIn('id', $heredados->pluck('id')->all())
            ->exists();

        if ($choque) {
            return response()->json([
                'message' => 'El funcionario ya tiene otra asignación que arranca ese día con el mismo horario. Revisala en SisMark antes de mover el contrato.',
                'actualizados' => 0,
            ], 422);
        }

        $primero = $heredados->first();
        $antes = $this->vigencia(Carbon::parse($primero->desde), Carbon::parse($primero->hasta));

        DB::transaction(function () use ($heredados, $desde, $hasta): void {
            foreach ($heredados as $asignacion) {
                $asignacion->update(['desde' => $desde, 'hasta' => $hasta]);
            }
        });

        $this->anotar(
            Actividad::EDITAR,
            'Cambió la vigencia de '.$this->cuantos($heredados->count(), 'horario', 'horarios')." del sistema anterior de CI {$ci}: {$antes} → {$this->vigencia($desde, $hasta)}{$this->delContrato($contratoId)}",
            $ci,
            ['contratoId' => $contratoId, 'antes' => $antes, 'despues' => $this->vigencia($desde, $hasta)],
        );

        return response()->json([
            'message' => 'Vigencia del horario actualizada en SisMark.',
            'actualizados' => $heredados->count(),
            'creados' => 0,
        ]);
    }

    /**
     * El contrato no tenía nada asignado: se le asigna ahora el turno sugerido,
     * reutilizando el alta.
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

        return response()->json([
            'message' => ($cuerpo['creados'] ?? 0) > 0
                ? 'El contrato no tenía turno en SisMark: se le asignó el sugerido con la vigencia nueva.'
                : ($cuerpo['message'] ?? 'No se pudo asignar el turno.'),
            'actualizados' => 0,
            'creados' => $cuerpo['creados'] ?? 0,
        ], $respuesta->getStatusCode() === 422 ? 422 : 200);
    }

    /**
     * Deja en la actividad lo que hizo SisMark con el pedido. Quién lo pidió
     * —el sistema externo— lo completa {@see RegistroActividad} desde el token.
     *
     * @param  array<string, mixed>  $datos
     */
    private function anotar(string $accion, string $descripcion, string $ci, array $datos): void
    {
        $this->actividad->registrarEvento($accion, 'API', $descripcion, ci: $ci, datos: $datos);
    }

    private function dia(Carbon $fecha): string
    {
        return $fecha->format('d/m/Y');
    }

    private function vigencia(Carbon $desde, Carbon $hasta): string
    {
        return "{$this->dia($desde)}–{$this->dia($hasta)}";
    }

    private function delContrato(?int $contratoId): string
    {
        return $contratoId === null ? '' : " (contrato {$contratoId})";
    }

    private function cuantos(int $cantidad, string $singular, string $plural): string
    {
        return $cantidad === 1 ? "1 {$singular}" : "{$cantidad} {$plural}";
    }
}
