<?php

namespace App\Services;

use App\Models\Actividad;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Qué pedido es un evento importante y cómo se dice en castellano.
 *
 * Todo pedido se registra (ver {@see RegistroActividad}); los que están acá
 * llevan además una frase, la CI del funcionario y la marca `importante`, para
 * que la pantalla se lea como una historia. Lo que no está acá queda como
 * detalle. Ver docs/ACTIVIDAD-DE-USUARIOS.md, sección 5.2.
 */
class CatalogoActividad
{
    /**
     * Módulo de cada prefijo de nombre de ruta.
     *
     * @var array<string, string>
     */
    private const MODULOS = [
        'dashboard' => 'Escritorio',
        'funcionarios' => 'Funcionarios',
        'marcaciones' => 'Marcaciones',
        'licencias' => 'Licencias',
        'turnos' => 'Turnos',
        'turnos-asignados' => 'Turnos',
        'horarios-asignados' => 'Turnos',
        'dias-excepcionales' => 'Días excepcionales',
        'reportes' => 'Reportes',
        'equipos' => 'Biométricos',
        'usuarios' => 'Usuarios',
        'roles' => 'Roles',
        'tokens-api' => 'Tokens de API',
        'configuracion' => 'Configuración',
        'manual' => 'Ayuda',
        'actividades' => 'Actividad',
        'login' => 'Acceso',
        'logout' => 'Acceso',
        'password' => 'Acceso',
    ];

    /**
     * El módulo del pedido: por el prefijo de su ruta, o API si vino por `api/`.
     */
    public function modulo(Request $request): string
    {
        if ($request->is('api/*')) {
            return 'API';
        }

        $ruta = (string) $request->route()?->getName();

        return self::MODULOS[strtok($ruta, '.') ?: ''] ?? 'Sistema';
    }

    /**
     * La CI del funcionario sobre el que va el pedido, cuando viene en la ruta
     * (`/api/v1/funcionarios/{ci}/…`, la ficha de Mamoré) o en la query (las
     * pestañas de la ficha). Así también el detalle se puede buscar por CI y
     * mostrar con nombre. No consulta a nadie: solo lee el pedido.
     */
    public function ciDelPedido(Request $request): ?string
    {
        $valor = $request->route('ci') ?? $request->query('ci');

        return is_string($valor) ? ($this->ci($valor) ?: null) : null;
    }

    /**
     * La acción, la frase y la CI si el pedido tiene una; `null` si es detalle
     * sin frase. Solo se describe lo que salió bien: una ficha que dio 404 no se
     * «miró».
     *
     * Las consultas de la API llevan frase pero siguen siendo detalle
     * (`importante` en falso): un sistema externo consulta mucho y no es una
     * persona mirando. Lo que la API escribe lo anota su propio controlador,
     * que es el que sabe qué cambió.
     *
     * @return array{accion: string, descripcion: string, ci: ?string, importante: bool}|null
     */
    public function describir(Request $request, int $estado): ?array
    {
        if ($estado >= 400 || ! $request->isMethod('GET')) {
            return null;
        }

        $ruta = (string) $request->route()?->getName();
        $q = trim((string) $request->query('q', ''));

        return match ($ruta) {
            'funcionarios.show', 'funcionarios.mamore' => $this->fichaFuncionario($request),
            'funcionarios.list' => $q === '' ? null : $this->evento(Actividad::BUSCAR, "Buscó «{$q}» en Funcionarios"),
            'licencias.show' => $this->licencia($request),
            'licencias.list' => $q === '' ? null : $this->evento(Actividad::BUSCAR, "Buscó «{$q}» en Licencias"),
            'turnos-asignados.list' => $q === '' ? null : $this->evento(Actividad::BUSCAR, "Buscó «{$q}» en Turnos asignados"),
            'reportes.marcaciones.procesado.generar' => $this->reporte($request, 'el reporte procesado de CI '.$this->ci($request->query('persona')), $this->ci($request->query('persona'))),
            'reportes.marcaciones.sin-procesar.generar' => $this->reporte($request, 'las marcaciones sin procesar de CI '.$this->ci($request->query('persona')), $this->ci($request->query('persona'))),
            'reportes.marcaciones.direccion.generar' => $this->reporte($request, 'el reporte por dirección '.$this->direccion($request)),
            'reportes.funcionarios.sin-turno.generar' => $this->reporte($request, 'los funcionarios sin turno de '.$this->sinTurno($request), null, false),
            'funcionarios.reporte' => $this->evento(Actividad::IMPRIMIR, 'Imprimió el reporte de marcaciones de CI '.$this->ciDeRuta($request, 'persona'), $this->ciDeRuta($request, 'persona')),
            'equipos.marcaciones.exportar' => $this->evento(Actividad::EXPORTAR, 'Bajó las marcaciones del equipo «'.($request->route('equipo')?->nombre ?? '').'»'),
            'usuarios.historial' => $this->evento(Actividad::VER, 'Miró el historial del usuario «'.($request->route('usuario')?->name ?? '').'»'),
            'manual.archivo' => $this->tutorial($request),
            'api.turnos.sugeridos' => $this->consultaApi('Consultó los turnos sugeridos'),
            'api.funcionarios.marcaciones' => $this->consultaApi('Consultó las marcaciones de CI '.$this->ciDeRuta($request, 'ci').$this->rango($request), $this->ciDeRuta($request, 'ci')),
            'api.funcionarios.asistencia' => $this->consultaApi('Consultó la asistencia de CI '.$this->ciDeRuta($request, 'ci').$this->rango($request), $this->ciDeRuta($request, 'ci')),
            'api.funcionarios.licencias' => $this->consultaApi('Consultó las licencias de CI '.$this->ciDeRuta($request, 'ci').$this->rango($request), $this->ciDeRuta($request, 'ci')),
            'api.funcionarios.licencias.show' => $this->consultaApi('Consultó la licencia #'.$this->idDeRuta($request, 'licencia').' de CI '.$this->ciDeRuta($request, 'ci'), $this->ciDeRuta($request, 'ci')),
            'api.funcionarios.licencias.respaldo' => $this->consultaApi('Pidió el respaldo de la licencia #'.$this->idDeRuta($request, 'licencia').' de CI '.$this->ciDeRuta($request, 'ci'), $this->ciDeRuta($request, 'ci')),
            'api.funcionarios.horarios.index' => $this->consultaApi('Consultó los horarios de CI '.$this->ciDeRuta($request, 'ci'), $this->ciDeRuta($request, 'ci')),
            default => null,
        };
    }

