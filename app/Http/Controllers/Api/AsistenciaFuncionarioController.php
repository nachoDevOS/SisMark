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
     * Licencias del funcionario en el rango.
     */
    public function licencias(Request $request, string $ci): AnonymousResourceCollection
    {
        [$ci, $desde, $hasta] = $this->parametros($request, $ci);

        $licencias = Licencia::query()
            ->with('turno')
            ->where('ci', $ci)
            ->whereDate('fecha', '>=', $desde)
            ->whereDate('fecha', '<=', $hasta)
            ->orderBy('fecha')
            ->get();

        return LicenciaApiResource::collection($licencias)
            ->additional($this->meta($ci, $desde, $hasta));
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
