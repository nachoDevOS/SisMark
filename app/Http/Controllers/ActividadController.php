<?php

namespace App\Http\Controllers;

use App\Models\Actividad;
use App\Models\User;
use App\Services\CatalogoActividad;
use App\Services\RegistroActividad;
use App\Services\ResolutorNombres;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Actividad de usuarios: quién entró, qué miró, qué buscó y qué sacó del
 * sistema (docs/ACTIVIDAD-DE-USUARIOS.md). Solo se consulta: la escribe
 * {@see RegistroActividad} y nadie la edita ni la borra.
 */
class ActividadController extends Controller
{
    /**
     * La línea de tiempo con sus filtros. Arranca en «Solo lo importante» y en hoy.
     *
     * El nombre del funcionario se resuelve al mostrar, solo para las CI de la
     * página y con la caché de {@see ResolutorNombres}: guardar la actividad
     * nunca consulta a Mamoré.
     */
    public function index(Request $request, ResolutorNombres $resolutor): View
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
            'nombres' => $resolutor->porCi(
                $actividades->getCollection()->map(fn (Actividad $actividad): ?string => $actividad->ciDelFuncionario())
            ),
        ]);
    }

    /**
     * Los filtros del pedido, saneados. Las fechas arrancan en hoy: sin rango la
     * consulta recorrería millones de filas.
     *
     * @return array{todo: bool, usuario: ?int, desde: string, hasta: string, modulo: string, ci: string, ip: string, errores: bool, fallidos: bool}
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
            'modulo' => in_array((string) $request->query('modulo'), CatalogoActividad::modulos(), true) ? (string) $request->query('modulo') : '',
            'ci' => trim((string) $request->query('ci', '')),
            'ip' => trim((string) $request->query('ip', '')),
            'errores' => $request->boolean('errores'),
            'fallidos' => $request->boolean('fallidos'),
        ];
    }

    /**
     * @param  array{todo: bool, usuario: ?int, desde: string, hasta: string, modulo: string, ci: string, ip: string, errores: bool, fallidos: bool}  $filtros
     * @return Builder<Actividad>
     */
    private function consulta(array $filtros): Builder
    {
        return Actividad::query()
            ->whereBetween('created_at', [
                Carbon::parse($filtros['desde'])->startOfDay(),
                Carbon::parse($filtros['hasta'])->endOfDay(),
            ])
            // «Solo lo importante» salvo que se pida todo; los errores y los
            // intentos fallidos se buscan en todo, que es donde están.
            ->when(! $filtros['todo'] && ! $filtros['errores'], fn (Builder $q) => $q->where('importante', true))
            ->when($filtros['usuario'], fn (Builder $q, int $usuario) => $q->where('user_id', $usuario))
            ->when($filtros['modulo'] !== '', fn (Builder $q) => $q->where('modulo', $filtros['modulo']))
            ->when($filtros['ci'] !== '', fn (Builder $q) => $q->where('ci', $filtros['ci']))
            ->when($filtros['ip'] !== '', fn (Builder $q) => $q->where('ip', $filtros['ip']))
            ->when($filtros['errores'], fn (Builder $q) => $q->where('estado', '>=', 400))
            ->when($filtros['fallidos'], fn (Builder $q) => $q->whereIn('accion', Actividad::FALLIDAS));
    }
}
