<?php

namespace App\Http\Controllers;

use App\Exceptions\MamoreException;
use App\Models\AsignacionTurno;
use App\Models\Asistencia;
use App\Models\Licencia;
use App\Models\Persona;
use App\Services\DirectorioMamore;
use App\Services\MamoreClient;
use App\Services\ResolutorNombres;
use App\Services\TopePermisos;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator as Paginador;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Consulta (solo lectura) de los funcionarios. El listado tiene dos fuentes,
 * elegibles con un select: «Mamoré» (API externa de Datos Personales, por
 * defecto) y «SIAT» (base local MySQL, tabla `personas`). El alta/edición/
 * borrado siguen siendo de los sistemas de origen.
 */
class PersonaController extends Controller
{
    /**
     * Pantalla del listado (browse): el «shell» con los filtros. La tabla en sí
     * se carga por AJAX contra `list()`, con su propia paginación.
     */
    public function index(): View
    {
        $this->authorize('viewAny', Persona::class);

        return view('funcionarios.index');
    }

    /**
     * Devuelve el parcial de la tabla (filas + paginación) para el AJAX del
     * listado, con búsqueda por CI o nombre desde la fuente elegida (Mamoré por
     * defecto, o SIAT local).
     */
    public function list(Request $request, MamoreClient $mamore): View
    {
        $this->authorize('viewAny', Persona::class);

        $busqueda = trim((string) $request->query('q', ''));
        $porPagina = $this->porPagina($request);
        $fuente = $request->query('fuente') === 'siat' ? 'siat' : 'mamore';
        $contrato = $this->contrato($request);
        $errorFuente = null;
        $totales = ['con' => null, 'sin' => null];

        if ($fuente === 'siat') {
            // SIAT no conoce los contratos: el filtro es solo de Mamoré.
            $contrato = 'todos';
            $funcionarios = $this->funcionariosLocales($busqueda, $porPagina);
        } else {
            [$funcionarios, $errorFuente, $totales] = $this->funcionariosMamore($request, $mamore, $busqueda, $porPagina, $contrato);
        }

        return view('funcionarios.list', compact('funcionarios', 'fuente', 'contrato', 'totales', 'errorFuente'));
    }

    /**
     * Ficha de detalle de un funcionario local (SIAT). Marcaciones, licencias y
     * turnos no van en esta respuesta: son las tres solapas del pie de la
     * ficha, y cada una carga su tabla por AJAX cuando se la abre.
     */
    public function show(Request $request, Persona $persona, ResolutorNombres $resolutor): View
    {
        $this->authorize('view', $persona);

        $persona->loadMissing('profesion');

        $desde = $request->query('desde', now()->startOfMonth()->toDateString());
        $hasta = $request->query('hasta', now()->toDateString());
        $tipo = $request->query('tipo', '');

        // La extensión del carnet solo la tiene Mamoré: la tabla local
        // `personas` no la trae. Se resuelve por la misma ficha cacheada que ya
        // usan los listados, así que no cuesta una consulta nueva por visita, y
        // si la API no responde la ficha se muestra igual sin ese dato.
        $extension = $resolutor->fichaPorCi(trim((string) $persona->ci))['extension'] ?? null;

        return view('funcionarios.show', compact('persona', 'desde', 'hasta', 'tipo', 'extension'));
    }

    /**
     * Devuelve el parcial de las marcaciones de una cédula (tabla +
     * paginación) para el AJAX del panel de la ficha. Sirve tanto a la ficha
     * local como a la de Mamoré: `asistencias` se cruza por CI, así que no
     * hace falta que la persona exista en la base local.
     */
    public function marcacionesList(Request $request): View
    {
        $this->authorize('viewAny', Persona::class);

        $ci = trim((string) $request->query('ci', ''));
        $desde = $request->query('desde', now()->startOfMonth()->toDateString());
        $hasta = $request->query('hasta', now()->toDateString());
        $tipo = $request->query('tipo', '');

        $marcaciones = Asistencia::query()
            ->where('ci', $ci)
            ->enRango($desde, $hasta)
            ->when($tipo !== '', fn (Builder $query) => $query->where('tipo', $tipo))
            ->orderByDesc('fecha')
            ->orderByDesc('hora')
            ->paginate($this->porPagina($request))
            ->withQueryString();

        return view('funcionarios.marcaciones-list', compact('marcaciones'));
    }

