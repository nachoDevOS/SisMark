<?php

namespace App\Http\Controllers;

use App\Exceptions\MamoreException;
use App\Http\Requests\ConcluirAsignacionTurnoRequest;
use App\Http\Requests\StoreAsignacionTurnoRequest;
use App\Models\AsignacionTurno;
use App\Models\Turno;
use App\Services\AsignadorTurnos;
use App\Services\DirectorioMamore;
use App\Services\ResolutorNombres;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Turnos asignados a cada funcionario: un turno por persona y período, con
 * fecha de inicio y de fin.
 *
 * Toda escritura pasa por {@see AsignadorTurnos}, que mueve la cabecera junto
 * con su detalle en `asignacion_horarios`.
 */
class AsignacionTurnoController extends Controller
{
    /**
     * Situaciones posibles respecto de hoy, para el filtro del listado.
     *
     * @var array<string, string>
     */
    public const SITUACIONES = [
        'vigentes' => 'Vigentes hoy',
        'futuras' => 'Aún no vigentes',
        'vencidas' => 'Vencidas',
    ];

    public function __construct(private AsignadorTurnos $asignador) {}

    /**
     * Pantalla del listado: el «shell» con los filtros. La tabla la carga por
     * AJAX contra `list()`.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', AsignacionTurno::class);

        $buscar = trim((string) $request->query('buscar', ''));
        $situacion = $this->situacion($request);
        $porPagina = $this->porPagina($request);

        return view('turnos-asignados.index', compact('buscar', 'situacion', 'porPagina'));
    }

    /**
     * Devuelve el parcial de la tabla (filas + paginación) para el AJAX.
     */
    public function list(Request $request, ResolutorNombres $resolutor): View
    {
        $this->authorize('viewAny', AsignacionTurno::class);

        $buscar = trim((string) $request->query('q', ''));
        $situacion = $this->situacion($request);
        $porPagina = $this->porPagina($request);

        $asignaciones = AsignacionTurno::query()
            ->with('turno.horarios')
            ->when($buscar !== '', fn (Builder $query) => $query->buscar($buscar))
            ->when($situacion === 'vigentes', fn (Builder $query) => $query->vigenteEn(today()))
            ->when($situacion === 'futuras', fn (Builder $query) => $query->where('desde', '>=', today()->addDay()))
            ->when($situacion === 'vencidas', fn (Builder $query) => $query->where('hasta', '<', today()))
            ->orderByDesc('desde')
            ->orderBy('ci')
            ->paginate($porPagina)
            ->withQueryString();

        $fichas = $resolutor->fichasPorCi($asignaciones->pluck('ci'));

        return view('turnos-asignados.list', compact('asignaciones', 'fichas', 'buscar'));
    }

    /**
     * Formulario para asignarle un turno a un funcionario. Con `?ci=` viene el
     * funcionario ya elegido (desde su ficha); sin eso, se busca con el combo.
     */
    public function create(Request $request, ResolutorNombres $resolutor): View
    {
        $this->authorize('create', AsignacionTurno::class);

        $ci = trim((string) $request->query('ci', old('ci', '')));
        $ficha = $ci === '' ? null : $resolutor->fichaPorCi($ci);
        $origen = $this->origen($request);

        $turnos = Turno::query()
            ->with('horarios')
            ->orderByDesc('sugerido')
            ->orderBy('nombre')
            ->get();

        return view('turnos-asignados.create', compact('ci', 'ficha', 'turnos', 'origen'));
    }

    /**
     * Guarda la asignación: la cabecera y un horario asignado por cada horario
     * del turno.
     */
    public function store(StoreAsignacionTurnoRequest $request): RedirectResponse
    {
        $this->authorize('create', AsignacionTurno::class);

        $datos = $request->validated();
        $ci = trim((string) $datos['ci']);

        $this->asignador->asignar(
            $ci,
            Turno::query()->with('horarios')->findOrFail($datos['turno_id']),
            Carbon::parse($datos['desde']),
            Carbon::parse($datos['hasta']),
            observacion: $datos['observacion'] ?? null,
        );

        return redirect($this->destino($request, $ci))->with('estado', 'Turno asignado correctamente.');
    }

    /**
     * Concluye el turno asignado: le pone fecha de fin a él y a su detalle.
     * Es lo que corresponde cuando el funcionario dejó ese turno; eliminar es
     * para lo cargado por error.
     */
    public function concluir(ConcluirAsignacionTurnoRequest $request, AsignacionTurno $asignacion): RedirectResponse
    {
        $this->authorize('update', $asignacion);

        $hasta = Carbon::parse($request->validated('hasta'));
        $this->asignador->concluir($asignacion, $hasta);

        return redirect($this->destino($request, trim((string) $asignacion->ci)))
            ->with('estado', 'Turno concluido el '.$hasta->format('d/m/Y').'.');
    }

    /**
     * Elimina (lógicamente) un turno asignado por error, con su detalle. El
     * motivo y el usuario los graba RegistersUserEvents.
     */
    public function destroy(Request $request, AsignacionTurno $asignacion): RedirectResponse
    {
        $this->authorize('delete', $asignacion);

        $this->asignador->eliminar($asignacion);

        $ancla = (string) $request->input('ancla', '');

        return redirect(url()->previous().($ancla === 'horarios' ? '#horarios' : ''))
            ->with('estado', 'Turno asignado eliminado.');
    }

    /**
     * Búsqueda de funcionarios por CI o nombre para el combo del formulario,
     * contra la API de Mamoré.
     */
    public function buscarFuncionarios(Request $request, DirectorioMamore $directorio): JsonResponse
    {
        $this->authorize('create', AsignacionTurno::class);

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
     * A dónde volver después de guardar: a la ficha desde la que se entró o, si
     * se entró por el listado, al listado filtrado por ese funcionario.
     */
    private function destino(Request $request, string $ci): string
    {
        return match ($this->origen($request)) {
            'mamore' => route('funcionarios.mamore', ['ci' => $ci]).'#horarios',
            'local' => route('funcionarios.show', ['persona' => $ci]).'#horarios',
            default => route('turnos-asignados.index', ['buscar' => $ci]),
        };
    }

    /**
     * Ficha de la que se entró al formulario: `mamore`, `local` o cadena vacía.
     */
    private function origen(Request $request): string
    {
        $origen = (string) $request->input('origen', '');

        return in_array($origen, ['mamore', 'local'], true) ? $origen : '';
    }

    /**
     * Filtro de situación del listado: «todas» (por defecto) o una de
     * {@see self::SITUACIONES}.
     */
    private function situacion(Request $request): string
    {
        $situacion = (string) $request->query('situacion', 'todas');

        return array_key_exists($situacion, self::SITUACIONES) ? $situacion : 'todas';
    }
}
