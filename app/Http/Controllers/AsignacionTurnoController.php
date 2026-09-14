<?php

namespace App\Http\Controllers;

use App\Exceptions\MamoreException;
use App\Http\Requests\ConcluirAsignacionTurnoRequest;
use App\Http\Requests\StoreAsignacionTurnoRequest;
use App\Models\AsignacionTurno;
use App\Models\Turno;
use App\Services\DirectorioMamore;
use App\Services\ResolutorNombres;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Listado (solo lectura) de los turnos asignados a cada funcionario: la tabla
 * local `asignacion_turnos`, migrada de «AsignacionTurnos» del SIA.
 *
 * El funcionario se cruza por **CI** (la asignación solo guarda la cédula) y el
 * turno por la FK **`turno_id`**; `idTurno` queda como dato histórico del SIA y
 * no se usa para relacionar.
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

    /**
     * Pantalla del listado (browse): el «shell» con los filtros. La tabla la
     * carga por AJAX contra `list()`.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', AsignacionTurno::class);

        $buscar = trim((string) $request->query('buscar', ''));
        $dia = (string) $request->query('dia', '');
        $situacion = $this->situacion($request);
        $porPagina = $this->porPagina($request);

        return view('turnos-asignados.index', compact('buscar', 'dia', 'situacion', 'porPagina'));
    }

    /**
     * Devuelve el parcial de la tabla (filas + paginación) para el AJAX.
     */
    public function list(Request $request, ResolutorNombres $resolutor): View
    {
        $this->authorize('viewAny', AsignacionTurno::class);

        $buscar = trim((string) $request->query('q', ''));
        $dia = (string) $request->query('dia', '');
        $situacion = $this->situacion($request);
        $porPagina = $this->porPagina($request);

        $asignaciones = AsignacionTurno::query()
            ->with('turno')
            ->when($buscar !== '', fn (Builder $query) => $query->buscar($buscar))
            ->when($dia !== '', fn (Builder $query) => $query->whereHas('turno', fn (Builder $turno) => $turno->where('dia', $dia)))
            // Los tres van por rango y no con `whereDate()`: `DATE(columna)`
            // anula el índice `(hasta, desde)` y manda a recorrer las 420.721
            // filas. `desde` y `hasta` son `datetime`, así que «después de hoy»
            // es «desde mañana a las cero».
            ->when($situacion === 'vigentes', fn (Builder $query) => $query->vigenteEn(today()))
            ->when($situacion === 'futuras', fn (Builder $query) => $query->where('desde', '>=', today()->addDay()))
            ->when($situacion === 'vencidas', fn (Builder $query) => $query->where('hasta', '<', today()))
            ->orderByDesc('desde')
            ->orderBy('ci')
            ->paginate($porPagina)
            ->withQueryString();

        // La columna «Funcionario» (nombre y cargo) sale de Mamoré y, si el CI
        // no está ahí, de la base local.
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
        // De qué ficha se entró, para volver ahí al guardar (ver `destino()`).
        $origen = $this->origen($request);

        $turnos = Turno::query()->ordenado()->get();

        return view('turnos-asignados.create', compact('ci', 'ficha', 'turnos', 'origen'));
    }

    /**
     * Guarda la asignación. El turno se vincula por `turno_id`; `idTurno` se
     * copia del turno elegido solo para conservar el código histórico del SIA,
     * que es además parte de la clave única de la tabla.
     */
    public function store(StoreAsignacionTurnoRequest $request): RedirectResponse
    {
        $this->authorize('create', AsignacionTurno::class);

        $datos = $request->validated();
        $turno = Turno::query()->findOrFail($datos['turno_id']);
        $ci = trim((string) $datos['ci']);

        AsignacionTurno::create([
            'ci' => $ci,
            'turno_id' => $turno->id,
            'idTurno' => $turno->idTurno,
            'desde' => Carbon::parse($datos['desde'])->startOfDay(),
            'hasta' => Carbon::parse($datos['hasta'])->startOfDay(),
            'observacion' => $datos['observacion'] ?? null,
        ]);

        return redirect($this->destino($request, $ci))->with('estado', 'Turno asignado correctamente.');
    }

    /**
     * Concluye una asignación: le pone fecha de fin y deja de estar vigente.
     *
     * Es lo que corresponde cuando el funcionario **dejó** ese turno: la
     * asignación queda como historia y sigue explicando sus marcaciones y
     * licencias de ese período. Borrarla es otra cosa, y es para cuando se
     * cargó mal ({@see self::destroy()}).
     */
    public function concluir(ConcluirAsignacionTurnoRequest $request, AsignacionTurno $asignacion): RedirectResponse
    {
        $this->authorize('update', $asignacion);

        $asignacion->hasta = Carbon::parse($request->validated('hasta'))->startOfDay();
        $asignacion->save();

        return redirect($this->destino($request, trim((string) $asignacion->ci)))
            ->with('estado', 'Turno concluido el '.$asignacion->hasta->format('d/m/Y').'.');
    }

    /**
     * Elimina (lógicamente) una asignación cargada por error. El motivo y el
     * usuario los graba el trait RegistersUserEvents.
     */
    public function destroy(Request $request, AsignacionTurno $asignacion): RedirectResponse
    {
        $this->authorize('delete', $asignacion);

        $asignacion->delete();

        // El modal global de baja manda el ancla de la solapa desde la que se
        // borró, para volver ahí y no a la primera.
        $ancla = (string) $request->input('ancla', '');

        return redirect(url()->previous().($ancla === 'turnos' ? '#turnos' : ''))
            ->with('estado', 'Asignación de turno eliminada.');
    }

    /**
     * A dónde volver después de guardar: a la ficha desde la que se entró
     * («mamore» o «local») o, si se entró por el listado, al listado filtrado
     * por ese funcionario. Solo se aceptan estos dos orígenes conocidos, así
     * un valor manipulado nunca redirige fuera del sitio.
     */
    private function destino(Request $request, string $ci): string
    {
        // El ancla deja abierta la solapa de turnos al volver a la ficha.
        return match ($this->origen($request)) {
            'mamore' => route('funcionarios.mamore', ['ci' => $ci]).'#turnos',
            'local' => route('funcionarios.show', ['persona' => $ci]).'#turnos',
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
     * Búsqueda de funcionarios por CI o nombre para el combo del formulario,
     * contra la API de Mamoré. Devuelve hasta 20 coincidencias como JSON, o un
     * 502 con el motivo si la API no responde.
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
     * Filtro de situación del listado: «todas» (por defecto) o una de
     * {@see self::SITUACIONES}. Un valor desconocido cae en «todas».
     */
    private function situacion(Request $request): string
    {
        $situacion = (string) $request->query('situacion', 'todas');

        return array_key_exists($situacion, self::SITUACIONES) ? $situacion : 'todas';
    }
}
