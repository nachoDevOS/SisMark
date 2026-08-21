<?php

namespace App\Http\Controllers;

use App\Exceptions\MamoreException;
use App\Http\Requests\RevisarLicenciaRequest;
use App\Http\Requests\StoreLicenciaRequest;
use App\Models\AsignacionTurno;
use App\Models\Licencia;
use App\Services\DirectorioMamore;
use App\Services\ProcesadorAsistencia;
use App\Services\RegistroLicencia;
use App\Services\ResolutorNombres;
use App\Services\RespaldoDocumento;
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
        $estado = $this->estado($request);

        return view('licencias.index', compact('busqueda', 'porPagina', 'estado'));
    }

    /**
     * Devuelve el parcial de la tabla (filas + paginación) para el AJAX.
     */
    public function list(Request $request, ResolutorNombres $resolutor): View
    {
        $this->authorize('viewAny', Licencia::class);

        $busqueda = trim((string) $request->query('q', ''));
        $porPagina = $this->porPagina($request);
        $estado = $this->estado($request);

        // Una fila por solicitud, no por día: el alta expande el rango a una
        // fila por día y turno, así que sin esto «14 al 15 de agosto» sale como
        // dos licencias y parece que hay que aprobarlas por separado.
        //
        // Va en dos pasos —primero qué solicitudes entran, después la fila que
        // abre cada una—; ver la migración `agregar_solicitud_a_licencias`.
        $licencias = Licencia::paginarPorSolicitud(
            Licencia::query()
                ->when($busqueda !== '', fn (Builder $query) => $query->buscar($busqueda))
                ->when($estado !== '', fn (Builder $query) => $query->where('estado', $estado)),
            $porPagina,
        )->withQueryString();

        // Hasta qué día llega cada una y cuántos días abarca. Solo de las
        // solicitudes de esta página, así que es una consulta chica por índice.
        $resumen = Licencia::resumenDe($licencias->getCollection()->map->clave_agrupadora);

        // La columna «Funcionario» (nombre y cargo) sale de Mamoré y, si el CI no
        // está ahí, de la base local (App\Services\ResolutorNombres).
        $fichas = $resolutor->fichasPorCi($licencias->pluck('ci'));

        return view('licencias.list', compact('licencias', 'fichas', 'resumen', 'busqueda'));
    }

    /**
     * Ficha de la solicitud: el rango completo con todos sus días, el motivo, el
     * respaldo y en qué quedó.
     *
     * Se entra por una fila del listado, pero se muestra la **solicitud entera**
     * —todas las filas hermanas—, porque eso es lo que pidió el funcionario: el
     * alta expande el rango a una fila por día y turno, y aprobar de a una
     * obligaría a abrir cinco fichas para una licencia de una semana.
     *
     * Es el paso previo obligado a resolver: no se aprueba un permiso sin haber
     * mirado qué días abarca y qué respaldo trae.
     */
    public function show(Licencia $licencia, ResolutorNombres $resolutor): View
    {
        $this->authorize('view', $licencia);

        $dias = Licencia::query()
            ->with(['turno', 'revisor'])
            ->deLaSolicitud($licencia)
            ->orderBy('fecha')
            ->get();

        $ficha = $resolutor->fichaPorCi(trim((string) $licencia->ci));

        // Cuántos días esperan decisión: es lo que rotula el botón («Aprobar los
        // 5 días») y lo que se va a modificar realmente.
        $pendientes = $dias->where('estado', Licencia::PENDIENTE);

        return view('licencias.show', compact('licencia', 'dias', 'ficha', 'pendientes'));
    }

    /**
     * Aprueba la solicitud: los días pendientes pasan a «Aprobado» y recién ahí
     * el cálculo de asistencia los descuenta ({@see ProcesadorAsistencia}
     * solo mira las aprobadas).
     */
    public function aprobar(RevisarLicenciaRequest $request, Licencia $licencia): RedirectResponse
    {
        return $this->resolver($request, $licencia, Licencia::APROBADO);
    }

    /**
     * Rechaza la solicitud. El motivo es obligatorio: el funcionario lo ve en su
     * perfil, y sin él se entera de que le negaron el permiso pero no de por qué.
     */
    public function rechazar(RevisarLicenciaRequest $request, Licencia $licencia): RedirectResponse
    {
        return $this->resolver($request, $licencia, Licencia::RECHAZADO);
    }

    /**
     * Deja la solicitud en el estado decidido, en una sola consulta.
     *
     * Solo toca los días que siguen «Pendiente»: si otro usuario resolvió la
     * misma solicitud mientras esta pantalla estaba abierta, su decisión no se
     * pisa —se informa que no quedaba nada por resolver—.
     */
    private function resolver(RevisarLicenciaRequest $request, Licencia $licencia, string $estado): RedirectResponse
    {
        $afectados = Licencia::query()
            ->deLaSolicitud($licencia)
            ->pendientes()
            ->update([
                'estado' => $estado,
                'observacion' => $request->validated('observacion'),
                'revisadoPor_id' => $request->user()?->id,
                'revisadoEn' => now(),
            ]);

        if ($afectados === 0) {
            return back()->with('error', 'Esta solicitud ya había sido resuelta por otro usuario.');
        }

        $verbo = $estado === Licencia::APROBADO ? 'aprobó' : 'rechazó';
        $mensaje = "Se {$verbo} la solicitud ({$afectados} día(s)).";

        // Al rechazar se vuelve a la ficha del funcionario: el pedido quedó
        // cerrado y ahí no queda nada por hacer, mientras que en su ficha se ve
        // el resto de sus licencias. Al aprobar se queda donde está, para poder
        // comprobar cómo quedaron los días.
        return $estado === Licencia::RECHAZADO
            ? redirect($this->fichaDelFuncionario($licencia))->with('estado', $mensaje)
            : back()->with('estado', $mensaje);
    }

    /**
     * Ficha del funcionario dueño de la licencia, abierta en la solapa de
     * licencias.
     *
     * Se prefiere la ficha local cuando la persona está en `personas`; si no
     * está —el padrón lo manda Mamoré y no todos tienen registro local— se va a
     * la ficha por cédula, que no necesita fila en esta base.
     */
    private function fichaDelFuncionario(Licencia $licencia): string
    {
        $ci = trim((string) $licencia->ci);

        // El ancla deja abierta la solapa de licencias al llegar.
        return $licencia->persona
            ? route('funcionarios.show', ['persona' => $licencia->persona]).'#licencias'
            : route('funcionarios.mamore', ['ci' => $ci]).'#licencias';
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
    public function store(StoreLicenciaRequest $request, RegistroLicencia $registro, RespaldoDocumento $respaldos): RedirectResponse
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
            ? $respaldos->guardar($request->file('respaldo'), 'licencias', $cis[0] ?? '')
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
     * Elimina (lógicamente) la solicitud entera: todos sus días.
     *
     * Da de baja la solicitud y no la fila porque es lo que se ve en pantalla:
     * borrar un día suelto de un pedido de cinco dejaría una licencia a la que
     * le falta un día en el medio, sin que nadie lo haya decidido. En lo migrado
     * del SIA, que no tiene `solicitud`, la fila **es** la licencia y se elimina
     * una sola.
     *
     * Se recorre fila por fila en vez de un borrado masivo para que el trait de
     * auditoría escriba quién la dio de baja y por qué en cada una.
     *
     * El respaldo **no** se borra del bucket: la eliminación es lógica y las
     * filas se pueden restaurar.
     */
    public function destroy(Licencia $licencia): RedirectResponse
    {
        $this->authorize('delete', $licencia);

        // Qué no se da de baja y por qué lo decide el modelo, así el botón y el
        // servidor usan el mismo criterio. No va en la policy: el `Gate::before`
        // de `AppServiceProvider` le concede todo al rol super_admin sin llegar
        // a ejecutarla.
        if ($motivo = $licencia->motivoParaNoEliminar()) {
            return back()->with('error', $motivo);
        }

        $dias = Licencia::query()->deLaSolicitud($licencia)->get();

        foreach ($dias as $dia) {
            $dia->delete();
        }

        return back()->with('estado', $dias->count() === 1
            ? 'Licencia eliminada.'
            : "Se eliminó la solicitud ({$dias->count()} días).");
    }

    /**
     * Redirige al respaldo de la licencia con un enlace temporal y firmado.
     *
     * El archivo no se sirve por acá ni se hace público: un certificado médico
     * no puede quedar accesible con solo adivinar la URL. Se comprueba el
     * permiso de lectura y recién ahí se pide al bucket un enlace de vida corta.
     */
    public function respaldo(Licencia $licencia, RespaldoDocumento $respaldos): RedirectResponse
    {
        $this->authorize('viewAny', Licencia::class);

        $enlace = $respaldos->enlace($licencia->adjunto);

        return $enlace === null
            ? back()->with('error', 'La licencia no tiene respaldo cargado, o el archivo ya no está disponible.')
            : redirect()->away($enlace);
    }

    /**
     * Estado por el que se filtra el listado, o cadena vacía por «todos».
     *
     * Se valida contra la lista conocida en vez de pasarlo tal cual: es un valor
     * del navegador que entra en un `where`, y así un estado inventado devuelve
     * el listado completo en lugar de una tabla vacía sin explicación.
     */
    private function estado(Request $request): string
    {
        $estado = trim((string) $request->query('estado', ''));

        return in_array($estado, Licencia::ESTADOS, true) ? $estado : '';
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
        return app(RegistroLicencia::class)->turnosDelRango($cis, $desde, $hasta, $elegidas);
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
