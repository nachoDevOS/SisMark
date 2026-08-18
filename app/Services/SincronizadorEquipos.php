<?php

namespace App\Services;

use App\Exceptions\DeviceServiceException;
use App\Models\Equipo;
use App\Models\EquipoAuditoria;
use Illuminate\Support\Carbon;

/**
 * Lectura de marcaciones de un equipo biométrico y su volcado a la tabla local
 * `asistencias`.
 *
 * Vive aparte del controlador porque hay dos disparadores con las mismas
 * reglas: el botón «Sincronizar» de la ficha del equipo y la tarea programada
 * que corre a las horas configuradas en cada equipo. Los dos leen del reloj vía
 * el microservicio, aplican el rango, registran con {@see RegistroAsistencia} y
 * dejan la entrada en la bitácora.
 */
class SincronizadorEquipos
{
    public function __construct(
        private DeviceService $deviceService,
        private RegistroAsistencia $registro,
    ) {}

    /**
     * Trae las marcaciones del equipo para el rango pedido, leyéndolas en vivo
     * del reloj vía el microservicio. El rango se pasa al microservicio, que lo
     * aplica antes de responder: así, en equipos con historial largo, Laravel
     * recibe y parsea mucho menos; el filtro local de abajo solo recorta lo que
     * el equipo igual haya mandado de más.
     *
     * Se lee siempre del reloj, sin caché: los dos usos —exportar y
     * sincronizar— tienen que traer lo del momento.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: ?string}
     */
    public function marcaciones(Equipo $equipo, string $desde = '', string $hasta = ''): array
    {
        try {
            $todas = $this->deviceService->attendance($equipo, $desde ?: null, $hasta ?: null)['marcaciones'] ?? [];
        } catch (DeviceServiceException $e) {
            return [[], $e->getMessage()];
        }

        return [$this->filtrarPorRango($todas, $desde, $hasta), null];
    }

    /**
     * Baja las marcaciones del equipo y las registra en `asistencias`, dejando
     * la acción en la bitácora (también cuando falla la lectura del reloj).
     *
     * @return array{exito: bool, mensaje: string, total: int}
     */
    public function sincronizar(Equipo $equipo, string $desde = '', string $hasta = ''): array
    {
        [$todas, $error] = $this->marcaciones($equipo, $desde, $hasta);

        if ($error) {
            EquipoAuditoria::registrar($equipo, EquipoAuditoria::ACCION_SINCRONIZAR, [
                'desde' => $desde ?: null,
                'hasta' => $hasta ?: null,
                'detalle' => $error,
                'exito' => false,
            ]);

            return ['exito' => false, 'mensaje' => $error, 'total' => 0];
        }

        $filas = array_map(fn (array $marcacion): array => [
            'ci' => $marcacion['user_id'] ?? null,
            'momento' => filled($marcacion['timestamp'] ?? null) ? Carbon::parse($marcacion['timestamp']) : null,
        ], $todas);

        $conteo = $this->registro->registrar($filas);
        $mensaje = $this->registro->mensaje($conteo, "Sincronización de «{$equipo->nombre}»");

        EquipoAuditoria::registrar($equipo, EquipoAuditoria::ACCION_SINCRONIZAR, [
            'desde' => $desde ?: null,
            'hasta' => $hasta ?: null,
            'total_marcaciones' => count($todas),
            'detalle' => $mensaje,
        ]);

        return ['exito' => true, 'mensaje' => $mensaje, 'total' => count($todas)];
    }

    /**
     * Filtra el array de marcaciones ya traídas del equipo por rango de
     * fechas (inclusive). `$desde`/`$hasta` vacíos no filtran ese extremo.
     *
     * @param  array<int, array<string, mixed>>  $todas
     * @return array<int, array<string, mixed>>
     */
    private function filtrarPorRango(array $todas, string $desde, string $hasta): array
    {
        if ($desde === '' && $hasta === '') {
            return $todas;
        }

        $inicio = $desde !== '' ? Carbon::parse($desde)->startOfDay() : null;
        $fin = $hasta !== '' ? Carbon::parse($hasta)->endOfDay() : null;

        return array_values(array_filter($todas, function (array $marcacion) use ($inicio, $fin): bool {
            if (blank($marcacion['timestamp'] ?? null)) {
                return false;
            }

            $fecha = Carbon::parse($marcacion['timestamp']);

            return (! $inicio || $fecha->greaterThanOrEqualTo($inicio))
                && (! $fin || $fecha->lessThanOrEqualTo($fin));
        }));
    }
}
