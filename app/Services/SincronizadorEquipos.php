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
 * el microservicio, registran con {@see RegistroAsistencia} y dejan la entrada
 * en la bitácora.
 *
 * **La sincronización no pide rango: baja el buffer completo del reloj.** Y no
 * cuesta más que pedir un día, porque el protocolo ZK no sabe filtrar —
 * `get_attendance()` vuelca todo el historial igual, y el rango solo recortaba
 * después—. A cambio, nada queda afuera: lo que el equipo tenga guardado entra,
 * y lo que ya está en la base se descarta por la terna `(ci, fecha, hora)`. La
 * corrida es idempotente: repetirla no cambia nada.
 *
 * El rango sí se sigue usando para **ver y exportar** ({@see marcaciones()}),
 * donde el usuario está mirando una pantalla y quiere acotar.
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
     * Se lee siempre del reloj, sin caché: los dos usos —ver la pantalla y
     * exportar el CSV— tienen que traer lo del momento.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: ?string}
     */
    public function marcaciones(Equipo $equipo, string $desde = '', string $hasta = ''): array
    {
        $lectura = $this->leer($equipo, $desde, $hasta);

        return [$lectura['marcaciones'], $lectura['error']];
    }

    /**
     * Lee el reloj y devuelve, además de las marcaciones, las cifras que
     * permiten comprobar que la transferencia se completó.
     *
     * - `enEquipo`: cuántas dice el reloj que tiene guardadas, según su propio
     *   contador. `null` si no se pudo averiguar (firmware que no lo expone, o
     *   microservicio viejo): ausente no es cero, es «no se sabe».
     * - `leidas`: cuántas se leyeron del buffer, antes de filtrar por rango.
     * - `recibidas`: cuántas llegaron a Laravel, ya filtradas por el
     *   microservicio.
     *
     * `enEquipo` contra `leidas` es la prueba: si el reloj declara 1500 y se
     * leyeron 1499, la lectura se cortó por el medio. Contando solo lo que
     * llegó, eso es invisible.
     *
     * @return array{marcaciones: array<int, array<string, mixed>>, error: ?string, recibidas: int, leidas: int, enEquipo: ?int}
     */
    private function leer(Equipo $equipo, string $desde, string $hasta): array
    {
        try {
            $respuesta = $this->deviceService->attendance($equipo, $desde ?: null, $hasta ?: null);
        } catch (DeviceServiceException $e) {
            return ['marcaciones' => [], 'error' => $e->getMessage(), 'recibidas' => 0, 'leidas' => 0, 'enEquipo' => null];
        }

        $todas = $respuesta['marcaciones'] ?? [];

        return [
            'marcaciones' => $this->filtrarPorRango($todas, $desde, $hasta),
            'error' => null,
            'recibidas' => count($todas),
            // Un microservicio anterior a estas cifras no las manda: se cae al
            // conteo de lo que llegó, y la comprobación de integridad queda
            // neutralizada en vez de acusar una pérdida que no ocurrió.
            'leidas' => isset($respuesta['leidas']) ? (int) $respuesta['leidas'] : count($todas),
            'enEquipo' => isset($respuesta['en_equipo']) ? (int) $respuesta['en_equipo'] : null,
        ];
    }

    /**
     * Baja **todo** el historial del reloj y registra en `asistencias` lo que
     * falte, dejando la acción en la bitácora (también cuando falla la lectura)
     * con las dos cuentas que la hacen auditable:
     *
     *     en el reloj = llegaron + se perdieron en el camino
     *     llegaron    = nuevas + repetidas + sin funcionario + fallidas
     *
     * La primera mide el transporte, la segunda el destino. Una corrida que
     * trajo 1499 de 1500 se marca fallida aunque las 1499 se hayan guardado
     * bien: falta una marcación de alguien.
     *
     * @return array{exito: bool, completa: ?bool, mensaje: string, total: int, enEquipo: ?int, perdidas: ?int, conteo: array{insertadas: int, existentes: int, sinFuncionario: int, invalidas: int}}
     */
    public function sincronizar(Equipo $equipo): array
    {
        // Sin rango: el buffer entero. Ver la nota de clase.
        $lectura = $this->leer($equipo, '', '');

        if ($lectura['error']) {
            EquipoAuditoria::registrar($equipo, EquipoAuditoria::ACCION_SINCRONIZAR, [
                'detalle' => $lectura['error'],
                'exito' => false,
            ]);

            return [
                'exito' => false,
                'completa' => null,
                'mensaje' => $lectura['error'],
                'total' => 0,
                'enEquipo' => null,
                'perdidas' => null,
                'conteo' => ['insertadas' => 0, 'existentes' => 0, 'sinFuncionario' => 0, 'invalidas' => 0],
            ];
        }

        $todas = $lectura['marcaciones'];

        $filas = array_map(fn (array $marcacion): array => [
            'ci' => $marcacion['user_id'] ?? null,
            'momento' => filled($marcacion['timestamp'] ?? null) ? Carbon::parse($marcacion['timestamp']) : null,
        ], $todas);

        $conteo = $this->registro->registrar($filas, $equipo);

        $perdidas = $this->perdidas($lectura);
        $completa = $perdidas === null ? null : $perdidas === 0;

        $mensaje = $this->registro->mensaje($conteo, "Sincronización de «{$equipo->nombre}»");

        if ($perdidas !== null && $perdidas > 0) {
            $mensaje .= " El reloj declara {$lectura['enEquipo']} marcación(es) y llegaron {$lectura['leidas']}: "
                ."faltan {$perdidas}. La lectura quedó incompleta y se reintenta en la próxima corrida.";
        }

        // El desglose va en columnas y no solo dentro del texto de `detalle`:
        // así la bitácora se puede leer de un vistazo y, sobre todo, sumar. Un
        // equipo que trae 400 marcaciones y las 400 son repetidas está tan
        // «sincronizado» como uno que trae 400 nuevas, y con una sola cifra los
        // dos casos se ven idénticos.
        EquipoAuditoria::registrar($equipo, EquipoAuditoria::ACCION_SINCRONIZAR, [
            // Lo que el reloj dice que tiene. Es la única cifra que no sale de
            // contar lo que llegó, y por eso es la que delata una lectura
            // cortada por el medio.
            'en_equipo' => $lectura['enEquipo'],
            // Lo que llegó a SisMark. Las cinco columnas de abajo reparten este
            // total y tienen que sumarlo exacto.
            'total_marcaciones' => $lectura['recibidas'],
            // Sin rango pedido nunca se recorta nada; queda en cero y así se ve
            // que el total llegó entero a repartirse.
            'fuera_de_rango' => $lectura['recibidas'] - count($todas),
            'nuevas' => $conteo['insertadas'],
            'repetidas' => $conteo['existentes'],
            'sin_funcionario' => $conteo['sinFuncionario'],
            'fallidas' => $conteo['invalidas'],
            'detalle' => $mensaje,
            'exito' => $completa !== false,
        ]);

        return [
            'exito' => true,
            'completa' => $completa,
            'mensaje' => $mensaje,
            'total' => count($todas),
            'enEquipo' => $lectura['enEquipo'],
            'perdidas' => $perdidas,
            'conteo' => $conteo,
        ];
    }

    /**
     * Cuántas marcaciones tenía el reloj que no llegaron a leerse, o `null` si
     * no hay con qué comparar.
     *
     * Nunca negativo: un contador **menor** que lo entregado no es una pérdida.
     * Pasa con firmware viejo después de un corte de luz, y no es motivo para
     * marcar como fallida una corrida que trajo datos de más.
     *
     * @param  array{leidas: int, enEquipo: ?int}  $lectura
     */
    private function perdidas(array $lectura): ?int
    {
        if ($lectura['enEquipo'] === null) {
            return null;
        }

        return max(0, $lectura['enEquipo'] - $lectura['leidas']);
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
