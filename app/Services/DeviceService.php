<?php

namespace App\Services;

use App\Exceptions\DeviceServiceException;
use App\Models\Equipo;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Cliente HTTP hacia el microservicio de dispositivos (Python/FastAPI).
 *
 * Es el único punto por el que Laravel se comunica con los equipos biométricos.
 * No abre sockets: traduce cada operación a una petición HTTP autenticada con el
 * token compartido, y convierte los errores del microservicio en excepciones
 * con mensajes claros para el usuario.
 */
class DeviceService
{
    /**
     * URL base del microservicio, sin barra final.
     */
    private readonly string $baseUrl;

    /**
     * Token compartido enviado en el encabezado X-Auth-Token.
     */
    private readonly ?string $token;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.device_service.url'), '/');
        $this->token = config('services.device_service.token');
    }

    /**
     * Trae la información de identificación de un equipo (nombre, serial,
     * plataforma, firmware y firma de algoritmo).
     *
     * @return array<string, mixed>
     *
     * @throws DeviceServiceException
     */
    public function info(Equipo $equipo): array
    {
        return $this->get('/device/info', $equipo);
    }

    /**
     * Trae las marcaciones (registros de asistencia) guardadas en el equipo,
     * de la más reciente a la más antigua.
     *
     * Si se pasan `$desde`/`$hasta` (YYYY-MM-DD), el microservicio filtra por
     * rango antes de responder: en equipos con historial largo, Laravel recibe
     * y parsea mucho menos. Las marcaciones se leen en vivo en cada llamada.
     *
     * @return array<string, mixed>
     *
     * @throws DeviceServiceException
     */
    public function attendance(Equipo $equipo, ?string $desde = null, ?string $hasta = null): array
    {
        return $this->get('/device/attendance', $equipo, array_filter([
            'desde' => $desde,
            'hasta' => $hasta,
        ], fn (?string $valor): bool => filled($valor)));
    }

    /**
     * Borra del equipo TODAS las marcaciones guardadas en su buffer.
     *
     * El protocolo ZK no permite borrar por rango: la única operación que
     * existe vacía el historial entero del reloj. Es irreversible, así que
     * conviene exportar o sincronizar antes de llamarla. Los usuarios y sus
     * huellas no se tocan.
     *
     * @return array<string, mixed>
     *
     * @throws DeviceServiceException
     */
    public function clearAttendance(Equipo $equipo): array
    {
        return $this->post('/device/attendance/clear', $equipo);
    }

    /**
     * Ejecuta un GET autenticado contra el microservicio para un equipo dado.
     *
     * @param  array<string, mixed>  $extra  Parámetros de query adicionales.
     * @return array<string, mixed>
     *
     * @throws DeviceServiceException
     */
    private function get(string $path, Equipo $equipo, array $extra = []): array
    {
        return $this->peticion('get', $path, $equipo, $extra);
    }

    /**
     * Ejecuta un POST autenticado contra el microservicio para un equipo dado.
     *
     * Los parámetros del equipo viajan igual que en el GET (query string): el
     * microservicio los declara como `Query(...)` en todos sus endpoints.
     *
     * @param  array<string, mixed>  $extra  Parámetros de query adicionales.
     * @return array<string, mixed>
     *
     * @throws DeviceServiceException
     */
    private function post(string $path, Equipo $equipo, array $extra = []): array
    {
        return $this->peticion('post', $path, $equipo, $extra);
    }

    /**
     * Arma y ejecuta la petición al microservicio, traduciendo los fallos a
     * DeviceServiceException con un mensaje entendible.
     *
     * @param  'get'|'post'  $metodo
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     *
     * @throws DeviceServiceException
     */
    private function peticion(string $metodo, string $path, Equipo $equipo, array $extra = []): array
    {
        $parametros = [
            'ip' => $equipo->ip,
            'port' => $equipo->puerto,
            'password' => $equipo->comm_key,
            ...$extra,
        ];

        try {
            $peticion = Http::withHeaders(['X-Auth-Token' => $this->token])
                ->connectTimeout(5) // El microservicio debe estar arriba en la red interna.
                ->timeout(60); // Leer usuarios + marcaciones de equipos con historial largo puede tardar.

            $response = $metodo === 'post'
                ? $peticion->post($this->baseUrl.$path.'?'.http_build_query($parametros))
                : $peticion->get($this->baseUrl.$path, $parametros);
        } catch (ConnectionException $e) {
            // No se pudo ni contactar al microservicio (apagado o URL mal configurada).
            throw new DeviceServiceException(
                'No se pudo contactar al microservicio de dispositivos. Verifica que esté encendido.',
                previous: $e,
            );
        }

        if ($response->failed()) {
            // El microservicio respondió con error (ej. 503 equipo caído, 401 token malo).
            $detalle = $response->json('detail') ?? 'El microservicio devolvió un error inesperado.';

            throw new DeviceServiceException($detalle);
        }

        return $response->json();
    }
}