    /**
     * Parcial de las licencias de una cédula para la solapa de la ficha.
     * También se cruza por CI, así que sirve a las dos fichas.
     */
    public function licenciasList(Request $request, TopePermisos $tope): View
    {
        $this->authorize('viewAny', Licencia::class);

        $ci = trim((string) $request->query('ci', ''));

        // Una fila por solicitud, igual que el listado general: el alta expande
        // el rango a una fila por día y turno, y mostrarlas sueltas hacía que un
        // permiso de cinco días pareciera cinco licencias. Importa además porque
        // la baja de esta pantalla alcanza a la solicitud entera.
        //
        // Sin `with('turno')`: la tabla ya no muestra el horario —un pedido de
        // cinco días puede abarcar turnos distintos y poner el del primer día
        // engañaba—. El desglose día por día, con su turno, está en la ficha.
        //
        // Con `mes` («2026-09») salen las solicitudes con algún día en ese mes;
        // una que cruza de un mes al otro aparece en los dos. Sin mes, todas.
        $mes = $this->mesPedido($request);

        $licencias = Licencia::paginarPorSolicitud(
            Licencia::query()
                ->where('ci', $ci)
                ->when($mes, fn (Builder $query) => $query->whereBetween('fecha', [
                    $mes->toDateString(),
                    $mes->copy()->endOfMonth()->toDateString(),
                ])),
            $this->porPagina($request),
        )->withQueryString();

        $resumen = Licencia::resumenDe($licencias->getCollection()->map->clave_agrupadora);

        // Cuánto permiso por horas lleva y le queda en el mes, contra el tope de
        // Configuración. Solo con un mes elegido: el tope es mensual.
        $saldo = $mes === null
            ? null
            : $tope->saldo($ci, $mes, $mes->copy()->endOfMonth()->startOfDay(), [], null, null);

        return view('funcionarios.licencias-list', compact('licencias', 'resumen', 'mes', 'saldo'));
    }

    /**
     * El mes del filtro de licencias (`?mes=2026-09`) como su primer día, o
     * `null` si no vino o no es un mes válido: en ese caso se listan todas.
     */
    private function mesPedido(Request $request): ?Carbon
    {
        $mes = trim((string) $request->query('mes', ''));

        if (! preg_match('/^(\d{4})-(\d{2})$/', $mes, $partes) || ! checkdate((int) $partes[2], 1, (int) $partes[1])) {
            return null;
        }

        return Carbon::create((int) $partes[1], (int) $partes[2], 1);
    }

    /**
     * Parcial de los turnos asignados de una cédula para la solapa de la ficha.
     *
     * Por defecto salen todos, con los que siguen en pie primero; el filtro
     * permite quedarse solo con los vigentes o mirar solo los vencidos.
     *
     * Lo que se pagina son los períodos de vigencia, no las asignaciones: cada
     * período trae una asignación por día de la semana, así que paginar filas
     * cortaba la tanda al medio y hacía cinco páginas de lo que es una sola.
     * Con los períodos de la página en mano se traen sus asignaciones de un
     * viaje y se agrupan para que la vista arme un bloque por vigencia.
     */
    public function turnosList(Request $request): View
    {
        $this->authorize('viewAny', AsignacionTurno::class);

        $ci = trim((string) $request->query('ci', ''));
        $situacion = $this->situacionTurnos($request);
        // De qué ficha se pidió la tabla: los botones de la fila lo devuelven
        // al concluir un turno, para volver a esta misma solapa.
        $origenFicha = in_array($request->query('origen'), ['local', 'mamore'], true)
            ? (string) $request->query('origen')
            : '';
        // El modal de licencia muestra esta misma tabla solo como referencia:
        // pide `acciones=0` para que no aparezcan concluir ni eliminar.
        $conAcciones = $request->boolean('acciones', true);

        $incluirVencidas = $situacion !== 'vigentes';
        $soloVencidas = fn (Builder $query) => $query->whereDate('asignacion_turnos.hasta', '<', today());

        $periodos = AsignacionTurno::query()
            ->periodosDelFuncionario($ci, incluirVencidas: $incluirVencidas)
            ->when($situacion === 'vencidas', $soloVencidas)
            ->paginate($this->porPagina($request))
            ->withQueryString();

        $asignaciones = $periodos->isEmpty()
            ? collect()
            : AsignacionTurno::query()
                ->delFuncionario($ci, incluirVencidas: $incluirVencidas)
                ->when($situacion === 'vencidas', $soloVencidas)
                ->where(function (Builder $query) use ($periodos): void {
                    foreach ($periodos as $periodo) {
                        $query->orWhere(fn (Builder $par) => $par
                            ->where('asignacion_turnos.desde', $periodo->desde)
                            ->where('asignacion_turnos.hasta', $periodo->hasta));
                    }
                })
                ->get()
                ->groupBy(fn (AsignacionTurno $asignacion): string => $asignacion->clave_periodo);

        return view('funcionarios.turnos-list', compact('periodos', 'asignaciones', 'origenFicha', 'conAcciones'));
    }

