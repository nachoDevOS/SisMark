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
        [$filtradas, $error] = $this->leer($equipo, $desde, $hasta);

        return [$filtradas, $error];
    }

    /**
     * Igual que {@see marcaciones()}, pero devuelve además **cuántas entregó el
     * reloj antes de aplicar el filtro por rango**.
     *
     * Las dos cifras hacen falta para que la bitácora cierre. El equipo manda de
     * más —el protocolo ZK no siempre respeta el rango, y el RTC con la batería
     * gastada devuelve marcaciones con años tipo 2064—, y esas se recortan acá.
     * Contando solo las que sobreviven al filtro, la marcación descartada
     * desaparece sin quedar registrada en ningún lado y el desglose no suma.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: ?string, 2: int}
     */
    private function leer(Equipo $equipo, string $desde, string $hasta): array
    {
        try {
            $todas = $this->deviceService->attendance($equipo, $desde ?: null, $hasta ?: null)['marcaciones'] ?? [];
        } catch (DeviceServiceException $e) {
            return [[], $e->getMessage(), 0];
        }

        return [$this->filtrarPorRango($todas, $desde, $hasta), null, count($todas)];
    }

    /**
     * Baja las marcaciones del equipo y las registra en `asistencias`, dejando
     * la acción en la bitácora (también cuando falla la lectura del reloj) con
     * el desglose de qué pasó con cada marcación que entregó el reloj.
     *
     * @return array{exito: bool, mensaje: string, total: int, conteo: array{insertadas: int, existentes: int, sinFuncionario: int, invalidas: int}}
     */
    public function sincronizar(Equipo $equipo, string $desde = '', string $hasta = ''): array
    {
        [$todas, $error, $entregadas] = $this->leer($equipo, $desde, $hasta);

        if ($error) {
            EquipoAuditoria::registrar($equipo, EquipoAuditoria::ACCION_SINCRONIZAR, [
                'desde' => $desde ?: null,
                'hasta' => $hasta ?: null,
                'detalle' => $error,
                'exito' => false,
            ]);

            return [
                'exito' => false,
                'mensaje' => $error,
                'total' => 0,
                'conteo' => ['insertadas' => 0, 'existentes' => 0, 'sinFuncionario' => 0, 'invalidas' => 0],
            ];
        }

        $filas = array_map(fn (array $marcacion): array => [
            'ci' => $marcacion['user_id'] ?? null,
            'momento' => filled($marcacion['timestamp'] ?? null) ? Carbon::parse($marcacion['timestamp']) : null,
        ], $todas);

        $conteo = $this->registro->registrar($filas, $equipo);
        $mensaje = $this->registro->mensaje($conteo, "Sincronización de «{$equipo->nombre}»");

        // El desglose va en columnas y no solo dentro del texto de `detalle`:
        // así la bitácora se puede leer de un vistazo y, sobre todo, sumar. Un
        // equipo que trae 400 marcaciones y las 400 son repetidas está tan
        // «sincronizado» como uno que trae 400 nuevas, y con una sola cifra los
        // dos casos se ven idénticos.
        EquipoAuditoria::registrar($equipo, EquipoAuditoria::ACCION_SINCRONIZAR, [
            'desde' => $desde ?: null,
            'hasta' => $hasta ?: null,
            // Lo que entregó el reloj, sin recortar: es «cuántos datos hay en el
            // equipo». Las cinco columnas de abajo reparten ese total y tienen
            // que sumarlo exacto.
            'total_marcaciones' => $entregadas,
            'fuera_de_rango' => $entregadas - count($todas),
            'nuevas' => $conteo['insertadas'],
            'repetidas' => $conteo['existentes'],
            'sin_funcionario' => $conteo['sinFuncionario'],
            'fallidas' => $conteo['invalidas'],
            'detalle' => $mensaje,
        ]);

        return ['exito' => true, 'mensaje' => $mensaje, 'total' => count($todas), 'conteo' => $conteo];
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
