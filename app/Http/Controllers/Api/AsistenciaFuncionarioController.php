<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DiaAsistenciaResource;
use App\Http\Resources\LicenciaApiResource;
use App\Http\Resources\MarcacionApiResource;
use App\Models\Asistencia;
use App\Models\Licencia;
use App\Services\ProcesadorAsistencia;
use App\Services\ResolutorNombres;
use App\Services\RespaldoDocumento;
use App\Services\ResumenEscritorio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Asistencia de un funcionario, para los sistemas externos (solo lectura).
 *
 * Hoy la consume Mamoré, donde cada funcionario ya tiene cuenta, para mostrarle
 * sus propias marcaciones sin darle acceso a SisMark.
 *
 * ---
 * **La cédula de la URL no autoriza nada.**
 *
 * La clave compartida identifica al *sistema* que pregunta, no a la persona por
 * la que pregunta: con esa clave se puede pedir la asistencia de cualquier
 * cédula. Quién es el funcionario lo decide el consumidor.
 *
 * Por eso el consumidor tiene que tomar la cédula de **su propia sesión, del
 * lado del servidor**, y nunca de algo que el navegador controle. Si expusiera
 * una pantalla tipo `/mis-marcaciones?ci=1234567`, cualquier funcionario
 * cambiaría el número y vería los horarios, ausencias y licencias de un
 * compañero.
 * ---
 *
 * El cálculo vive acá y no del lado del consumidor: las reglas de asistencia
 * —turnos nocturnos, turnos partidos, licencias parciales, tolerancias— son de
 * {@see ProcesadorAsistencia}, y duplicarlas garantiza que los dos sistemas
 * terminen dando números distintos para el mismo día.
 */
class AsistenciaFuncionarioController extends Controller
{
    /**
     * Tope del rango consultable. El mismo que el alta de licencias: un año
     * cubre cualquier consulta razonable de un funcionario sobre sí mismo, y
     * corta de raíz el pedido que barre la tabla entera.
     */
    private const MAX_DIAS = 366;

    /**
     * Marcaciones crudas del funcionario en el rango, en orden cronológico.
     *
     * Es lo que el reloj registró, sin cruzar contra turnos ni licencias.
     */
    public function marcaciones(Request $request, string $ci): AnonymousResourceCollection
    {
        [$ci, $desde, $hasta] = $this->parametros($request, $ci);

        $marcaciones = Asistencia::query()
            ->where('ci', $ci)
            ->enRango($desde, $hasta)
            ->orderBy('fecha')
            ->orderBy('hora')
            ->get();

        return MarcacionApiResource::collection($marcaciones)
            ->additional($this->meta($ci, $desde, $hasta));
    }

    /**
     * Asistencia procesada: las marcas cruzadas contra el turno asignado, los
     * días excepcionales y las licencias, con entradas, salidas, atrasos y horas
     * computadas.
     *
     * Es el mismo cálculo que ve Recursos Humanos en «Reportes → Procesado».
     * Los valores viajan formateados además de en segundos, para que el
     * consumidor pinte la fila sin hacer cuentas.
     */
    public function asistencia(Request $request, ProcesadorAsistencia $procesador, string $ci): JsonResponse
    {
        [$ci, $desde, $hasta] = $this->parametros($request, $ci);

        $dias = $procesador->procesar($ci, $desde, $hasta);
        $totales = $procesador->totales($dias);

        return response()->json([
            'data' => DiaAsistenciaResource::collection($dias)->resolve(),
            'totales' => [
                'dias' => $totales['dias'],
                'atrasoSegundos' => $totales['atraso'],
                // En minutos, igual que el pie del reporte impreso («0 min»), y
                // no en horas: el atraso acumulado de un mes rara vez llega a
                // una hora y «0h 12m» se lee peor que «12 min».
                'atraso' => ProcesadorAsistencia::desvio($totales['atraso']),
                'computadoSegundos' => $totales['computado'],
                'computado' => ProcesadorAsistencia::duracion($totales['computado']),
                'esperadoSegundos' => $totales['esperado'],
                'esperado' => ProcesadorAsistencia::duracion($totales['esperado']),
                'anticipoSegundos' => $totales['anticipo'],
                'anticipo' => ProcesadorAsistencia::desvio($totales['anticipo']),
                'saldoSegundos' => $totales['saldo'],
                'saldo' => ProcesadorAsistencia::duracion($totales['saldo']),
                // Cuántos días cayó cada estado, con la etiqueta ya resuelta:
                // el consumidor no tiene por qué conocer las claves internas
                // («no_laborable», «sin_salida») ni cómo se escriben.
                'porEstado' => collect($totales['porEstado'])
                    ->map(fn (int $cantidad, string $estado): array => [
                        'estado' => $estado,
                        'etiqueta' => ProcesadorAsistencia::ETIQUETAS[$estado] ?? $estado,
                        'cantidad' => $cantidad,
                    ])
                    ->values()
                    ->all(),
            ],
            ...$this->meta($ci, $desde, $hasta),
        ]);
    }

