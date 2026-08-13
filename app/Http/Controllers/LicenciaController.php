<?php

namespace App\Http\Controllers;

use App\Exceptions\MamoreException;
use App\Http\Requests\StoreLicenciaRequest;
use App\Models\AsignacionTurno;
use App\Models\Licencia;
use App\Services\DirectorioMamore;
use App\Services\RegistroLicencia;
use App\Services\ResolutorNombres;
use App\Services\RespaldoLicencia;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Licencias/permisos de personal sobre la base local MySQL, con el flujo del
 * sistema de escritorio: se elige el funcionario, se marcan sus turnos
 * asignados y un rango de fechas, y se anotan las licencias resultantes (una
 * por día y turno). Eliminación lógica, como todo el sistema.
 */
class LicenciaController extends Controller
{
    /**
     * Pantalla del listado (browse): el «shell» con los filtros. La tabla se
     * carga por AJAX contra `list()`.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Licencia::class);

        $busqueda = trim((string) $request->query('q', ''));
        $porPagina = $this->porPagina($request);

        return view('licencias.index', compact('busqueda', 'porPagina'));
    }

    /**
     * Devuelve el parcial de la tabla (filas + paginación) para el AJAX.
     */
    public function list(Request $request, ResolutorNombres $resolutor): View
    {
        $this->authorize('viewAny', Licencia::class);

        $busqueda = trim((string) $request->query('q', ''));
        $porPagina = $this->porPagina($request);

        $licencias = Licencia::query()
            ->with('turno')
            ->when($busqueda !== '', fn (Builder $query) => $query->buscar($busqueda))
            ->orderByDesc('fecha')
            ->orderBy('idTurno')
            ->paginate($porPagina)
            ->withQueryString();

        // La columna «Funcionario» (nombre y cargo) sale de Mamoré y, si el CI no
        // está ahí, de la base local (App\Services\ResolutorNombres).
        $fichas = $resolutor->fichasPorCi($licencias->pluck('ci'));

        return view('licencias.list', compact('licencias', 'fichas', 'busqueda'));
    }

    /**
     * Pantalla «Licenciar»: combo de funcionario y, si ya hay uno elegido, la
     * grilla de sus turnos asignados más el formulario del rango.
     *
     * Los datos personales salen exclusivamente de la API de Mamoré; lo local
     * son los turnos, que se cruzan por CI.
     */
    public function create(Request $request, DirectorioMamore $directorio): View
    {
        $this->authorize('create', Licencia::class);

        $ci = trim((string) $request->query('ci', ''));
        $errorMamore = null;
        $persona = null;

        if ($ci !== '') {
            try {
                $persona = $directorio->porCi($ci);
            } catch (MamoreException $e) {
                $errorMamore = $e->getMessage();
            }
        }

        // Por defecto solo los turnos vigentes: un funcionario antiguo arrastra
        // decenas de asignaciones viejas que solo son ruido.
        $incluirVencidos = $request->boolean('vencidos');

        $asignaciones = $persona !== null
            ? $this->turnosAsignados($persona['ci'], $incluirVencidos)
            : collect();

        $vencidos = $persona !== null
            ? $this->contarVencidos($persona['ci'])
            : 0;

        // Si el alta grupal volvió por un error de validación, se rearman las
        // fichas de funcionarios ya elegidos con su nombre.
        [$elegidos, $errorElegidos] = $this->fichasElegidas($directorio);
        $errorMamore ??= $errorElegidos;

        // El CI vino por la URL y Mamoré no lo tiene: no hay a quién licenciar.
        $ciDesconocido = $ci !== '' && $persona === null && $errorMamore === null;

        return view('licencias.create', compact(
            'persona', 'ci', 'asignaciones', 'incluirVencidos', 'vencidos', 'elegidos', 'errorMamore', 'ciDesconocido'
        ));
    }