    /**
     * Filtro de la solapa de turnos: «todas» (por defecto), «vigentes» o
     * «vencidas». Un valor desconocido cae en «todas».
     */
    private function situacionTurnos(Request $request): string
    {
        $situacion = (string) $request->query('situacion', 'todas');

        return in_array($situacion, ['vigentes', 'vencidas'], true) ? $situacion : 'todas';
    }

    /**
     * Ficha de solo lectura de una persona de la API de Mamoré (por cédula).
     * Sus marcaciones las carga el panel por AJAX contra `marcacionesList()`.
     */
    public function mamoreShow(Request $request, string $ci, MamoreClient $mamore): View
    {
        // Es la ficha, igual que `show()`: pide «Ver ficha» y no el del
        // listado. Va por nombre porque acá no hay un `Persona` local que
        // pasarle a la policy.
        $this->autorizarPermiso('View:Persona');

        try {
            $persona = $mamore->personByCi($ci);
        } catch (MamoreException $e) {
            abort(502, $e->getMessage());
        }

        abort_if($persona === null, 404);

        $ciMarcaciones = trim((string) ($persona['ci'] ?? $ci));
        $desde = $request->query('desde', now()->startOfMonth()->toDateString());
        $hasta = $request->query('hasta', now()->toDateString());
        $tipo = $request->query('tipo', '');

        // El reporte imprimible cuelga del funcionario local: solo se ofrece si
        // esta cédula también está en la base local.
        $personaLocal = Persona::query()->where('ci', $ciMarcaciones)->first();

        return view('funcionarios.mamore-show', compact('persona', 'personaLocal', 'desde', 'hasta', 'tipo'));
    }

    /**
     * Reporte imprimible de las marcaciones «sin procesar» del funcionario:
     * todas las marcaciones crudas del rango (sin paginar, en orden
     * cronológico), con el formato del sistema de escritorio viejo.
     */
    public function reporteMarcaciones(Request $request, Persona $persona, ResolutorNombres $resolutor): View
    {
        $this->authorize('view', $persona);

        $desde = $request->query('desde', now()->startOfMonth()->toDateString());
        $hasta = $request->query('hasta', now()->toDateString());
        $tipo = $request->query('tipo', '');

        $marcaciones = $persona->marcaciones()
            ->enRango($desde, $hasta)
            ->when($tipo !== '', fn (Builder $query) => $query->where('tipo', $tipo))
            ->orderBy('fecha')
            ->orderBy('hora')
            ->get();

        // El reporte se imprime con la ficha resuelta (Mamoré primero, para que
        // salga el cargo), con la base local como respaldo.
        $ficha = $resolutor->fichaPorCi((string) $persona->ci) ?? [
            'ci' => trim((string) $persona->ci),
            'nombre' => $persona->nombre_completo ?: '—',
            'nombreFormal' => collect([$persona->paterno, $persona->materno, $persona->nombres])
                ->map(fn ($parte): string => trim((string) $parte))
                ->filter()
                ->implode(' ') ?: '—',
            'cargo' => null,
            'direccion' => null,
            'pinReloj' => trim((string) $persona->pinReloj),
        ];

        return view('reportes.marcaciones.sinProcesar.print', [
            'persona' => $ficha,
            'marcaciones' => $marcaciones,
            'desde' => $desde,
            'hasta' => $hasta,
            'tipo' => $tipo,
        ]);
    }