    /**
     * Licencias del funcionario en el rango, **una por pedido**.
     *
     * Un alta expande el rango a una fila por día y turno, así que un permiso
     * del 14 al 15 de agosto son dos filas. Devolverlas sueltas le mostraba al
     * funcionario dos licencias donde pidió una; se agrupan por `solicitud`,
     * igual que las pantallas de Recursos Humanos.
     *
     * El rango se aplica **para elegir qué solicitudes entran**, no para
     * recortarlas: si un permiso empezó en julio y sigue en agosto, quien
     * consulta agosto tiene que verlo entero y no un pedazo. Por eso las
     * solicitudes se buscan por sus días dentro del rango y después se traen
     * completas.
     */
    public function licencias(Request $request, string $ci): AnonymousResourceCollection
    {
        [$ci, $desde, $hasta] = $this->parametros($request, $ci);

        // Qué licencias tocan el rango. La clave sale de la fila y no de la
        // columna: lo migrado del SIA va con `solicitud` en null y ahí cada
        // fila es su propia licencia.
        $delRango = Licencia::query()
            ->where('ci', $ci)
            ->whereDate('fecha', '>=', $desde)
            ->whereDate('fecha', '<=', $hasta)
            ->get(['id', 'solicitud']);

        $solicitudes = $delRango->map->clave_agrupadora->unique()->values();
        $conSolicitud = $delRango->pluck('solicitud')->filter()->unique()->values();
        $delSia = $delRango->whereNull('solicitud')->pluck('id')->all();

        $licencias = Licencia::query()
            ->with('turno')
            ->where(fn ($q) => $q
                // Los pedidos salen por su fila de inicio; los del SIA son
                // una fila cada uno y entran por su id.
                ->where(fn ($p) => $p->whereIn('solicitud', $conSolicitud)->iniciosDeSolicitud())
                ->orWhereIn('id', $delSia))
            // Lo más reciente primero, igual que el listado de Recursos
            // Humanos: lo que el funcionario acaba de pedir es lo que viene a
            // mirar, y en un rango largo quedaba al fondo de la tabla.
            ->orderByDesc('fecha')
            ->get();

        // Hasta qué día llega cada pedido y cuántos días abarca. Se cuelga de
        // cada modelo para que el recurso lo encuentre sin recibir un mapa.
        $resumen = Licencia::resumenDe($solicitudes);

        $licencias->each(function (Licencia $licencia) use ($resumen): void {
            $datos = $resumen[$licencia->clave_agrupadora] ?? null;

            $licencia->hastaSolicitud = $datos->hasta ?? $licencia->fecha?->toDateString();
            $licencia->diasSolicitud = (int) ($datos->dias ?? 1);
            $licencia->estadosSolicitud = (int) ($datos->estados ?? 1);
        });

        return LicenciaApiResource::collection($licencias)
            ->additional($this->meta($ci, $desde, $hasta));
    }

    /**
     * Ficha de una licencia propia: el pedido y el desglose día por día.
     *
     * Es lo que la pantalla de Recursos Humanos muestra al abrir una solicitud,
     * recortado a lo que le sirve al funcionario: no van los avisos internos ni
     * quién la revisó, solo qué pidió, qué días abarcó y en qué quedó.
     *
     * Se comprueba que la licencia sea de esa cédula, por lo mismo que el
     * respaldo: acá el identificador es el id de una fila, un número corrido.
     */
    public function licencia(string $ci, Licencia $licencia): JsonResponse
    {
        $ci = trim($ci);

        // 404 y no 403: que el mensaje no confirme que existe una licencia
        // ajena con ese número.
        if ($ci === '' || trim((string) $licencia->ci) !== $ci) {
            return response()->json(['message' => 'La licencia no existe.'], 404);
        }

        $dias = Licencia::query()
            ->with('turno')
            ->deLaSolicitud($licencia)
            ->orderBy('fecha')
            ->get();

        // El pedido se describe con la fila que lo abre, que es la que el
        // listado muestra: si se entró por otro día, igual se ve el pedido.
        $inicio = $dias->first() ?? $licencia;
        $inicio->hastaSolicitud = $dias->last()?->fecha?->toDateString();
        $inicio->diasSolicitud = $dias->count();
        $inicio->estadosSolicitud = $dias->pluck('estado')->unique()->count();

        return response()->json([
            'licencia' => (new LicenciaApiResource($inicio))->resolve(),
            'dias' => $dias->map(fn (Licencia $dia): array => [
                'fecha' => $dia->fecha?->toDateString(),
                'diaSemana' => $dia->fecha?->locale('es')->dayName,
                'turno' => $dia->resumen_turno,
                'estado' => $dia->estado,
            ])->all(),
        ]);
    }