    /**
     * Búsqueda de funcionarios por CI o nombre para el combo de la pantalla
     * «Licenciar», contra la API de Mamoré. Devuelve hasta 20 coincidencias como
     * JSON, o un 502 con el motivo si la API no responde.
     */
    public function buscarFuncionarios(Request $request, DirectorioMamore $directorio): JsonResponse
    {
        $this->authorize('create', Licencia::class);

        $q = trim((string) $request->query('q', ''));

        if ($q === '') {
            return response()->json([]);
        }

        try {
            $funcionarios = $directorio->buscar($q);
        } catch (MamoreException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        return response()->json($funcionarios->map(fn (array $persona): array => [
            'id' => $persona['ci'],
            'texto' => $directorio->etiqueta($persona),
        ])->values());
    }

    /**
     * Anota las licencias del rango, para un funcionario, varios o todos los que
     * tengan turno dentro del rango. La expansión (un registro por funcionario,
     * día y turno) la hace el servicio; la validación, el Request.
     */
    public function store(StoreLicenciaRequest $request, RegistroLicencia $registro, RespaldoLicencia $respaldos): RedirectResponse
    {
        $datos = $request->validated();

        $modo = $datos['modo'];
        $desde = Carbon::parse($datos['desde'])->startOfDay();
        $hasta = Carbon::parse($datos['hasta'])->startOfDay();
        // Los turnos elegidos a mano solo aplican al alta de un funcionario.
        $elegidas = $modo === 'uno' ? ($datos['asignaciones'] ?? []) : [];

        $cis = match ($modo) {
            'uno' => [trim((string) $datos['ci'])],
            'varios' => array_map(trim(...), $datos['cis']),
            // «Todos» no acota por carnet: el propio rango define a quiénes
            // alcanza (los que tengan un turno vigente en esas fechas).
            default => [],
        };

        $asignacionesPorCi = $this->turnosDelRango($cis, $desde, $hasta, $elegidas);

        if ($asignacionesPorCi->isEmpty()) {
            return back()
                ->withInput()
                ->with('error', $this->motivoSinTurnos($modo, $elegidas));
        }

        // El respaldo se sube una sola vez y la ruta se copia a todas las filas
        // que genere el rango: son la misma licencia partida por día y turno.
        // Va después de resolver los turnos, para no dejar un archivo huérfano
        // en el bucket cuando el alta no llega a crear nada.
        [$adjunto, $adjuntoNombre] = $request->hasFile('respaldo')
            ? $respaldos->guardar($request->file('respaldo'), $cis[0] ?? 'sin-ci')
            : [null, null];

        $conteo = $registro->anotar($asignacionesPorCi, $desde, $hasta, [
            'tCompleto' => $datos['tCompleto'],
            'goceHaberes' => $datos['goceHaberes'],
            'motivo' => $datos['motivo'],
            'lEntra' => $datos['lEntra'] ?? null,
            'lSale' => $datos['lSale'] ?? null,
            'adjunto' => $adjunto,
            'adjuntoNombre' => $adjuntoNombre,
            'usuario' => (string) ($request->user()?->name ?? ''),
            'usuarioId' => $request->user()?->id,
        ]);

        if ($conteo['creadas'] === 0) {
            return back()
                ->withInput()
                ->with('error', 'No se anotó ninguna licencia: '.$registro->mensaje($conteo));
        }

        return redirect($this->destino($request, $modo, $cis[0] ?? ''))
            ->with('estado', $registro->mensaje($conteo));
    }

    /**
     * A dónde volver después de anotar: a la ficha desde la que se abrió el
     * modal (`local` o `mamore`) o, si se anotó desde la pantalla «Licenciar»,
     * al listado —filtrado por el carnet cuando fue un solo funcionario, porque
     * con varios no hay un filtro que los agrupe—.
     *
     * Solo se aceptan los dos orígenes conocidos, así un valor manipulado nunca
     * redirige fuera del sitio.
     */
    private function destino(Request $request, string $modo, string $ci): string
    {
        if ($ci !== '') {
            $origen = (string) $request->input('origen', '');

            // El ancla deja abierta la solapa de licencias al volver.
            if ($origen === 'local') {
                return route('funcionarios.show', ['persona' => $ci]).'#licencias';
            }

            if ($origen === 'mamore') {
                return route('funcionarios.mamore', ['ci' => $ci]).'#licencias';
            }
        }

        return route('licencias.index', $modo === 'uno' ? ['q' => $ci] : []);
    }

    /**
     * Por qué no hubo ningún turno que licenciar, según el alcance pedido.
     *
     * @param  list<int>  $elegidas
     */
    private function motivoSinTurnos(string $modo, array $elegidas): string
    {
        if ($elegidas !== []) {
            return 'Los turnos elegidos no pertenecen a ese funcionario.';
        }

        return $modo === 'todos'
            ? 'Ningún funcionario tiene turnos asignados dentro de ese rango de fechas.'
            : 'El funcionario no tiene ningún turno asignado dentro de ese rango de fechas.';
    }

    /**
     * Elimina (lógicamente) una licencia.
     *
     * El respaldo **no** se borra del bucket: la eliminación es lógica y la fila
     * se puede restaurar, además de que otras filas del mismo alta comparten el
     * archivo. Borrarlo dejaría a esas otras apuntando a la nada.
     */
    public function destroy(Licencia $licencia): RedirectResponse
    {
        $this->authorize('delete', $licencia);

        $licencia->delete();

        return back()->with('estado', 'Licencia eliminada.');
    }

    /**
     * Redirige al respaldo de la licencia con un enlace temporal y firmado.
     *
     * El archivo no se sirve por acá ni se hace público: un certificado médico
     * no puede quedar accesible con solo adivinar la URL. Se comprueba el
     * permiso de lectura y recién ahí se pide al bucket un enlace de vida corta.
     */
    public function respaldo(Licencia $licencia, RespaldoLicencia $respaldos): RedirectResponse
    {
        $this->authorize('viewAny', Licencia::class);

        $enlace = $respaldos->enlace($licencia->adjunto);

        return $enlace === null
            ? back()->with('error', 'La licencia no tiene respaldo cargado, o el archivo ya no está disponible.')
            : redirect()->away($enlace);
    }

    /**
     * Turnos asignados al funcionario para la grilla «Turnos asignados a …».
     * El orden y el filtro por vigencia viven en el scope del modelo, que es el
     * mismo que usa la ficha del funcionario.
     *
     * @return Collection<int, AsignacionTurno>
     */
    private function turnosAsignados(string $ci, bool $incluirVencidos = false): Collection
    {
        return AsignacionTurno::query()->delFuncionario($ci, $incluirVencidos)->get();
    }

    /**
     * Turnos a licenciar, agrupados por carnet. Sin selección manual se toman
     * las asignaciones cuya vigencia se solapa con el rango pedido: el rango de
     * fechas ya dice qué días son, marcar además los días de la semana sería
     * pedir el mismo dato dos veces. La selección manual queda para el caso de
     * doble turno en un día.
     *
     * Con `$cis` vacío no se acota por funcionario: es el alcance «todos», donde
     * el rango define a quiénes alcanza. Es una sola consulta para cualquier
     * cantidad de funcionarios (un feriado toca más de 400).
     *
     * @param  list<string>  $cis
     * @param  list<int>  $elegidas
     * @return Collection<string, Collection<int, AsignacionTurno>>
     */
    private function turnosDelRango(array $cis, Carbon $desde, Carbon $hasta, array $elegidas): Collection
    {
        return AsignacionTurno::query()
            ->with('turno')
            ->whereHas('turno')
            ->when($cis !== [], fn (Builder $query) => $query->whereIn('ci', $cis))
            ->when($elegidas !== [], fn (Builder $query) => $query->whereIn('id', $elegidas))
            // Solapamiento de rangos: la asignación sirve si empieza antes de
            // que termine el pedido y termina después de que empiece. Con
            // selección manual no se aplica, para permitir altas retroactivas.
            ->when($elegidas === [], fn (Builder $query) => $query
                ->where('desde', '<=', $hasta->copy()->endOfDay())
                ->where('hasta', '>=', $desde))
            ->get()
            ->groupBy(fn (AsignacionTurno $asignacion): string => trim((string) $asignacion->ci));
    }

    /**
     * Cuántas asignaciones vencidas tiene el funcionario, para ofrecer verlas
     * sin cargarlas de entrada.
     */
    private function contarVencidos(string $ci): int
    {
        return AsignacionTurno::query()
            ->join('turnos', 'turnos.id', '=', 'asignacion_turnos.turno_id')
            ->whereNull('turnos.deleted_at')
            ->where('asignacion_turnos.ci', $ci)
            ->where('asignacion_turnos.hasta', '<', now()->startOfDay())
            ->count();
    }

    /**
     * Fichas «CI — nombre» de los funcionarios que ya estaban en la lista del
     * alta grupal, para rearmarlas cuando el envío vuelve por un error de
     * validación. Los nombres salen de Mamoré; si la API falla se muestran solo
     * los carnets para no perder la selección.
     *
     * @return array{0: Collection<int, array{id: string, texto: string}>, 1: ?string}
     */
    private function fichasElegidas(DirectorioMamore $directorio): array
    {
        $cis = collect(old('cis', []))
            ->map(fn ($ci): string => trim((string) $ci))
            ->filter()
            ->unique()
            ->values();

        if ($cis->isEmpty()) {
            return [collect(), null];
        }

        try {
            $fichas = $cis->map(function (string $ci) use ($directorio): array {
                $persona = $directorio->porCi($ci);

                return [
                    'id' => $ci,
                    'texto' => $persona === null
                        ? $ci.' — Sin datos en Mamoré'
                        : $directorio->etiqueta($persona),
                ];
            });
        } catch (MamoreException $e) {
            return [$cis->map(fn (string $ci): array => ['id' => $ci, 'texto' => $ci]), $e->getMessage()];
        }

        return [$fichas, null];
    }
}
