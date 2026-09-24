<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMarcacionRequest;
use App\Models\Asistencia;
use App\Models\EquipoAuditoria;
use App\Services\ResolutorNombres;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Listado de las marcaciones desde la base local (MySQL, tabla `asistencias`,
 * migrada del SIA) y el alta manual de a una.
 *
 * La tabla tiene ~4.4 millones de filas, por eso el rango arranca en el mes
 * actual: nunca se lista ni se cuenta la tabla completa.
 *
 * La importación de CSV no vive acá: el botón del listado abre el mismo modal
 * de Biométricos y va a EquipoController::importarMarcaciones(), que la deja
 * en la bitácora con su motivo.
 */
class MarcacionController extends Controller
{
    /**
     * Listado paginado de marcaciones, filtrado por rango de fechas.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Asistencia::class);

        $carga = $this->cargaPedida($request);

        // Por defecto: del 1.º del mes hasta hoy (deja fuera las fechas basura
        // futuras que arrastra el SIA, ej. años 2064/2103). Viniendo de una
        // carga de la bitácora, sin rango: el reloj y el CSV traen historial y
        // lo que entró puede ser de cualquier fecha.
        $desde = $request->query('desde', $carga ? '' : now()->startOfMonth()->toDateString());
        $hasta = $request->query('hasta', $carga ? '' : now()->toDateString());
        $tipo = $request->query('tipo', '');
        $porPagina = $this->porPagina($request, 10);

        return view('marcaciones.index', compact('desde', 'hasta', 'tipo', 'porPagina', 'carga'));
    }

    /**
     * Devuelve el listado para AJAX.
     */
    public function list(Request $request, ResolutorNombres $resolutor): View
    {
        $this->authorize('viewAny', Asistencia::class);

        $carga = $this->cargaPedida($request);

        $desde = $request->query('desde', $carga ? '' : now()->startOfMonth()->toDateString());
        $hasta = $request->query('hasta', $carga ? '' : now()->toDateString());
        $buscar = trim((string) $request->query('q', ''));
        $tipo = $request->query('tipo', '');
        $porPagina = $this->porPagina($request, 10);

        $marcaciones = Asistencia::query()
            ->when($carga, fn (Builder $query) => $query->where('equipo_auditoria_id', $carga->id))
            ->enRango($desde, $hasta)
            ->when($buscar !== '', fn (Builder $query) => $query->buscar($buscar))
            ->when($tipo !== '', fn (Builder $query) => $query->where('tipo', $tipo))
            // De la marcación más reciente a la más antigua, por fecha y hora.
            //
            // No por `id`: no es cronológico. Las filas migradas del SIA
            // entraron en el orden de su clave (IdPersona, Fecha, Hora), así
            // que cada funcionario ocupa un bloque de ids con su historial
            // adentro, y ordenar por id agrupaba por persona.
            //
            // El `id` queda de desempate: dos funcionarios pueden marcar en el
            // mismo segundo, y sin un orden total la paginación repetiría o
            // saltearía filas entre páginas.
            ->orderByDesc('fecha')
            ->orderByDesc('hora')
            ->orderByDesc('id')
            ->paginate($porPagina)
            ->withQueryString();

        // La columna «Funcionario» (nombre y cargo) sale de Mamoré y, si el CI no
        // está ahí, de la base local (App\Services\ResolutorNombres).
        $fichas = $resolutor->fichasPorCi($marcaciones->pluck('ci'));

        return view('marcaciones.list', compact('marcaciones', 'fichas'));
    }

    /**
     * Registra una marcación manual (tipo M) sobre la base local. La hora se
     * guarda como hora pura, igual que el resto de las marcaciones.
     */
    public function store(StoreMarcacionRequest $request): RedirectResponse
    {
        $this->authorize('create', Asistencia::class);

        $ci = $request->validated('ci');
        $fecha = Carbon::parse($request->validated('fecha'))->toDateString();
        $hora = Carbon::parse($request->validated('hora'))->format('H:i:s');

        // Las dos columnas se comparan enteras: `fecha` guarda solo el día y
        // `hora` solo la hora, así que la búsqueda cae sobre el índice único
        // (ci, fecha, hora) en vez de recorrer las marcaciones de esa cédula.
        //
        // Antes `hora` iba por `whereTime()` porque las filas migradas del SIA
        // colgaban de fechas base distintas; con la columna `time` eso ya no
        // existe y la función solo impedía usar el índice.
        $yaExiste = Asistencia::query()
            ->where('ci', $ci)
            ->where('fecha', $fecha)
            ->where('hora', $hora)
            ->exists();

        if ($yaExiste) {
            return back()->with('error', 'Ya existe una marcación para ese funcionario en esa fecha y hora.');
        }

        Asistencia::create([
            'ci' => $ci,
            'fecha' => $fecha,
            'hora' => $hora,
            'tipo' => Asistencia::TIPO_MANUAL,
            'observacion' => $request->validated('observacion'),
        ]);

        return redirect($this->destino($request, $ci))
            ->with('estado', 'Marcación manual registrada correctamente.');
    }

    /**
     * La entrada de la bitácora de `?carga=`, cuando se entra al listado desde
     * la bitácora de equipos para ver qué marcaciones guardó una sincronización
     * o una importación.
     *
     * Solo cuentan esas dos acciones: exportar, limpiar y eliminar no insertan
     * marcaciones, y un id que no existe se ignora en vez de dejar el listado
     * vacío sin explicación.
     */
    private function cargaPedida(Request $request): ?EquipoAuditoria
    {
        $id = (int) $request->query('carga', 0);

        if ($id <= 0) {
            return null;
        }

        return EquipoAuditoria::query()
            ->whereIn('accion', EquipoAuditoria::ACCIONES_QUE_GUARDAN)
            ->find($id);
    }

    /**
     * A dónde volver después de registrar: a la ficha desde la que se abrió el
     * modal (`local` o `mamore`) o, si se registró desde el listado, al
     * listado. Solo se aceptan esos dos orígenes conocidos, así un valor
     * manipulado nunca redirige fuera del sitio.
     */
    private function destino(Request $request, string $ci): string
    {
        return match ((string) $request->input('origen', '')) {
            'local' => route('funcionarios.show', ['persona' => $ci]),
            'mamore' => route('funcionarios.mamore', ['ci' => $ci]),
            default => route('marcaciones.index'),
        };
    }
}