    /**
     * Enlace temporal para descargar el respaldo de una licencia.
     *
     * ---
     * **Se comprueba que la licencia sea de esa cédula.**
     *
     * Es la única parte de esta API donde el identificador no es la cédula sino
     * el id de una fila, y ese id es un número corrido: sin esta comprobación,
     * quien tenga la clave compartida podría pedir `…/licencias/1/respaldo` e ir
     * subiendo el número para bajarse los certificados médicos de todo el
     * personal. El consumidor manda la cédula de su sesión, y acá se exige que
     * la fila le pertenezca.
     * ---
     *
     * Se devuelve la URL en vez de redirigir: quien llama es otro servidor, que
     * necesita el enlace para dárselo a su propio navegador.
     */
    public function respaldo(Request $request, string $ci, Licencia $licencia, RespaldoDocumento $respaldos): JsonResponse
    {
        $ci = trim($ci);

        if ($ci === '' || trim((string) $licencia->ci) !== $ci) {
            // 404 y no 403: que el mensaje no confirme que la licencia existe
            // pero es de otra persona.
            return response()->json(['message' => 'La licencia no existe.'], 404);
        }

        $enlace = $respaldos->enlace($licencia->adjunto);

        if ($enlace === null) {
            return response()->json([
                'message' => 'La licencia no tiene respaldo cargado, o el archivo ya no está disponible.',
            ], 404);
        }

        return response()->json([
            'url' => $enlace,
            'nombre' => $licencia->adjuntoNombre ?: 'respaldo',
        ]);
    }

    /**
     * Valida y normaliza lo que llega: la cédula de la ruta y el rango.
     *
     * Sin rango se toma el mes actual hasta hoy, igual que las pantallas del
     * sistema. El rango invertido se da vuelta en vez de devolver un error: es
     * lo que hace el reporte de RRHH y no hay motivo para diferir.
     *
     * @return array{0: string, 1: Carbon, 2: Carbon}
     *
     * @throws ValidationException
     */
    private function parametros(Request $request, string $ci): array
    {
        $validado = $request->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
        ]);

        $ci = trim($ci);

        if ($ci === '') {
            throw ValidationException::withMessages(['ci' => 'La cédula es obligatoria.']);
        }

        $desde = Carbon::parse($validado['desde'] ?? now()->startOfMonth()->toDateString())->startOfDay();
        $hasta = Carbon::parse($validado['hasta'] ?? now()->toDateString())->startOfDay();

        if ($hasta->lessThan($desde)) {
            [$desde, $hasta] = [$hasta, $desde];
        }

        if ($desde->diffInDays($hasta) + 1 > self::MAX_DIAS) {
            throw ValidationException::withMessages([
                'hasta' => 'El rango no puede superar '.self::MAX_DIAS.' días.',
            ]);
        }

        return [$ci, $desde, $hasta];
    }

    /**
     * Datos del funcionario y del rango, que acompañan a las tres respuestas
     * para que el consumidor pueda rotular la pantalla sin otra consulta.
     *
     * El nombre sale de {@see ResolutorNombres}: Mamoré primero, base local
     * como respaldo. `null` si la cédula no está en ninguna de las dos —eso no
     * es un error, las marcaciones se cruzan por cédula y puede haber marcas de
     * alguien que ya no figura en el padrón—.
     *
     * @return array{meta: array<string, mixed>}
     */
    private function meta(string $ci, Carbon $desde, Carbon $hasta): array
    {
        $ficha = app(ResolutorNombres::class)->fichaPorCi($ci);

        return [
            'meta' => [
                'funcionario' => [
                    'ci' => $ci,
                    'nombre' => $ficha['nombre'] ?? null,
                    // «Apellidos Nombres», que es como rotula el reporte
                    // impreso, heredado del sistema de escritorio viejo.
                    'nombreFormal' => $ficha['nombreFormal'] ?? ($ficha['nombre'] ?? null),
                    'pinReloj' => $ficha['pinReloj'] ?? null,
                    'cargo' => $ficha['cargo'] ?? null,
                    'direccion' => $ficha['direccion'] ?? null,
                ],
                'rango' => [
                    'desde' => $desde->toDateString(),
                    'hasta' => $hasta->toDateString(),
                ],
                // Hasta qué día llegaron marcaciones de los relojes.
                //
                // Sin esto, el consumidor no puede distinguir «no marcó» de
                // «todavía no se sincronizó ese día», y muestra faltas que no
                // existen: los equipos se sincronizan a mano, así que la tabla
                // queda días atrás con normalidad. Todo lo posterior a esta
                // fecha es «sin datos aún», no una ausencia.
                'ultimaSincronizacion' => $this->ultimaSincronizacion(),
            ],
        ];
    }

    /**
     * Último día del que hay marcaciones en el sistema, en formato Y-m-d.
     *
     * Se cachea cinco minutos: es el mismo dato para todos los funcionarios y
     * no cambia hasta la próxima sincronización de un equipo.
     */
    private function ultimaSincronizacion(): ?string
    {
        return Cache::remember(
            'api.ultima-sincronizacion',
            now()->addMinutes(5),
            fn (): ?string => app(ResumenEscritorio::class)->ultimaMarcacion()?->toDateString(),
        );
    }
}