    /**
     * @return array{accion: string, descripcion: string, ci: ?string, importante: bool}
     */
    private function evento(string $accion, string $descripcion, ?string $ci = null, bool $importante = true): array
    {
        return ['accion' => $accion, 'descripcion' => mb_substr($descripcion, 0, 500), 'ci' => $ci, 'importante' => $importante];
    }

    /**
     * Una consulta de la API: con frase, pero detalle.
     *
     * @return array{accion: string, descripcion: string, ci: ?string, importante: bool}
     */
    private function consultaApi(string $descripcion, ?string $ci = null): array
    {
        return $this->evento(Actividad::PEDIDO, $descripcion, $ci ?: null, importante: false);
    }

    /**
     * « del 01/10/2026 al 27/10/2026», o nada si el pedido no trae fechas.
     */
    private function rango(Request $request): string
    {
        if (blank($request->query('desde')) && blank($request->query('hasta'))) {
            return '';
        }

        return ' del '.$this->fecha($request->query('desde')).' al '.$this->fecha($request->query('hasta'));
    }

    private function idDeRuta(Request $request, string $parametro): string
    {
        $valor = $request->route($parametro);

        return mb_substr(trim((string) (is_object($valor) ? $valor->getKey() : $valor)), 0, 20);
    }

    /**
     * @return array{accion: string, descripcion: string, ci: ?string, importante: bool}
     */
    private function fichaFuncionario(Request $request): array
    {
        $ci = $request->routeIs('funcionarios.mamore')
            ? $this->ci($request->route('ci'))
            : $this->ciDeRuta($request, 'persona');

        return $this->evento(Actividad::VER, "Miró la ficha del funcionario CI {$ci}", $ci);
    }

    /**
     * @return array{accion: string, descripcion: string, ci: ?string, importante: bool}
     */
    private function licencia(Request $request): array
    {
        $licencia = $request->route('licencia');
        $ci = $this->ci($licencia?->ci);
        $fecha = $this->fecha($licencia?->fecha);

        return $this->evento(Actividad::VER, "Abrió la licencia del {$fecha} de CI {$ci}", $ci);
    }

    /**
     * `print`: 0 en pantalla, 1 para imprimir, 2 en Excel.
     *
     * @return array{accion: string, descripcion: string, ci: ?string, importante: bool}
     */
    private function reporte(Request $request, string $que, ?string $ci = null, bool $conRango = true): array
    {
        [$accion, $verbo] = match ((int) $request->query('print', 0)) {
            1 => [Actividad::IMPRIMIR, 'Imprimió'],
            2 => [Actividad::EXPORTAR, 'Bajó en Excel'],
            default => [Actividad::REPORTE, 'Generó'],
        };

        $rango = $conRango ? ', '.$this->fecha($request->query('desde')).' al '.$this->fecha($request->query('hasta')) : '';

        return $this->evento($accion, "{$verbo} {$que}{$rango}", $ci ?: null);
    }

    /**
     * Solo la primera vista de un video: el reproductor lo pide por rangos y
     * cada salto es otro pedido, que queda como detalle.
     *
     * @return array{accion: string, descripcion: string, ci: ?string, importante: bool}|null
     */
    private function tutorial(Request $request): ?array
    {
        $rango = (string) $request->header('Range', '');

        if ($request->route('extension') !== 'mp4' || ($rango !== '' && ! str_starts_with($rango, 'bytes=0-'))) {
            return null;
        }

        $titulo = ManualUsuario::TUTORIALES[$request->route('tutorial')]['titulo'] ?? $request->route('tutorial');

        return $this->evento(Actividad::VER_TUTORIAL, "Miró el tutorial «{$titulo}»");
    }

    private function direccion(Request $request): string
    {
        $nombre = trim((string) $request->query('nombre', '')) ?: 'sin nombre';
        $unidad = trim((string) $request->query('nombreUnidad', ''));

        return $unidad === '' ? $nombre : "{$nombre} / {$unidad}";
    }

    private function sinTurno(Request $request): string
    {
        return trim((string) $request->query('nombreDireccion', '')) ?: 'toda la Gobernación';
    }

    private function ciDeRuta(Request $request, string $parametro): string
    {
        $valor = $request->route($parametro);

        return $this->ci(is_object($valor) ? $valor->ci : $valor);
    }

    private function ci(mixed $valor): string
    {
        return mb_substr(trim((string) $valor), 0, 20);
    }

    private function fecha(mixed $valor): string
    {
        $texto = trim((string) $valor);

        if ($texto === '') {
            return '—';
        }

        // Una fecha mal escrita en la URL no puede tirar el registro del pedido.
        try {
            return Carbon::parse($texto)->format('d/m/Y');
        } catch (\Throwable) {
            return $texto;
        }
    }
}
