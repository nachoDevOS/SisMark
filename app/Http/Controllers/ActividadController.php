<?php

namespace App\Http\Controllers;

use App\Models\Actividad;
use App\Models\User;
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
     * Los métodos HTTP del filtro: todos los que usan las rutas del sistema.
     *
     * @var list<string>
     */
    private const METODOS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

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
            'metodos' => self::METODOS,
            'nombres' => $resolutor->porCi(
                $actividades->getCollection()->map(fn (Actividad $actividad): ?string => $actividad->ciDelFuncionario())
            ),
        ]);
    }

    /**
     * Los filtros del pedido, saneados. Las fechas arrancan en hoy: sin rango la
     * consulta recorrería millones de filas.
     *
     * @return array{todo: bool, quien: string, desde: string, hasta: string, ci: string, metodo: string, errores: bool, fallidos: bool}
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
            'quien' => preg_match('/^(personas|sistemas|usuario:\d+)$/', (string) $request->query('quien')) === 1 ? (string) $request->query('quien') : '',
            'desde' => $desde,
            'hasta' => $hasta,
            'ci' => trim((string) $request->query('ci', '')),
            'metodo' => in_array((string) $request->query('metodo'), self::METODOS, true) ? (string) $request->query('metodo') : '',
            'errores' => $request->boolean('errores'),
            'fallidos' => $request->boolean('fallidos'),
        ];
    }

    /**
     * @param  array{todo: bool, quien: string, desde: string, hasta: string, ci: string, metodo: string, errores: bool, fallidos: bool}  $filtros
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
            ->when($filtros['quien'] !== '', fn (Builder $q) => $this->filtrarQuien($q, $filtros['quien']))
            ->when($filtros['ci'] !== '', fn (Builder $q) => $q->where('ci', $filtros['ci']))
            ->when($filtros['metodo'] !== '', fn (Builder $q) => $q->where('metodo', $filtros['metodo']))
            ->when($filtros['errores'], fn (Builder $q) => $q->where('estado', '>=', 400))
            ->when($filtros['fallidos'], fn (Builder $q) => $q->whereIn('accion', Actividad::FALLIDAS));
    }

    /**
     * «Personas de SisMark» es todo lo que no vino con el token de un sistema
     * externo: también los intentos de ingreso, que todavía no tienen usuario.
     *
     * @param  Builder<Actividad>  $consulta
     * @return Builder<Actividad>
     */
    private function filtrarQuien(Builder $consulta, string $quien): Builder
    {
        [$lado, $id] = array_pad(explode(':', $quien, 2), 2, null);

        return match ($lado) {
            'personas' => $consulta->whereNull('sistema_externo_id'),
            'sistemas' => $consulta->whereNotNull('sistema_externo_id'),
            'usuario' => $consulta->where('user_id', (int) $id),
            default => $consulta,
        };
    }
}
