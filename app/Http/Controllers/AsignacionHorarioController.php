<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConcluirAsignacionHorarioRequest;
use App\Models\AsignacionHorario;
use App\Services\ResolutorNombres;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Historial de los horarios asignados a cada funcionario, día por día: la tabla
 * local `asignacion_horarios`, migrada de «AsignacionTurnos» del SIA.
 *
 * Ya no se asignan horarios sueltos: se asigna un turno
 * ({@see AsignacionTurnoController}) y sus horarios quedan acá como detalle. Lo
 * único que se hace desde esta pantalla es concluir o eliminar lo heredado del
 * sistema anterior; el detalle de un turno se maneja desde el turno.
 *
 * El funcionario se cruza por **CI** (la asignación solo guarda la cédula) y el
 * horario por la FK **`horario_id`**; `idHorario` queda como dato histórico del SIA y
 * no se usa para relacionar.
 */
class AsignacionHorarioController extends Controller
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
        $this->authorize('viewAny', AsignacionHorario::class);

        $buscar = trim((string) $request->query('buscar', ''));
        $dia = (string) $request->query('dia', '');
        $situacion = $this->situacion($request);
        $porPagina = $this->porPagina($request);

        return view('horarios-asignados.index', compact('buscar', 'dia', 'situacion', 'porPagina'));
    }

    /**
     * Devuelve el parcial de la tabla (filas + paginación) para el AJAX.
     */
    public function list(Request $request, ResolutorNombres $resolutor): View
    {
        $this->authorize('viewAny', AsignacionHorario::class);

        $buscar = trim((string) $request->query('q', ''));
        $dia = (string) $request->query('dia', '');
        $situacion = $this->situacion($request);
        $porPagina = $this->porPagina($request);

        $asignaciones = AsignacionHorario::query()
            ->with(['horario', 'asignacionTurno.turno'])
            ->when($buscar !== '', fn (Builder $query) => $query->buscar($buscar))
            ->when($dia !== '', fn (Builder $query) => $query->whereHas('horario', fn (Builder $horario) => $horario->where('dia', $dia)))
            // Los tres van por rango y no con `whereDate()`: `DATE(columna)`
            // anula el índice `(hasta, desde)` y manda a recorrer las 420.721
            // filas. `desde` y `hasta` son fechas, así que «después de hoy»
            // es «desde mañana a las cero».
            ->when($situacion === 'vigentes', fn (Builder $query) => $query->vigenteEn(today()))
            ->when($situacion === 'futuras', fn (Builder $query) => $query->where('desde', '>=', today()->addDay()->toDateString()))
            ->when($situacion === 'vencidas', fn (Builder $query) => $query->where('hasta', '<', today()->toDateString()))
            ->orderByDesc('desde')
            ->orderBy('ci')
            ->paginate($porPagina)
            ->withQueryString();

        // La columna «Funcionario» (nombre y cargo) sale de Mamoré y, si el CI
        // no está ahí, de la base local.
        $fichas = $resolutor->fichasPorCi($asignaciones->pluck('ci'));

        return view('horarios-asignados.list', compact('asignaciones', 'fichas', 'buscar'));
    }

    /**
     * Concluye una asignación: le pone fecha de fin y deja de estar vigente.
     *
     * Es lo que corresponde cuando el funcionario **dejó** ese horario: la
     * asignación queda como historia y sigue explicando sus marcaciones y
     * licencias de ese período. Borrarla es otra cosa, y es para cuando se
     * cargó mal ({@see self::destroy()}).
     */
    public function concluir(ConcluirAsignacionHorarioRequest $request, AsignacionHorario $asignacion): RedirectResponse
    {
        $this->authorize('update', $asignacion);
        $this->rechazarSiEsDeTurno($asignacion);

        $asignacion->hasta = Carbon::parse($request->validated('hasta'))->startOfDay();
        $asignacion->save();

        return redirect($this->destino($request, trim((string) $asignacion->ci)))
            ->with('estado', 'Horario concluido el '.$asignacion->hasta->format('d/m/Y').'.');
    }

    /**
     * Elimina (lógicamente) una asignación cargada por error. El motivo y el
     * usuario los graba el trait RegistersUserEvents.
     */
    public function destroy(Request $request, AsignacionHorario $asignacion): RedirectResponse
    {
        $this->authorize('delete', $asignacion);
        $this->rechazarSiEsDeTurno($asignacion);

        $asignacion->delete();

        // El modal global de baja manda el ancla de la solapa desde la que se
        // borró, para volver ahí y no a la primera.
        $ancla = (string) $request->input('ancla', '');

        return redirect(url()->previous().($ancla === 'horarios' ? '#horarios' : ''))
            ->with('estado', 'Asignación de horario eliminada.');
    }

    /**
     * Corta si la fila es detalle de un turno: eso se concluye o se elimina
     * desde «Turnos asignados», con el turno entero.
     *
     * Va acá además de en la policy porque el `Gate::before` de
     * AppServiceProvider le da todo al super_admin sin consultar las policies:
     * sin esto, el super_admin podía dejar un turno desparejo con su detalle.
     */
    private function rechazarSiEsDeTurno(AsignacionHorario $asignacion): void
    {
        abort_if($asignacion->es_de_turno, 403, 'Este horario es parte de un turno asignado: concluí o eliminá el turno desde «Turnos asignados».');
    }

    /**
     * A dónde volver después de guardar: a la ficha desde la que se entró
     * («mamore» o «local») o, si se entró por el listado, al listado filtrado
     * por ese funcionario. Solo se aceptan estos dos orígenes conocidos, así
     * un valor manipulado nunca redirige fuera del sitio.
     */
    private function destino(Request $request, string $ci): string
    {
        // El ancla deja abierta la solapa de horarios al volver a la ficha.
        return match ($this->origen($request)) {
            'mamore' => route('funcionarios.mamore', ['ci' => $ci]).'#horarios',
            'local' => route('funcionarios.show', ['persona' => $ci]).'#horarios',
            default => route('horarios-asignados.index', ['buscar' => $ci]),
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
     * {@see self::SITUACIONES}. Un valor desconocido cae en «todas».
     */
    private function situacion(Request $request): string
    {
        $situacion = (string) $request->query('situacion', 'todas');

        return array_key_exists($situacion, self::SITUACIONES) ? $situacion : 'todas';
    }
}
