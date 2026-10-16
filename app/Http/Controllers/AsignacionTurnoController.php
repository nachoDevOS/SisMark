<?php

namespace App\Http\Controllers;

use App\Exceptions\MamoreException;
use App\Http\Requests\ConcluirAsignacionTurnoRequest;
use App\Http\Requests\StoreAsignacionTurnoRequest;
use App\Models\AsignacionHorario;
use App\Models\AsignacionTurno;
use App\Models\Turno;
use App\Services\AsignadorTurnos;
use App\Services\ContratosFuncionario;
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

        // Por defecto, el mes en curso completo: del 1.º al último día.
        $desde = (string) $request->query('desde', now()->startOfMonth()->toDateString());
        $hasta = (string) $request->query('hasta', now()->endOfMonth()->toDateString());

        return view('turnos-asignados.index', compact('buscar', 'situacion', 'porPagina', 'desde', 'hasta'));
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
        $desde = trim((string) $request->query('desde', ''));
        $hasta = trim((string) $request->query('hasta', ''));

        $asignaciones = AsignacionTurno::query()
            ->with('turno.horarios')
            ->when($buscar !== '', fn (Builder $query) => $query->buscar($buscar))
            ->when($situacion === 'vigentes', fn (Builder $query) => $query->vigenteEn(today()))
            ->when($situacion === 'futuras', fn (Builder $query) => $query->where('desde', '>=', today()->addDay()->toDateString()))
            ->when($situacion === 'vencidas', fn (Builder $query) => $query->where('hasta', '<', today()->toDateString()))
            // Entra la asignación cuya vigencia se cruza con el rango, aunque
            // empiece antes o termine después. Sin `desde` o sin `hasta`, ese
            // extremo queda abierto.
            ->when($desde !== '', fn (Builder $query) => $query->where('hasta', '>=', $desde))
            ->when($hasta !== '', fn (Builder $query) => $query->where('desde', '<=', $hasta))
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
            isset($datos['contrato_id']) ? (int) $datos['contrato_id'] : null,
            $datos['observacion'] ?? null,
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
     * Lo que hay que ver antes de asignarle un turno a alguien: sus datos, sus
     * contratos firmados vigentes (a uno de ellos se ata el turno) y los turnos
     * que ya tiene, vigentes o por empezar.
     *
     * `mamore: false` es «Mamoré no está configurado»: ahí se asigna sin
     * contrato, como antes. Si está configurado y no responde, los contratos
     * vienen vacíos con el motivo en `errorContratos`, y no se puede asignar.
     */
    public function ficha(Request $request, ResolutorNombres $resolutor, ContratosFuncionario $contratos): JsonResponse
    {
        $this->authorize('create', AsignacionTurno::class);

        $ci = trim((string) $request->query('ci', ''));
        abort_if($ci === '', 422, 'Falta la cédula.');

        $persona = $resolutor->fichaPorCi($ci);
        $errorContratos = null;

        try {
            $asignables = $contratos->asignables($ci);
        } catch (MamoreException $e) {
            $asignables = [];
            $errorContratos = 'No se pudieron consultar los contratos en Mamoré: '.$e->getMessage();
        }

        $turnoPorContrato = AsignacionTurno::query()
            ->with('turno')
            ->whereIn('contrato_id', collect($asignables ?? [])->pluck('id'))
            ->get()
            ->keyBy('contrato_id');

        $fecha = fn (?Carbon $valor): ?string => $valor?->format('d/m/Y');

        return response()->json([
            'persona' => $persona === null ? null : [
                'ci' => $ci,
                'ciCompleto' => $persona['ciCompleto'] ?? null,
                'nombre' => $persona['nombre'] ?? null,
                'cargo' => $persona['cargo'] ?? null,
                'direccion' => $persona['direccion'] ?? null,
                'tipoContrato' => $persona['tipoContrato'] ?? null,
                'ver' => $persona['ver'] ?? null,
            ],
            'mamore' => $asignables !== null,
            'errorContratos' => $errorContratos,
            'contratos' => collect($asignables ?? [])->map(fn (array $contrato): array => [
                'id' => $contrato['id'],
                'codigo' => $contrato['codigo'],
                'cargo' => $contrato['cargo'],
                'direccion' => $contrato['direccion'],
                'desde' => $contrato['desde']->toDateString(),
                'hasta' => $contrato['hasta']?->toDateString(),
                'desdeTexto' => $fecha($contrato['desde']),
                'hastaTexto' => $fecha($contrato['hasta']),
                'empezo' => $contrato['desde']->lessThanOrEqualTo(today()),
                'turno' => ($asignado = $turnoPorContrato->get($contrato['id'])) === null ? null : [
                    'nombre' => $asignado->turno?->nombre,
                    'desdeTexto' => $fecha($asignado->desde),
                    'hastaTexto' => $fecha($asignado->hasta),
                ],
            ])->values(),
            'turnos' => AsignacionTurno::query()
                ->with('turno')
                ->where('ci', $ci)
                ->where('hasta', '>=', today()->toDateString())
                ->orderBy('desde')
                ->get()
                ->map(fn (AsignacionTurno $asignado): array => [
                    'nombre' => $asignado->turno?->nombre,
                    'desdeTexto' => $fecha($asignado->desde),
                    'hastaTexto' => $fecha($asignado->hasta),
                    'vigente' => $asignado->desde->lessThanOrEqualTo(today()),
                    'contrato' => $asignado->contrato_id !== null,
                ]),
            // Horarios del sistema anterior que siguen en pie. Sin turno, con
            // ellos igual se le controla la asistencia; y al asignar un turno se
            // cortan el día antes de su inicio, así que quien asigna tiene que
            // verlos.
            'heredados' => AsignacionHorario::query()
                ->whereNull('asignacion_turno_id')
                ->whereHas('horario')
                ->where('ci', $ci)
                ->where('hasta', '>=', today()->toDateString())
                ->selectRaw('desde, hasta, count(*) as horarios')
                ->groupBy('desde', 'hasta')
                ->orderBy('desde')
                ->get()
                ->map(fn (AsignacionHorario $periodo): array => [
                    'desde' => $periodo->desde?->toDateString(),
                    'hasta' => $periodo->hasta?->toDateString(),
                    'desdeTexto' => $fecha($periodo->desde),
                    'hastaTexto' => $fecha($periodo->hasta),
                    'horarios' => (int) $periodo->horarios,
                    'vigente' => $periodo->desde?->lessThanOrEqualTo(today()) ?? false,
                ]),
        ]);
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
