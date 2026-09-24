<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Licencia;
use App\Services\ContratosFuncionario;
use App\Services\RegistroLicencia;
use App\Services\RespaldoDocumento;
use App\Services\TopePermisos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Solicitudes de licencia que llegan de un sistema externo.
 *
 * Hoy la usa Mamoré: el funcionario pide su licencia desde su perfil y acá queda
 * anotada **en estado «Pendiente»**, esperando que Recursos Humanos la apruebe o
 * la rechace desde SisMark. Es el único endpoint de escritura de esta API.
 *
 * ---
 * **La cédula de la URL no autoriza nada.**
 *
 * Igual que en la parte de solo lectura: la clave compartida identifica al
 * *sistema* que escribe, no a la persona por la que escribe. Con ella se podría
 * pedir una licencia a nombre de cualquier cédula.
 *
 * Quién es el funcionario lo decide el consumidor, tomándolo de **su propia
 * sesión del lado del servidor**. Acá se confía en esa cédula porque no hay otra
 * forma: lo que sí se garantiza de este lado es que la solicitud **no surta
 * efecto sola** —nace «Pendiente», y `ProcesadorAsistencia` solo descuenta las
 * aprobadas—, así que una solicitud indebida no puede justificar una ausencia
 * sin que una persona de Recursos Humanos la haya mirado.
 * ---
 */
class SolicitudLicenciaController extends Controller
{
    /**
     * Anota la solicitud del funcionario en estado «Pendiente».
     *
     * Devuelve 422 cuando no hay nada que anotar, con el motivo: sin turnos
     * asignados en esas fechas no hay licencia posible —una licencia licencia un
     * turno—, y si ya existe una para ese día no se pisa.
     */
    public function store(Request $request, string $ci, RegistroLicencia $registro, RespaldoDocumento $respaldos, TopePermisos $tope): JsonResponse
    {
        // Cuando la solicitud trae un respaldo viaja como `multipart/form-data`,
        // y ahí no hay booleanos: `tCompleto` llega como el texto «1» o «0». Sin
        // normalizarlo, `required_if:tCompleto,false` no dispara —compara contra
        // un booleano real— y una licencia parcial pasaría sin horas de entrada
        // ni de salida.
        $request->merge([
            'tCompleto' => $request->boolean('tCompleto'),
            // Ausente significa «con goce»: es lo que corresponde a un pedido
            // que todavía nadie resolvió, y así un consumidor que no manda el
            // campo sigue funcionando como antes.
            'goceHaberes' => $request->has('goceHaberes') ? $request->boolean('goceHaberes') : true,
        ]);

        $datos = $request->validate([
            'desde' => ['required', 'date'],
            'hasta' => ['required', 'date', 'after_or_equal:desde'],
            'motivo' => ['required', 'string', 'max:255'],
            'tCompleto' => ['required', 'boolean'],
            // Lo declara el funcionario al pedir. No es una decisión: la
            // solicitud nace «Pendiente» y Recursos Humanos la resuelve.
            'goceHaberes' => ['required', 'boolean'],
            'lEntra' => ['nullable', 'required_if:tCompleto,false', 'date_format:H:i'],
            'lSale' => ['nullable', 'required_if:tCompleto,false', 'date_format:H:i', 'after:lEntra'],
            // Quién pide, para que figure en el listado de Recursos Humanos.
            'solicitante' => ['nullable', 'string', 'max:50'],
            // Respaldo que justifica el pedido: el certificado o la nota que el
            // funcionario adjunta desde su perfil. Opcional y con los mismos
            // límites que el alta de Recursos Humanos —imagen o PDF, hasta
            // 5 MB—: el documento suele llegar a destiempo (el certificado se
            // presenta al volver), así que exigirlo frenaría la solicitud en vez
            // de mejorarla. Quien resuelve decide si le alcanza lo que ve.
            'respaldo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            // Los contratos del funcionario en los meses del pedido, para contar
            // el tope por contrato sin preguntárselos de vuelta a Mamoré. Ver
            // {@see AsistenciaFuncionarioController::asistencia()}.
            'contratos' => ['nullable', 'array'],
            'contratos.*.desde' => ['required', 'date'],
            'contratos.*.hasta' => ['nullable', 'date'],
        ], [
            'hasta.after_or_equal' => 'La fecha «Hasta» no puede ser anterior a «Desde».',
            'lEntra.required_if' => 'Indicá la hora de entrada o pedí el turno completo.',
            'lSale.required_if' => 'Indicá la hora de salida o pedí el turno completo.',
            'lSale.after' => 'La hora de salida debe ser posterior a la de entrada.',
        ]);

        $ci = trim($ci);
        $desde = Carbon::parse($datos['desde'])->startOfDay();
        $hasta = Carbon::parse($datos['hasta'])->startOfDay();

        if ($desde->diffInDays($hasta) + 1 > RegistroLicencia::MAX_DIAS) {
            return response()->json([
                'message' => 'El rango no puede superar '.RegistroLicencia::MAX_DIAS.' días.',
            ], 422);
        }

        $asignaciones = $registro->turnosDelRango([$ci], $desde, $hasta);

        if ($asignaciones->isEmpty()) {
            return response()->json([
                'message' => 'No tenés turnos asignados en esas fechas, así que no hay nada que licenciar. Consultá con Recursos Humanos.',
            ], 422);
        }

        // Tope mensual de permisos por horas: si este pedido lo pasa, no se
        // anota nada. Va antes de subir el respaldo, para no dejar un archivo
        // huérfano.
        if (! $datos['tCompleto']) {
            $excesos = $tope->excesos(
                $registro->fechasNuevas($asignaciones, $desde, $hasta),
                (string) $datos['lEntra'],
                (string) $datos['lSale'],
                $request->has('contratos') ? [$ci => ContratosFuncionario::delPedido($datos['contratos'] ?? [])] : null,
            );

            if ($excesos !== []) {
                return response()->json([
                    'message' => $tope->mensajeDeExcesos($excesos, false),
                ], 422);
            }
        }

        // El respaldo se sube recién acá, con los turnos ya resueltos: subirlo
        // antes dejaría un archivo huérfano en el bucket cada vez que la
        // solicitud no llega a anotar nada.
        [$adjunto, $adjuntoNombre] = $request->hasFile('respaldo')
            ? $respaldos->guardar($request->file('respaldo'), 'licencias', $ci)
            : [null, null];

        $conteo = $registro->anotar($asignaciones, $desde, $hasta, [
            'tCompleto' => (bool) $datos['tCompleto'],
            // Lo declara el funcionario, pero no lo decide: la licencia nace
            // «Pendiente» y no descuenta nada hasta que Recursos Humanos la
            // resuelva.
            'goceHaberes' => (bool) $datos['goceHaberes'],
            'motivo' => $datos['motivo'],
            'lEntra' => $datos['lEntra'] ?? null,
            'lSale' => $datos['lSale'] ?? null,
            'adjunto' => $adjunto,
            'adjuntoNombre' => $adjuntoNombre,
            'usuario' => mb_substr((string) ($datos['solicitante'] ?? $ci), 0, 50),
            // Sin `registerUser_id`: quien pide no tiene usuario en SisMark.
            'usuarioId' => null,
            'estado' => Licencia::PENDIENTE,
            'origen' => Licencia::ORIGEN_MAMORE,
            // Desde Mamoré el tipo lo da el alcance: el turno completo es una
            // licencia institucional; por horas, un permiso personal, que es
            // el único que cuenta contra el tope.
            'tipo' => $datos['tCompleto'] ? Licencia::TIPO_INSTITUCIONAL : Licencia::TIPO_PERSONAL,
        ]);

        if ($conteo['creadas'] === 0) {
            return response()->json([
                'message' => 'No se anotó ninguna solicitud: '.$registro->mensaje($conteo),
                'detalle' => $conteo,
            ], 422);
        }

        return response()->json([
            'message' => $registro->mensaje($conteo),
            'estado' => 'Pendiente',
            'detalle' => $conteo,
        ], 201);
    }

