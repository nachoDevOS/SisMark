<?php

namespace App\Http\Controllers;

use App\Models\Actividad;
use App\Models\User;
use App\Services\CatalogoActividad;
use App\Services\ExcelActividad;
use App\Services\RegistroActividad;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Actividad de usuarios: quién entró, qué miró, qué buscó y qué sacó del
 * sistema (docs/ACTIVIDAD-DE-USUARIOS.md). Solo se consulta: la escribe
 * {@see RegistroActividad} y nadie la edita ni la borra.
 */
class ActividadController extends Controller
{
    /** Tope del Excel: lo filtrado más allá de esto se acota por fechas o usuario. */
    private const MAX_EXCEL = 10000;

    /**
     * La línea de tiempo con sus filtros. Arranca en «Solo lo importante» y en hoy.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Actividad::class);

        $filtros = $this->filtros($request);

        $actividades = $this->consulta($filtros)
            ->with(['usuario', 'sistemaExterno'])
            ->latest('created_at')
            ->latest('id')
            ->paginate($this->porPagina($request, 25))
            ->withQueryString();

        return view('actividades.index', [
            'actividades' => $actividades,
            'filtros' => $filtros,
            'usuarios' => User::withTrashed()->orderBy('name')->get(['id', 'name']),
            'modulos' => CatalogoActividad::modulos(),
            'tipos' => Actividad::TIPOS,
        ]);
    }

    /**
     * Lo filtrado, en Excel. Bajarla también queda en la actividad.
     */
    public function excel(Request $request, ExcelActividad $excel): BinaryFileResponse
    {
        $this->authorize('export', Actividad::class);

        $filtros = $this->filtros($request);
        $consulta = $this->consulta($filtros);
        $total = (clone $consulta)->count();

        $filas = $consulta
            ->with(['usuario', 'sistemaExterno'])
            ->latest('created_at')
            ->latest('id')
            ->limit(self::MAX_EXCEL)
            ->get();

        return response()
            ->download($excel->generar($filas, $total, $this->resumenFiltros($filtros)), $excel->nombreArchivo())
            ->deleteFileAfterSend();
    }

    /**
     * Los filtros del pedido, saneados. Las fechas arrancan en hoy: sin rango la
     * consulta recorrería millones de filas.
     *
     * @return array{todo: bool, usuario: ?int, desde: string, hasta: string, tipo: string, modulo: string, ci: string, ip: string, errores: bool, fallidos: bool}
     */
    private function filtros(Request $request): array
    {
        $fecha = function (string $campo) use ($request): string {
            try {
                return Carbon::parse((string) $request->query($campo, now()->toDateString()))->toDateString();
            } catch (\Throwable) {
                return now()->toDateString();
            }
        };

        $desde = $fecha('desde');
        $hasta = $fecha('hasta');

        if ($hasta < $desde) {
            [$desde, $hasta] = [$hasta, $desde];
        }

        return [
            'todo' => $request->boolean('todo'),
            'usuario' => $request->integer('usuario') ?: null,
            'desde' => $desde,
            'hasta' => $hasta,
            'tipo' => array_key_exists((string) $request->query('tipo'), Actividad::TIPOS) ? (string) $request->query('tipo') : '',
            'modulo' => in_array((string) $request->query('modulo'), CatalogoActividad::modulos(), true) ? (string) $request->query('modulo') : '',
            'ci' => trim((string) $request->query('ci', '')),
            'ip' => trim((string) $request->query('ip', '')),
            'errores' => $request->boolean('errores'),
            'fallidos' => $request->boolean('fallidos'),
        ];
    }

    /**
     * @param  array{todo: bool, usuario: ?int, desde: string, hasta: string, tipo: string, modulo: string, ci: string, ip: string, errores: bool, fallidos: bool}  $filtros
     * @return Builder<Actividad>
     */
    private function consulta(array $filtros): Builder
    {
        $accionesDelTipo = array_keys(array_filter(
            Actividad::ACCIONES,
            fn (array $accion): bool => $accion['tipo'] === $filtros['tipo'],
        ));

        return Actividad::query()
            ->whereBetween('created_at', [
                Carbon::parse($filtros['desde'])->startOfDay(),
                Carbon::parse($filtros['hasta'])->endOfDay(),
            ])
            // «Solo lo importante» salvo que se pida todo; los errores y los
            // intentos fallidos se buscan en todo, que es donde están.
            ->when(! $filtros['todo'] && ! $filtros['errores'], fn (Builder $q) => $q->where('importante', true))
            ->when($filtros['usuario'], fn (Builder $q, int $usuario) => $q->where('user_id', $usuario))
            ->when($filtros['tipo'] !== '', fn (Builder $q) => $q->whereIn('accion', $accionesDelTipo))
            ->when($filtros['modulo'] !== '', fn (Builder $q) => $q->where('modulo', $filtros['modulo']))
            ->when($filtros['ci'] !== '', fn (Builder $q) => $q->where('ci', $filtros['ci']))
            ->when($filtros['ip'] !== '', fn (Builder $q) => $q->where('ip', $filtros['ip']))
            ->when($filtros['errores'], fn (Builder $q) => $q->where('estado', '>=', 400))
            ->when($filtros['fallidos'], fn (Builder $q) => $q->whereIn('accion', Actividad::FALLIDAS));
    }

    /**
     * Los filtros en palabras, para el encabezado del Excel.
     *
     * @param  array{todo: bool, usuario: ?int, desde: string, hasta: string, tipo: string, modulo: string, ci: string, ip: string, errores: bool, fallidos: bool}  $filtros
     * @return list<string>
     */
    private function resumenFiltros(array $filtros): array
    {
        $fecha = fn (string $dia): string => Carbon::parse($dia)->format('d/m/Y');

        return array_values(array_filter([
            "Del {$fecha($filtros['desde'])} al {$fecha($filtros['hasta'])}",
            $filtros['todo'] ? 'Todos los pedidos' : 'Solo lo importante',
            $filtros['usuario'] ? 'Usuario: '.(User::withTrashed()->find($filtros['usuario'])?->name ?? $filtros['usuario']) : null,
            $filtros['tipo'] !== '' ? 'Tipo: '.Actividad::TIPOS[$filtros['tipo']] : null,
            $filtros['modulo'] !== '' ? "Módulo: {$filtros['modulo']}" : null,
            $filtros['ci'] !== '' ? "CI: {$filtros['ci']}" : null,
            $filtros['ip'] !== '' ? "IP: {$filtros['ip']}" : null,
            $filtros['errores'] ? 'Solo errores' : null,
            $filtros['fallidos'] ? 'Solo intentos fallidos' : null,
        ]));
    }
}
