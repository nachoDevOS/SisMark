<?php

namespace App\Services;

use App\Models\Actividad;
use App\Models\SistemaExterno;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * Escribe la actividad de usuarios. Nadie más escribe en `actividades`.
 *
 * Dos entradas: {@see registrarPedido()} para cada pedido (lo llama el
 * middleware RegistrarActividad al terminar) y {@see registrarEvento()} para lo
 * que no es un pedido entero: ingresos, intentos fallidos, salidas.
 *
 * Nunca guarda contraseñas ni tokens (ver {@see limpiar()}), y si algo falla al
 * guardar, el pedido sigue: el error va al log. Ver docs/ACTIVIDAD-DE-USUARIOS.md.
 */
class RegistroActividad
{
    /** Lo que se tapa: cualquier campo cuyo nombre contenga alguna de estas. */
    private const SENSIBLES = ['password', 'clave', 'token', 'secret'];

    private const TAPADO = '••••';

    /** Tope de `datos`: un formulario enorme no infla la tabla. */
    private const MAX_DATOS = 10240;

    public function __construct(private CatalogoActividad $catalogo) {}

    /**
     * Un pedido entero. Si es un evento importante del catálogo, con su frase.
     */
    public function registrarPedido(Request $request, int $estado, int $duracionMs): void
    {
        $this->guardar(function () use ($request, $estado, $duracionMs): void {
            $evento = $this->catalogo->describir($request, $estado);

            Actividad::create([
                ...$this->quien($request),
                ...$this->contexto($request),
                'importante' => $evento['importante'] ?? false,
                'accion' => $evento['accion'] ?? Actividad::PEDIDO,
                'modulo' => $this->catalogo->modulo($request),
                'descripcion' => $evento['descripcion'] ?? null,
                'ci' => ($evento['ci'] ?? null) ?: $this->catalogo->ciDelPedido($request),
                'datos' => $this->recortar($this->limpiar($request->all())),
                'estado' => $estado,
                'duracion_ms' => $duracionMs,
            ]);
        });
    }

    /**
     * Un evento suelto dentro del pedido en curso: siempre importante.
     *
     * @param  array<string, mixed>  $datos
     */
    public function registrarEvento(
        string $accion,
        string $modulo,
        string $descripcion,
        ?User $usuario = null,
        ?string $email = null,
        ?string $ci = null,
        array $datos = [],
    ): void {
        $this->guardar(function () use ($accion, $modulo, $descripcion, $usuario, $email, $ci, $datos): void {
            $request = request();
            $quien = $this->quien($request);

            Actividad::create([
                ...$quien,
                ...$this->contexto($request),
                // En un ingreso el usuario todavía no está en el pedido: llega en el evento.
                'user_id' => $usuario?->getKey() ?? $quien['user_id'],
                'email' => $email === null ? null : mb_substr($email, 0, 150),
                'importante' => true,
                'accion' => $accion,
                'modulo' => $modulo,
                'descripcion' => mb_substr($descripcion, 0, 500),
                'ci' => $ci,
                'datos' => $datos === [] ? null : $this->recortar($this->limpiar($datos)),
                // El pedido que lo contiene se registra aparte, con su estado y su tiempo.
                'estado' => 0,
                'duracion_ms' => 0,
            ]);
        });
    }

    /**
     * Tapa todo campo sensible, en cualquier nivel, y cambia los archivos por
     * su nombre, tipo y tamaño.
     *
     * @param  array<array-key, mixed>  $datos
     * @return array<array-key, mixed>
     */
    public function limpiar(array $datos): array
    {
        $limpio = [];

        foreach ($datos as $clave => $valor) {
            $limpio[$clave] = match (true) {
                $this->esSensible((string) $clave) => self::TAPADO,
                $valor instanceof UploadedFile => [
                    'archivo' => $valor->getClientOriginalName(),
                    'tipo' => $valor->getClientMimeType(),
                    'tamaño' => $valor->getSize(),
                ],
                is_array($valor) => $this->limpiar($valor),
                default => $valor,
            };
        }

        return $limpio;
    }

    /**
     * La URL sin dominio y con la query limpia: un `?token=` también se tapa.
     */
    public function urlLimpia(Request $request): string
    {
        $query = $this->limpiar($request->query());
        $url = '/'.ltrim($request->path(), '/').($query === [] ? '' : '?'.http_build_query($query));

        return mb_substr(rawurldecode($url), 0, 500);
    }

    private function esSensible(string $clave): bool
    {
        $clave = mb_strtolower($clave);

        foreach (self::SENSIBLES as $sensible) {
            if (str_contains($clave, $sensible)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Quién hizo el pedido: una persona con sesión o un sistema externo con su
     * token de la API.
     *
     * @return array{user_id: ?int, sistema_externo_id: ?int}
     */
    private function quien(Request $request): array
    {
        $autenticado = $request->user();

        return [
            'user_id' => $autenticado instanceof User ? $autenticado->getKey() : null,
            'sistema_externo_id' => $autenticado instanceof SistemaExterno ? $autenticado->getKey() : null,
        ];
    }

    /**
     * @return array{ruta: ?string, url: string, metodo: string, ip: ?string, navegador: ?string}
     */
    private function contexto(Request $request): array
    {
        return [
            'ruta' => $request->route()?->getName(),
            'url' => $this->urlLimpia($request),
            'metodo' => $request->method(),
            'ip' => $request->ip(),
            'navegador' => mb_substr((string) $request->userAgent(), 0, 255) ?: null,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $datos
     * @return array<array-key, mixed>|null
     */
    private function recortar(array $datos): ?array
    {
        if ($datos === []) {
            return null;
        }

        if (strlen((string) json_encode($datos)) <= self::MAX_DATOS) {
            return $datos;
        }

        return ['recortado' => true, 'campos' => array_slice(array_keys($datos), 0, 50)];
    }

    /**
     * Registrar nunca corta el trabajo de nadie: si falla, queda en el log.
     */
    private function guardar(callable $escribir): void
    {
        try {
            $escribir();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