    /**
     * Da de baja la solicitud del funcionario, con eliminación lógica.
     *
     * ---
     * **Solo lo que sigue «Pendiente», y solo si es suyo.**
     *
     * Dos comprobaciones, porque acá el identificador es el id de una fila —un
     * número corrido— y no la cédula:
     *
     * 1. La licencia tiene que ser de esa cédula. Sin esto, subiendo el número
     *    se le borrarían las licencias a cualquier funcionario.
     * 2. Tiene que estar «Pendiente». Una licencia aprobada justifica una
     *    ausencia y forma parte de reportes que Recursos Humanos ya firmó;
     *    borrarla le cambiaría el pasado al legajo. Si hay que darla de baja,
     *    la decisión es de Recursos Humanos desde SisMark.
     * ---
     *
     * Se borra el pedido entero —todos sus días— porque es lo que el funcionario
     * ve como una licencia, y fila por fila para que el trait de auditoría deje
     * anotado el motivo en cada una.
     */
    public function destroy(Request $request, string $ci, Licencia $licencia): JsonResponse
    {
        $datos = $request->validate([
            // Por qué la da de baja. Opcional: cancelar un pedido propio no
            // debería frenarse por un campo de texto, y sin motivo igual queda
            // constancia de quién y cuándo.
            'observacion' => ['nullable', 'string', 'max:255'],
        ]);

        $ci = trim($ci);

        // 404 y no 403: que el mensaje no confirme que existe una licencia
        // ajena con ese número.
        if ($ci === '' || trim((string) $licencia->ci) !== $ci) {
            return response()->json(['message' => 'La licencia no existe.'], 404);
        }

        if (! $licencia->esPendiente) {
            return response()->json([
                'message' => 'Esta licencia ya fue resuelta por Recursos Humanos, así que no se puede dar de baja desde acá.',
            ], 422);
        }

        $dias = Licencia::query()->deLaSolicitud($licencia)->pendientes()->get();

        // Se deja constancia de dónde vino la baja aunque no escriban motivo:
        // quién la dio no se puede anotar en `deleteUser_id` —el funcionario no
        // tiene usuario en SisMark—, así que el origen va en el texto.
        $motivo = filled($datos['observacion'] ?? null)
            ? 'Cancelada por el funcionario desde Mamoré: '.trim($datos['observacion'])
            : 'Cancelada por el funcionario desde Mamoré.';

        foreach ($dias as $dia) {
            // Va con `save()` **antes** del `delete()`: la eliminación lógica
            // escribe solo `deleted_at` y `updated_at`, así que un atributo
            // suelto no llegaría a la base. Es lo mismo que hace el trait de
            // auditoría cuando sí hay un usuario en sesión.
            $dia->deleteObservacion = mb_substr($motivo, 0, 500);
            $dia->save();
            $dia->delete();
        }

        return response()->json([
            'message' => $dias->count() === 1
                ? 'Tu solicitud se dio de baja.'
                : "Tu solicitud se dio de baja ({$dias->count()} días).",
            'dias' => $dias->count(),
        ]);
    }
}
