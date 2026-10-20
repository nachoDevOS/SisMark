<?php

namespace App\Http\Controllers;

use App\Exceptions\MamoreException;
use App\Http\Requests\ResolverLoteLicenciasRequest;
use App\Http\Requests\RevisarLicenciaRequest;
use App\Http\Requests\StoreLicenciaRequest;
use App\Models\AsignacionHorario;
use App\Models\Licencia;
use App\Services\ContratosFuncionario;
use App\Services\DirectorioMamore;
use App\Services\ProcesadorAsistencia;
use App\Services\RegistroLicencia;
use App\Services\ResolutorNombres;
use App\Services\RespaldoDocumento;
use App\Services\TopePermisos;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Licencias/permisos de personal sobre la base local MySQL, con el flujo del
 * sistema de escritorio: se elige el funcionario, se marcan sus horarios
 * asignados y un rango de fechas, y se anotan las licencias resultantes (una
 * por día y horario). Eliminación lógica, como todo el sistema.
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
        $tipo = $this->tipo($request);

        // Por defecto, el mes en curso completo: del 1.º al último día, porque
        // una licencia se pide por adelantado y los días de fin de mes ya cuentan.
        $desde = (string) $request->query('desde', now()->startOfMonth()->toDateString());
        $hasta = (string) $request->query('hasta', now()->endOfMonth()->toDateString());

        return view('licencias.index', compact('busqueda', 'porPagina', 'estado', 'tipo', 'desde', 'hasta'));
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
        $tipo = $this->tipo($request);
        $desde = trim((string) $request->query('desde', ''));
        $hasta = trim((string) $request->query('hasta', ''));

        // Una fila por solicitud, no por día: el alta expande el rango a una
        // fila por día y horario, así que sin esto «14 al 15 de agosto» sale como
        // dos licencias y parece que hay que aprobarlas por separado.
        //
        // Va en dos pasos —primero qué solicitudes entran, después la fila que
        // abre cada una—; ver la migración `create_licencias_table`.
        $licencias = Licencia::paginarPorSolicitud(
            $this->filtrarListado(Licencia::query(), $busqueda, $estado, $tipo, $desde, $hasta),
            $porPagina,
        )->withQueryString();

        // Hasta qué día llega cada una y cuántos días abarca. Solo de las
        // solicitudes de esta página, así que es una consulta chica por índice.
        $resumen = Licencia::resumenDe($licencias->getCollection()->map->clave_agrupadora);

        // La columna «Funcionario» (nombre y cargo) sale de Mamoré y, si el CI no
        // está ahí, de la base local (App\Services\ResolutorNombres).
        $fichas = $resolutor->fichasPorCi($licencias->pluck('ci'));

        // Para resolver en lote: cuántas solicitudes pendientes —y cuántos
        // días— abarca el filtro entero, no solo esta página. Es lo que ofrece
        // «marcar todas las que coinciden». Sin el permiso no se calcula.
        $lote = null;

        if ($request->user()?->can('Approve:Licencia')) {
            $pendientes = $this->filtrarListado(Licencia::query()->pendientes(), $busqueda, '', $tipo, $desde, $hasta);
            $lote = [
                'solicitudes' => (int) (clone $pendientes)
                    ->selectRaw('COUNT(DISTINCT '.Licencia::CLAVE_AGRUPADORA.') as total')
                    ->value('total'),
                'dias' => (clone $pendientes)->count(),
                'filtros' => ['q' => $busqueda, 'estado' => $estado, 'tipo' => $tipo, 'desde' => $desde, 'hasta' => $hasta],
            ];
        }

        return view('licencias.list', compact('licencias', 'fichas', 'resumen', 'busqueda', 'lote'));
    }

    /**
     * Los filtros del listado sobre una consulta. Los comparten la tabla y la
     * resolución en lote: «todas las que coinciden» tiene que alcanzar
     * exactamente a lo que la tabla dice que coincide.
     */
    private function filtrarListado(Builder $query, string $busqueda, string $estado, string $tipo, string $desde, string $hasta): Builder
    {
        return $query
            ->when($busqueda !== '', fn (Builder $query) => $query->buscar($busqueda))
            ->when($estado !== '', fn (Builder $query) => $query->where('estado', $estado))
            ->when($tipo !== '', fn (Builder $query) => $query->where('tipo', $tipo))
            // Entra la solicitud con algún día dentro del rango; la fila que
            // la abre y el resumen siguen mostrándola entera. Sin `desde` o
            // sin `hasta`, ese extremo queda abierto.
            ->when($desde !== '', fn (Builder $query) => $query->where('fecha', '>=', $desde))
            ->when($hasta !== '', fn (Builder $query) => $query->where('fecha', '<=', $hasta));
    }

    /**
     * Ficha de la solicitud: el rango completo con todos sus días, el motivo, el
     * respaldo y en qué quedó.
     *
     * Se entra por una fila del listado, pero se muestra la **solicitud entera**
     * —todas las filas hermanas—, porque eso es lo que pidió el funcionario: el
     * alta expande el rango a una fila por día y horario, y aprobar de a una
     * obligaría a abrir cinco fichas para una licencia de una semana.
     *
     * Es el paso previo obligado a resolver: no se aprueba un permiso sin haber
     * mirado qué días abarca y qué respaldo trae.
     */
    public function show(Licencia $licencia, ResolutorNombres $resolutor): View
    {
        $this->authorize('view', $licencia);

        $dias = Licencia::query()
            ->with(['horario', 'revisor'])
            ->deLaSolicitud($licencia)
            ->orderBy('fecha')
            ->get();

        $ficha = $resolutor->fichaPorCi(trim((string) $licencia->ci));

        // Cuántos días esperan decisión: es lo que rotula el botón («Aprobar los
        // 5 días») y lo que se va a modificar realmente.
        $pendientes = $dias->where('estado', Licencia::PENDIENTE);

        // Saldo de permisos por horas del mes de la solicitud, para decidir con el
        // dato a la vista. Solo si es un permiso personal por horas y sigue
        // contando —una rechazada no descuenta, una institucional no tiene
        // tope—. Lo aprobado y lo pendiente —esta solicitud incluida— van en
        // columnas aparte.
        $porHoras = $dias->reject(fn (Licencia $dia): bool => $dia->tCompleto
            || $dia->estado === Licencia::RECHAZADO
            || $dia->tipo === Licencia::TIPO_INSTITUCIONAL);
        $saldo = $porHoras->isEmpty() ? null : app(TopePermisos::class)->saldo(
            trim((string) $licencia->ci),
            $porHoras->min('fecha'),
            $porHoras->max('fecha'),
            [],
            null,
            null,
        );

        return view('licencias.show', compact('licencia', 'dias', 'ficha', 'pendientes', 'saldo'));
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
     * Solo toca los días que todavía admiten esa decisión —lo «Pendiente», y
     * al rechazar también lo «Aprobado»—: si otro usuario ya los rechazó
     * mientras esta pantalla estaba abierta, su decisión no se pisa —se informa
     * que no quedaba nada por resolver—.
     */
    private function resolver(RevisarLicenciaRequest $request, Licencia $licencia, string $estado): RedirectResponse
    {
        // Resuelta desde el modal de la ficha, se vuelve ahí con la solapa de
        // licencias abierta: `back()` pierde el ancla y abriría otra solapa.
        $volver = $request->boolean('desde_ficha')
            ? fn (): RedirectResponse => redirect($this->fichaDelFuncionario($licencia))
            : fn (): RedirectResponse => back();

        if ($estado === Licencia::APROBADO && ($exceso = $this->excesoAlAprobar($licencia)) !== null) {
            return $volver()->with('error', $exceso);
        }

        // Aprobar toca lo pendiente; rechazar, también lo ya aprobado.
        $afectados = $this->marcar(
            $licencia,
            $estado,
            $request->validated('observacion'),
            $request->user()?->id,
            $estado === Licencia::RECHAZADO ? [Licencia::PENDIENTE, Licencia::APROBADO] : [Licencia::PENDIENTE],
        );

        if ($afectados === 0) {
            return $volver()->with('error', 'Esta solicitud ya había sido resuelta por otro usuario.');
        }

        $verbo = $estado === Licencia::APROBADO ? 'aprobó' : 'rechazó';
        $mensaje = "Se {$verbo} la solicitud ({$afectados} día(s)).";

        // Al rechazar se vuelve a la ficha del funcionario: el pedido quedó
        // cerrado y ahí no queda nada por hacer, mientras que en su ficha se ve
        // el resto de sus licencias. Al aprobar se queda donde está, para poder
        // comprobar cómo quedaron los días.
        return $estado === Licencia::RECHAZADO
            ? redirect($this->fichaDelFuncionario($licencia))->with('estado', $mensaje)
            : $volver()->with('estado', $mensaje);
    }

    /**
     * Pasa al estado decidido los días de la solicitud que todavía están en
     * alguno de `$desde`, con el autor y la fecha de la revisión. Devuelve
     * cuántos días cambió.
     *
     * @param  list<string>  $desde
     */
    private function marcar(Licencia $licencia, string $estado, ?string $observacion, ?int $revisor, array $desde): int
    {
        return Licencia::query()
            ->deLaSolicitud($licencia)
            ->whereIn('estado', $desde)
            ->update([
                'estado' => $estado,
                'observacion' => $observacion,
                'revisadoPor_id' => $revisor,
                'revisadoEn' => now(),
            ]);
    }

    /**
     * Aprueba en lote las solicitudes pendientes marcadas en el listado.
     */
    public function aprobarLote(ResolverLoteLicenciasRequest $request): RedirectResponse
    {
        return $this->resolverLote($request, Licencia::APROBADO);
    }

    /**
     * Rechaza en lote las solicitudes pendientes marcadas en el listado, todas
     * con el mismo motivo.
     */
    public function rechazarLote(ResolverLoteLicenciasRequest $request): RedirectResponse
    {
        return $this->resolverLote($request, Licencia::RECHAZADO);
    }

    /**
     * Resuelve de una vez varias solicitudes **pendientes**: las marcadas en la
     * página, o todas las que coinciden con el filtro —que se vuelve a aplicar
     * acá, sin confiar en lo que mandó la pantalla—.
     *
     * Cada solicitud queda como si se hubiera resuelto de a una: mismo autor,
     * misma fecha, misma nota. Al aprobar, cada una pasa por el control del tope
     * de permisos: la que lo pasaría queda pendiente y se avisa, sin frenar al
     * resto. Van en orden de fecha, así cada control ya cuenta lo aprobado antes
     * dentro del mismo lote.
     *
     * Sin transacción a propósito: el control del tope puede consultar los
     * contratos en Mamoré, y una llamada HTTP no va dentro de una transacción.
     * Cada solicitud se resuelve en una sola consulta, así que nunca queda una
     * a medias.
     */
    private function resolverLote(ResolverLoteLicenciasRequest $request, string $estado): RedirectResponse
    {
        $filtros = [
            'q' => trim((string) $request->validated('q')),
            'estado' => (string) $request->validated('estado'),
            'tipo' => (string) $request->validated('tipo'),
            'desde' => (string) $request->validated('desde'),
            'hasta' => (string) $request->validated('hasta'),
        ];

        $pendientes = Licencia::query()->pendientes();
        $alcance = $request->boolean('todas')
            ? $this->filtrarListado($pendientes, $filtros['q'], '', $filtros['tipo'], $filtros['desde'], $filtros['hasta'])
            : $pendientes->whereIn('id', $request->validated('solicitudes'));

        $solicitudes = $alcance
            ->orderBy('fecha')
            ->orderBy('id')
            ->get()
            ->unique(fn (Licencia $licencia): string => $licencia->clave_agrupadora)
            ->values();

        $resueltas = 0;
        $dias = 0;
        $topadas = [];

        foreach ($solicitudes as $licencia) {
            if ($estado === Licencia::APROBADO && $this->excesoAlAprobar($licencia) !== null) {
                $topadas[] = trim((string) $licencia->ci);

                continue;
            }

            $afectados = $this->marcar($licencia, $estado, $request->validated('observacion'), $request->user()?->id, [Licencia::PENDIENTE]);

            if ($afectados > 0) {
                $resueltas++;
                $dias += $afectados;
            }
        }

        $volver = redirect()->route('licencias.index', array_filter($filtros, fn (string $valor): bool => $valor !== ''));

        if ($resueltas === 0 && $topadas === []) {
            return $volver->with('error', 'No quedaba nada pendiente en lo marcado: otro usuario ya lo había resuelto.');
        }

        $verbo = $estado === Licencia::APROBADO ? 'aprobaron' : 'rechazaron';
        $volver->with('estado', "Se {$verbo} {$resueltas} solicitud(es) ({$dias} día(s)).");

        // Las que pasan el tope quedan pendientes: se nombran para revisarlas
        // de a una, con el saldo a la vista en su ficha.
        if ($topadas !== []) {
            $volver->with('error', count($topadas).' solicitud(es) quedaron pendientes porque pasan el tope mensual de permisos: CI '
                .implode(', ', array_unique($topadas)).'.');
        }

        return $volver;
    }

    /**
     * Si aprobar la solicitud pasa el tope mensual de permisos, el motivo; si
     * no, `null`.
     *
     * Al pedir ya se controló contra lo aprobado y lo pendiente; acá se mira
     * solo lo aprobado más esta solicitud, por si el tope se bajó después de
     * pedirla. Solo aplica a los permisos personales por horas.
     */
    private function excesoAlAprobar(Licencia $licencia): ?string
    {
        $porHoras = Licencia::query()
            ->deLaSolicitud($licencia)
            ->pendientes()
            ->where('tCompleto', false)
            ->where('tipo', Licencia::TIPO_PERSONAL)
            ->whereNotNull('lEntra')
            ->whereNotNull('lSale')
            ->get(['fecha', 'lEntra', 'lSale']);

        if ($porHoras->isEmpty()) {
            return null;
        }

        $ci = trim((string) $licencia->ci);
        $tope = app(TopePermisos::class);
        // Los días de una solicitud comparten horario: salen del mismo pedido.
        $excesos = $tope->excesos(
            [$ci => $porHoras->map(fn (Licencia $dia): string => $dia->fecha->toDateString())->unique()->values()->all()],
            $porHoras->first()->lEntra->format('H:i'),
            $porHoras->first()->lSale->format('H:i'),
            conPendientes: false,
        );

        return $excesos === [] ? null : 'No se puede aprobar. '.$tope->mensajeDeExcesos($excesos, false);
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
     * grilla de sus horarios asignados más el formulario del rango.
     *
     * Los datos personales salen exclusivamente de la API de Mamoré; lo local
     * son los horarios, que se cruzan por CI.
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

        // Por defecto solo los horarios vigentes: un funcionario antiguo arrastra
        // decenas de asignaciones viejas que solo son ruido.
        $incluirVencidos = $request->boolean('vencidos');

        $asignaciones = $persona !== null
            ? $this->horariosAsignados($persona['ci'], $incluirVencidos)
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

        // Las fechas van primero y deciden a quién se puede elegir. Al elegir un
        // funcionario la página se recarga con `?ci=`, y las fechas viajan con
        // él para no perderlas.
        $desde = $this->fechaDelPedido($request, 'desde') ?? now()->toDateString();
        $hasta = $this->fechaDelPedido($request, 'hasta') ?? $desde;

        return view('licencias.create', compact(
            'persona', 'ci', 'asignaciones', 'incluirVencidos', 'vencidos', 'elegidos', 'errorMamore', 'ciDesconocido',
            'desde', 'hasta',
        ));
    }

    /**
     * Búsqueda de funcionarios por CI o nombre para el combo de la pantalla
     * «Licenciar», contra la API de Mamoré. Devuelve hasta 20 coincidencias como
     * JSON, o un 502 con el motivo si la API no responde.
     *
     * Con `desde`/`hasta` solo trae a quien tuvo contrato en esas fechas, con
     * la vigencia de ese contrato para mostrarla en la lista.
     */
    public function buscarFuncionarios(Request $request, DirectorioMamore $directorio): JsonResponse
    {
        $this->authorize('create', Licencia::class);

        $q = trim((string) $request->query('q', ''));

        if ($q === '') {
            return response()->json([]);
        }

        $desde = $this->fechaDelPedido($request, 'desde');
        $hasta = $this->fechaDelPedido($request, 'hasta') ?? $desde;

        try {
            $funcionarios = $directorio->buscar($q, desde: $desde, hasta: $hasta);
        } catch (MamoreException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        return response()->json($funcionarios->map(fn (array $persona): array => [
            'id' => $persona['ci'],
            'texto' => $directorio->etiqueta($persona),
            // Con fechas en el pedido es el contrato de ese período.
            'contrato' => $persona['contratoDesde'] === null ? null : self::vigencia(
                Carbon::parse($persona['contratoDesde'])->format('d/m/Y'),
                $persona['contratoHasta'] === null ? null : Carbon::parse($persona['contratoHasta'])->format('d/m/Y'),
            ),
        ])->values());
    }

    /**
     * Contrato de cada funcionario elegido en las fechas del pedido, para la
     * pantalla «Licenciar»: al cambiar las fechas con alguien ya elegido, se
     * revisa si sigue teniendo contrato.
     *
     * Por cédula, el texto de la vigencia o `null` si no tuvo contrato esos días.
     * Con Mamoré sin configurar responde `configurado: false` y no afirma nada.
     */
    public function contratos(Request $request, ContratosFuncionario $contratos): JsonResponse
    {
        $this->authorize('create', Licencia::class);

        $datos = $request->validate([
            'cis' => ['required', 'array', 'max:500'],
            'cis.*' => ['string', 'max:20'],
            'desde' => ['required', 'date'],
            'hasta' => ['required', 'date', 'after_or_equal:desde'],
        ]);

        try {
            $tramos = $contratos->tramosDeVarios(
                array_values(array_map(trim(...), $datos['cis'])),
                Carbon::parse($datos['desde']),
                Carbon::parse($datos['hasta']),
            );
        } catch (MamoreException $e) {
            return response()->json(['error' => 'No se pudieron revisar los contratos: '.$e->getMessage()], 502);
        }

        if ($tramos === null) {
            return response()->json(['configurado' => false, 'contratos' => (object) []]);
        }

        return response()->json([
            'configurado' => true,
            'contratos' => array_map(fn (array $delCi): ?string => $delCi === []
                ? null
                : implode(' · ', array_map(fn (array $tramo): string => self::vigencia(
                    $tramo['desde']->format('d/m/Y'),
                    $tramo['hasta']?->format('d/m/Y'),
                ), $delCi)), $tramos),
        ]);
    }

    /**
     * «Contrato del 05/01/2026 al 30/11/2026», o «… en adelante» sin fin.
     */
    private static function vigencia(string $desde, ?string $hasta): string
    {
        return 'Contrato del '.$desde.($hasta === null ? ' en adelante' : ' al '.$hasta);
    }

    /**
     * Una fecha `Y-m-d` de la consulta, o `null` si no vino o no es una fecha.
     */
    private function fechaDelPedido(Request $request, string $campo): ?string
    {
        $valor = trim((string) $request->query($campo, ''));

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) === 1 && strtotime($valor) !== false ? $valor : null;
    }

    /**
     * Saldo de permisos por horas del funcionario contra el tope mensual, para
     * el recuadro de «Licenciar»: cuánto lleva en cada mes —y contrato— del
     * rango, cuánto suma lo que se está armando y si se pasa.
     *
     * Resuelve los días igual que el alta —horarios del rango, los elegidos a
     * mano, sin los días ya ocupados— y calcula con el mismo servicio que la
     * bloquea al guardar, así lo que se ve es lo que va a pasar.
     *
     * Responde `activo: false` cuando no hay tope configurado.
     */
    public function saldo(Request $request, RegistroLicencia $registro, TopePermisos $tope): JsonResponse
    {
        $this->authorize('create', Licencia::class);

        $datos = $request->validate([
            'ci' => ['required', 'string', 'max:20'],
            'desde' => ['required', 'date'],
            'hasta' => ['required', 'date', 'after_or_equal:desde'],
            'lEntra' => ['nullable', 'date_format:H:i'],
            'lSale' => ['nullable', 'date_format:H:i'],
            'asignaciones' => ['nullable', 'array'],
            'asignaciones.*' => ['integer'],
        ]);

        $desde = Carbon::parse($datos['desde'])->startOfDay();
        $hasta = Carbon::parse($datos['hasta'])->startOfDay();

        if ($desde->diffInDays($hasta) + 1 > RegistroLicencia::MAX_DIAS) {
            return response()->json(['error' => 'El rango no puede superar '.RegistroLicencia::MAX_DIAS.' días.'], 422);
        }

        $ci = trim($datos['ci']);
        $asignaciones = $this->horariosDelRango([$ci], $desde, $hasta, array_map('intval', $datos['asignaciones'] ?? []));
        $lEntra = $datos['lEntra'] ?? null;
        $lSale = $datos['lSale'] ?? null;
        $fechas = $registro->fechasNuevas($asignaciones, $desde, $hasta, $lEntra, $lSale)[$ci] ?? [];

        // Salida y entrada invertidas no son un pedido: se muestra solo lo usado,
        // y la validación del formulario avisa al guardar.
        if ($lEntra !== null && $lSale !== null && $lSale <= $lEntra) {
            $lEntra = $lSale = null;
        }

        $saldo = $tope->saldo($ci, $desde, $hasta, $fechas, $lEntra, $lSale);

        if ($saldo === null) {
            return response()->json(['activo' => false]);
        }

        $duracion = fn (int $minutos): string => ProcesadorAsistencia::duracion($minutos * 60);

        return response()->json([
            'activo' => true,
            'tope' => $duracion($saldo['tope']),
            'alcance' => $saldo['porContrato'] ? 'por contrato' : 'por mes',
            'excede' => $saldo['excede'],
            'dias' => count($fechas),
            'bolsas' => array_map(fn (array $bolsa): array => [
                'titulo' => $bolsa['titulo'],
                'usado' => $duracion($bolsa['usado']),
                'pedido' => $bolsa['pedido'] > 0 ? $duracion($bolsa['pedido']) : null,
                'queda' => $duracion($bolsa['queda']),
                'excede' => $bolsa['excede'],
            ], $saldo['bolsas']),
        ]);
    }

    /**
     * Direcciones y unidades administrativas para los combos del alcance «por
     * dirección», con cuánta gente tuvo cada una en el rango.
     *
     * Es el mismo catálogo que usa el reporte por dirección, pero con su propia
     * ruta: aquella exige el permiso de reportes, y quien anota licencias no
     * tiene por qué tenerlo.
     *
     * Las dos viajan juntas porque salen del mismo pedido: la unidad se elige
     * recién después de la dirección, y pedirlas por separado gastaría un segundo
     * viaje de una cuota de 60 por minuto.
     */
    public function direcciones(Request $request, DirectorioMamore $directorio): JsonResponse
    {
        $this->authorize('create', Licencia::class);

        if (! $directorio->configurado()) {
            return response()->json(['error' => 'La API de Mamoré no está configurada, así que no hay direcciones que listar.'], 503);
        }

        try {
            $estructura = $directorio->estructura(
                (string) $request->query('desde', '') ?: null,
                (string) $request->query('hasta', '') ?: null,
            );
        } catch (MamoreException $e) {
            return response()->json(['error' => 'No se pudieron traer las direcciones: '.$e->getMessage()], 502);
        }

        return response()->json([
            'direcciones' => $estructura['direcciones']
                ->map(fn (array $fila): array => $this->opcionDelCombo($fila))
                ->values(),
            'unidades' => $estructura['unidades']
                ->map(fn (array $fila): array => $this->opcionDelCombo($fila) + [
                    'direccionId' => $fila['direccionId'],
                ])
                ->values(),
        ]);
    }

    /**
     * Una dirección o una unidad como la pinta el combo: «SIGLA — Nombre».
     *
     * @param  array{id: int, nombre: string, sigla: string, funcionarios: int}  $fila
     * @return array{id: int, texto: string, funcionarios: int}
     */
    private function opcionDelCombo(array $fila): array
    {
        return [
            'id' => $fila['id'],
            'texto' => ($fila['sigla'] !== '' ? $fila['sigla'].' — ' : '').$fila['nombre'],
            'funcionarios' => $fila['funcionarios'],
        ];
    }

    /**
     * El personal **con contrato firmado** de la dirección (o unidad) elegida,
     * para mostrarlo antes de anotar.
     *
     * Se muestra la lista y no solo un número porque el alcance «por dirección»
     * anota sin que nadie elija uno por uno: quien firma el permiso tiene que
     * poder ver a quiénes va a alcanzar antes de darle a guardar.
     *
     * La lista es informativa; quién termina licenciado lo vuelve a resolver el
     * `store` contra Mamoré, así que un envío manipulado no puede ampliarla.
     *
     * Cada uno viaja con `sinTurno`: tener contrato no alcanza, el alta solo
     * licencia a quien además tenga turno asignado esos días, y conviene verlo
     * antes de guardar y no enterarse por el conteo del resultado.
     */
    public function funcionariosDeDireccion(Request $request, DirectorioMamore $directorio, RegistroLicencia $registro): JsonResponse
    {
        $this->authorize('create', Licencia::class);

        $direccion = (int) $request->query('direccion', 0);

        if ($direccion <= 0) {
            return response()->json(['error' => 'Elegí una dirección.'], 422);
        }

        if (! $directorio->configurado()) {
            return response()->json(['error' => 'La API de Mamoré no está configurada.'], 503);
        }

        $desde = $this->fechaDelPedido($request, 'desde') ?? now()->toDateString();
        $hasta = $this->fechaDelPedido($request, 'hasta') ?? $desde;

        try {
            $personas = $directorio->porDireccion(
                $direccion,
                $desde,
                $hasta,
                (int) $request->query('unidad', 0) ?: null,
                soloConContrato: true,
            );
        } catch (MamoreException $e) {
            return response()->json(['error' => 'No se pudo traer el personal: '.$e->getMessage()], 502);
        }

        return $this->listaParaMarcar($personas, $registro, $desde, $hasta);
    }

    /**
     * El personal con contrato de **toda la Gobernación** en las fechas, para
     * el alcance «todos»: la misma lista que por dirección, con `sinTurno`.
     */
    public function funcionariosConContrato(Request $request, DirectorioMamore $directorio, RegistroLicencia $registro): JsonResponse
    {
        $this->authorize('create', Licencia::class);

        if (! $directorio->configurado()) {
            return response()->json(['error' => 'La API de Mamoré no está configurada.'], 503);
        }

        $desde = $this->fechaDelPedido($request, 'desde') ?? now()->toDateString();
        $hasta = $this->fechaDelPedido($request, 'hasta') ?? $desde;

        try {
            $personas = $directorio->conContrato($desde, $hasta);
        } catch (MamoreException $e) {
            return response()->json(['error' => 'No se pudo traer el personal: '.$e->getMessage()], 502);
        }

        return $this->listaParaMarcar($personas, $registro, $desde, $hasta);
    }

    /**
     * La lista que la pantalla muestra para marcar a quién se licencia: el
     * turno de cada uno esos días («08:00–16:00»), `sinTurno` en quien no
     * tiene, y el género, para acotar la lista (el día de la madre, por ejemplo).
     *
     * @param  Collection<int, array<string, mixed>>  $personas
     */
    private function listaParaMarcar(Collection $personas, RegistroLicencia $registro, string $desde, string $hasta): JsonResponse
    {
        $turnos = $registro->turnos(
            $personas->pluck('ci')->map(fn (string $ci): string => trim($ci))->all(),
            Carbon::parse($desde)->startOfDay(),
            Carbon::parse($hasta)->startOfDay(),
        );

        return response()->json([
            'funcionarios' => $personas->map(fn (array $persona): array => [
                'ci' => $persona['ci'],
                'nombre' => $persona['nombreFormal'] ?: $persona['nombre'],
                'cargo' => $persona['cargo'] ?: '—',
                'genero' => $persona['genero'],
                'turno' => implode(' · ', $turnos[trim($persona['ci'])] ?? []),
                'sinTurno' => ! isset($turnos[trim($persona['ci'])]),
            ])->values(),
        ]);
    }

    /**
     * Anota las licencias del rango, para un funcionario, varios, o la dirección
     * (o unidad) elegida. La expansión (un registro por funcionario, día y horario)
     * la hace el servicio; la validación, el Request.
     */
    public function store(
        StoreLicenciaRequest $request,
        RegistroLicencia $registro,
        RespaldoDocumento $respaldos,
        DirectorioMamore $directorio,
        TopePermisos $tope,
        ContratosFuncionario $contratosFuncionario,
    ): RedirectResponse {
        $datos = $request->validated();

        $modo = $datos['modo'];
        $desde = Carbon::parse($datos['desde'])->startOfDay();
        $hasta = Carbon::parse($datos['hasta'])->startOfDay();
        // Los horarios elegidos a mano solo aplican al alta de un funcionario.
        $elegidas = $modo === 'uno' ? ($datos['asignaciones'] ?? []) : [];

        try {
            $cis = match ($modo) {
                'uno' => [trim((string) $datos['ci'])],
                'varios' => array_map(trim(...), $datos['cis']),
                // El personal con contrato de toda la Gobernación, acotado a los
                // marcados en la pantalla.
                'todos' => $this->acotarALaSeleccion($directorio->conContrato($desde->toDateString(), $hasta->toDateString()), $datos),
                // La dirección se resuelve a carnets antes de tocar los horarios:
                // quién la integra lo sabe Mamoré, y acá solo se licencia a quien
                // además tenga horario asignado en el rango.
                default => $this->cisDeLaDireccion($directorio, $datos, $desde, $hasta),
            };
        } catch (MamoreException $e) {
            return back()
                ->withInput()
                ->with('error', ($modo === 'todos' ? 'No se pudo traer el personal desde Mamoré: ' : 'No se pudo traer el personal de la dirección desde Mamoré: ').$e->getMessage());
        }

        // Un alcance sin nadie con contrato no llega siquiera a buscar horarios:
        // con la lista vacía, `horariosDelRango` no acota por carnet y licenciaría
        // a toda la Gobernación.
        if ($modo === 'direccion' && $cis === []) {
            return back()
                ->withInput()
                ->with('error', ($datos['cis'] ?? []) === []
                    ? 'Ningún funcionario de esa dirección tiene contrato firmado dentro del rango.'
                    : 'Los funcionarios elegidos no pertenecen a esa dirección, o no tienen contrato firmado en el rango.');
        }

        if ($modo === 'todos' && $cis === []) {
            return back()
                ->withInput()
                ->with('error', 'Ninguno de los funcionarios elegidos tiene contrato dentro del rango.');
        }

        // Los contratos de cada uno en el rango, en una sola consulta. Sirven
        // para dos cosas: frenar a quien no tuvo contrato ningún día, y
        // licenciar solo los días que el contrato cubre —quien se fue el 15 no
        // recibe licencia del 16 en adelante—. Con Mamoré sin configurar vuelve
        // `null`: no se sabe, y no se frena ni se recorta nada.
        try {
            $contratos = $contratosFuncionario->tramosDeVarios($cis, $desde, $hasta);
        } catch (MamoreException $e) {
            return back()
                ->withInput()
                ->with('error', 'No se pudieron revisar los contratos en Mamoré: '.$e->getMessage());
        }

        // Uno o varios funcionarios: cada uno tiene que haber tenido contrato
        // —firmado o ya concluido— en algún día de las fechas. La pantalla ya lo
        // filtra al buscar; acá se vuelve a controlar, porque el pedido puede
        // venir armado con otras fechas. Por dirección y para todos ya lo
        // resuelve Mamoré.
        $sinContrato = $contratos === null || in_array($modo, ['direccion', 'todos'], true)
            ? []
            : array_values(array_filter($cis, fn (string $ci): bool => ($contratos[$ci] ?? []) === []));

        if ($sinContrato !== []) {
            return back()
                ->withInput()
                ->with('error', 'Sin contrato entre el '.$desde->format('d/m/Y').' y el '.$hasta->format('d/m/Y')
                    .': CI '.implode(', ', $sinContrato).'. Quitalos o cambiá las fechas.');
        }

        $asignacionesPorCi = $this->horariosDelRango($cis, $desde, $hasta, $elegidas);

        if ($asignacionesPorCi->isEmpty()) {
            return back()
                ->withInput()
                ->with('error', $this->motivoSinHorarios($modo, $elegidas));
        }

        // Tope mensual de permisos por horas, solo para los permisos personales:
        // si alguien se pasa, no se anota nada, ni siquiera a los demás. Anotar
        // a medias dejaría a Recursos Humanos adivinando a quién le faltó.
        if ($datos['tipo'] === Licencia::TIPO_PERSONAL && ! $datos['tCompleto']) {
            $excesos = $tope->excesos(
                $registro->fechasNuevas($asignacionesPorCi, $desde, $hasta, $datos['lEntra'], $datos['lSale'], $contratos),
                (string) $datos['lEntra'],
                (string) $datos['lSale'],
            );

            if ($excesos !== []) {
                return back()
                    ->withInput()
                    ->with('error', $tope->mensajeDeExcesos($excesos, $modo !== 'uno'));
            }
        }

        // El respaldo se sube una sola vez y la ruta se copia a todas las filas
        // que genere el rango: son la misma licencia partida por día y horario.
        // Va después de resolver los horarios, para no dejar un archivo huérfano
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
            'tipo' => $datos['tipo'],
        ], $contratos);

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
     * Los carnets de la dirección (o de una de sus unidades) que tienen contrato
     * firmado dentro del rango.
     *
     * Solo los contratados: a quien no tiene contrato no hay jornada que
     * licenciarle, y meterlo en la lista anotaría permisos sobre horarios que
     * quedaron de una asignación vieja.
     *
     * ---
     * **La selección de la pantalla acota, nunca amplía.** Quien marca de a uno
     * manda esos carnets en `cis`, y acá se cruzan contra los que Mamoré dice que
     * integran la dirección: un envío manipulado que agregue a un tercero lo
     * pierde en el cruce. Sin `cis` entra la dirección entera, que es lo que el
     * alcance significa.
     * ---
     *
     * @param  array<string, mixed>  $datos
     * @return list<string>
     *
     * @throws MamoreException
     */
    private function cisDeLaDireccion(DirectorioMamore $directorio, array $datos, Carbon $desde, Carbon $hasta): array
    {
        return $this->acotarALaSeleccion($directorio->porDireccion(
            (int) $datos['direccion'],
            $desde->toDateString(),
            $hasta->toDateString(),
            isset($datos['unidad']) ? ((int) $datos['unidad'] ?: null) : null,
            soloConContrato: true,
        ), $datos);
    }

    /**
     * Los carnets del alcance que Mamoré resolvió, cruzados con los marcados en
     * la pantalla (`cis`). Sin `cis` entra el alcance entero; con ellos, solo
     * los que además están en el alcance.
     *
     * @param  Collection<int, array<string, mixed>>  $personas
     * @param  array<string, mixed>  $datos
     * @return list<string>
     */
    private function acotarALaSeleccion(Collection $personas, array $datos): array
    {
        $delAlcance = $personas
            ->pluck('ci')
            ->map(fn (string $ci): string => trim($ci))
            ->filter()
            ->unique()
            ->values();

        $elegidos = collect($datos['cis'] ?? [])
            ->map(fn ($ci): string => trim((string) $ci))
            ->filter()
            ->unique();

        return $elegidos->isEmpty()
            ? $delAlcance->all()
            : $delAlcance->intersect($elegidos)->values()->all();
    }

    /**
     * Por qué no hubo ningún horario que licenciar, según el alcance pedido.
     *
     * @param  list<int>  $elegidas
     */
    private function motivoSinHorarios(string $modo, array $elegidas): string
    {
        if ($elegidas !== []) {
            return 'Los horarios elegidos no pertenecen a ese funcionario.';
        }

        return match ($modo) {
            'direccion' => 'Ningún funcionario con contrato de esa dirección tiene horarios asignados dentro de ese rango de fechas.',
            'todos' => 'Ninguno de los funcionarios elegidos tiene horarios asignados dentro de ese rango de fechas.',
            default => 'El funcionario no tiene ningún horario asignado dentro de ese rango de fechas.',
        };
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
        // de `AppServiceProvider` le concede todo al rol Administrador sin llegar
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
     * Filtro de tipo del listado: uno de {@see Licencia::TIPOS}, o vacío para
     * todos. Lo desconocido vale como vacío, por lo mismo que el estado.
     */
    private function tipo(Request $request): string
    {
        $tipo = trim((string) $request->query('tipo', ''));

        return array_key_exists($tipo, Licencia::TIPOS) ? $tipo : '';
    }

    /**
     * Horarios asignados al funcionario para la grilla «Horarios asignados a …».
     * El orden y el filtro por vigencia viven en el scope del modelo, que es el
     * mismo que usa la ficha del funcionario.
     *
     * @return Collection<int, AsignacionHorario>
     */
    private function horariosAsignados(string $ci, bool $incluirVencidos = false): Collection
    {
        return AsignacionHorario::query()->delFuncionario($ci, $incluirVencidos)->get();
    }

    /**
     * Horarios a licenciar, agrupados por carnet. Sin selección manual se toman
     * las asignaciones cuya vigencia se solapa con el rango pedido: el rango de
     * fechas ya dice qué días son, marcar además los días de la semana sería
     * pedir el mismo dato dos veces. La selección manual queda para el caso de
     * doble horario en un día.
     *
     * Con `$cis` vacío no se acota por funcionario: es el alcance «todos», donde
     * el rango define a quiénes alcanza. Es una sola consulta para cualquier
     * cantidad de funcionarios (un feriado toca más de 400).
     *
     * @param  list<string>  $cis
     * @param  list<int>  $elegidas
     * @return Collection<string, Collection<int, AsignacionHorario>>
     */
    private function horariosDelRango(array $cis, Carbon $desde, Carbon $hasta, array $elegidas): Collection
    {
        return app(RegistroLicencia::class)->horariosDelRango($cis, $desde, $hasta, $elegidas);
    }

    /**
     * Cuántas asignaciones vencidas tiene el funcionario, para ofrecer verlas
     * sin cargarlas de entrada.
     */
    private function contarVencidos(string $ci): int
    {
        return AsignacionHorario::query()
            ->join('horarios', 'horarios.id', '=', 'asignacion_horarios.horario_id')
            ->whereNull('horarios.deleted_at')
            ->where('asignacion_horarios.ci', $ci)
            ->where('asignacion_horarios.hasta', '<', today()->toDateString())
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