    /**
     * Filtro de contrato del listado: «todos» (por defecto), «con» o «sin».
     * Cualquier otro valor cae en «todos» para no dejar la tabla vacía.
     */
    private function contrato(Request $request): string
    {
        $contrato = (string) $request->query('contrato', 'todos');

        return in_array($contrato, ['con', 'sin'], true) ? $contrato : 'todos';
    }

    /**
     * Funcionarios de la base local (SIAT), normalizados a la forma común de la
     * tabla.
     */
    private function funcionariosLocales(string $busqueda, int $porPagina): LengthAwarePaginator
    {
        return Persona::query()
            ->with('profesion')
            ->when($busqueda !== '', fn (Builder $query) => $query->buscar($busqueda))
            ->orderBy('paterno')
            ->paginate($porPagina)
            ->withQueryString()
            ->through(fn (Persona $persona): array => [
                'id' => $persona->id,
                'ci' => trim((string) $persona->ci),
                'nombre' => $persona->nombre_completo ?: '—',
                'profesion' => trim((string) $persona->profesion?->nombreProfesion),
                'pinReloj' => trim((string) $persona->pinReloj),
                'nacimiento' => $persona->fechaNacimiento?->format('d/m/Y'),
                'edad' => $persona->fechaNacimiento?->age,
                'ver' => route('funcionarios.show', $persona),
                // SIAT no tiene contratos: las columnas de Mamoré van vacías.
                'cargo' => null,
                'direccion' => null,
                'conContrato' => null,
                // SIAT no guarda fotos: solo las tiene Mamoré.
                'image' => null,
                'imageThumb' => null,
            ]);
    }

    /**
     * Funcionarios de la API de Mamoré, normalizados a la forma común de la
     * tabla. Si la API falla, devuelve un paginador vacío y el mensaje de error.
     *
     * La fuente es siempre `/people`: devuelve todas las personas y cada una
     * trae su contrato firmado (de donde salen el cargo y la dirección) o `null`.
     * El filtro por situación de contrato lo resuelve la propia API.
     *
     * La búsqueda se delega entera a la API, incluida la de varias palabras.
     *
     * Antes se filtraba acá: la API solo sabía buscar una frase, así que este
     * método pedía 100 filas por la palabra más larga y cruzaba el resto en
     * memoria. Eso perdía gente sin avisar —«maria» cruza con 220 personas, y
     * las que caían después de la 100, ordenadas por apellido, no aparecían
     * nunca—: buscar «maria rene» no encontraba a MARIA RENE TELLEZ, pero
     * «maria rene tellez» sí, porque ahí la palabra más larga era selectiva.
     * La API pasó a cruzar todas las palabras contra todos los campos, que es
     * donde corresponde hacerlo: en la base que tiene los datos.
     *
     * @return array{0: LengthAwarePaginator, 1: ?string, 2: array{con: ?int, sin: ?int}}
     */
    private function funcionariosMamore(Request $request, MamoreClient $mamore, string $busqueda, int $porPagina, string $contrato): array
    {
        $pagina = max(1, (int) $request->query('page', 1));

        try {
            $respuesta = $mamore->people($pagina, $porPagina, $busqueda, $contrato);
            $meta = $respuesta['meta'] ?? [];

            $paginador = new Paginador(
                $this->normalizarMamore($respuesta['data'] ?? []),
                (int) ($meta['total'] ?? count($respuesta['data'] ?? [])),
                (int) ($meta['per_page'] ?? $porPagina),
                (int) ($meta['current_page'] ?? $pagina),
                ['path' => $request->url(), 'query' => $request->query()],
            );

            return [$paginador, null, $this->totalesPorContrato($meta)];
        } catch (MamoreException $e) {
            return [$this->paginadorVacio($request, $porPagina), $e->getMessage(), ['con' => null, 'sin' => null]];
        }
    }

    private function totalesPorContrato(array $meta): array
    {
        return [
            'con' => isset($meta['total_con_contrato']) ? (int) $meta['total_con_contrato'] : null,
            'sin' => isset($meta['total_sin_contrato']) ? (int) $meta['total_sin_contrato'] : null,
        ];
    }

    private function normalizarMamore(array $data): array
    {
        return app(DirectorioMamore::class)->normalizar($data);
    }

    private function paginadorVacio(Request $request, int $porPagina): LengthAwarePaginator
    {
        return new Paginador([], 0, $porPagina, 1, ['path' => $request->url(), 'query' => $request->query()]);
    }
}
