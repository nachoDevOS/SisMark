<?php

namespace App\Http\Controllers;

use App\Exceptions\DeviceServiceException;
use App\Http\Requests\StoreEquipoRequest;
use App\Http\Requests\UpdateEquipoRequest;
use App\Models\Equipo;
use App\Models\EquipoAuditoria;
use App\Services\DeviceService;
use App\Services\SincronizadorEquipos;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * CRUD clásico (MVC) de los equipos biométricos.
 */
class EquipoController extends Controller
{
    /**
     * Listado de equipos, del más reciente al más antiguo, con búsqueda por
     * nombre, IP o ubicación.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Equipo::class);

        $busqueda = trim((string) $request->query('q', ''));
        $porPagina = $this->porPagina($request);

        $equipos = Equipo::query()
            ->when($busqueda !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('nombre', 'like', "%{$busqueda}%")
                ->orWhere('ip', 'like', "%{$busqueda}%")
                ->orWhere('ubicacion', 'like', "%{$busqueda}%")))
            ->latest()
            ->paginate($porPagina)
            ->withQueryString();

        return view('equipos.index', compact('equipos', 'busqueda', 'porPagina'));
    }

    /**
     * Bitácora de acciones sobre las marcaciones de los equipos: quién exportó,
     * quién envió a la base del SIA, quién vació un reloj y quién dio de baja
     * un equipo, con el motivo en los dos últimos casos.
     *
     * Se filtra por acción (`?accion=`) y se busca por nombre/IP del equipo o
     * por el usuario que la ejecutó.
     */
    public function auditoria(Request $request): View
    {
        // Permiso propio: administrar los relojes y auditar lo que se hizo con
        // ellos no tienen por qué ser la misma persona.
        $this->authorize('viewAny', EquipoAuditoria::class);

        $busqueda = trim((string) $request->query('q', ''));
        $accion = (string) $request->query('accion', '');
        $porPagina = $this->porPagina($request, 25);

        $registros = EquipoAuditoria::query()
            ->with('usuario')
            ->when(
                array_key_exists($accion, EquipoAuditoria::ETIQUETAS),
                fn (Builder $query) => $query->where('accion', $accion),
            )
            ->when($busqueda !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                // El nombre y la IP se buscan sobre la foto guardada, no sobre
                // la tabla de equipos: así el filtro sigue funcionando para
                // equipos que ya se dieron de baja.
                ->where('datos_equipo', 'like', "%{$busqueda}%")
                ->orWhere('motivo', 'like', "%{$busqueda}%")
                ->orWhereHas('usuario', fn (Builder $usuario) => $usuario
                    ->where('name', 'like', "%{$busqueda}%"))))
            ->latest()
            ->paginate($porPagina)
            ->withQueryString();

        return view('equipos.auditoria', [
            'registros' => $registros,
            'busqueda' => $busqueda,
            'accion' => $accion,
            'porPagina' => $porPagina,
            'etiquetas' => EquipoAuditoria::ETIQUETAS,
        ]);
    }

    /**
     * Formulario de alta.
     */
    public function create(): View
    {
        $this->authorize('create', Equipo::class);

        return view('equipos.create');
    }

    /**
     * Guarda un equipo nuevo. La validación la hace StoreEquipoRequest.
     */
    public function store(StoreEquipoRequest $request): RedirectResponse
    {
        $this->authorize('create', Equipo::class);

        Equipo::create($request->validated());

        return redirect()
            ->route('equipos.index')
            ->with('estado', 'Equipo registrado correctamente.');
    }

    /**
     * Ficha de un equipo.
     */
    public function show(Equipo $equipo): View
    {
        $this->authorize('view', $equipo);

        return view('equipos.show', compact('equipo'));
    }

    /**
     * Formulario de edición.
     */
    public function edit(Equipo $equipo): View
    {
        $this->authorize('update', $equipo);

        return view('equipos.edit', compact('equipo'));
    }

    /**
     * Actualiza un equipo. La validación la hace UpdateEquipoRequest.
     */
    public function update(UpdateEquipoRequest $request, Equipo $equipo): RedirectResponse
    {
        $this->authorize('update', $equipo);

        $equipo->update($request->validated());

        return redirect()
            ->route('equipos.index')
            ->with('estado', 'Equipo actualizado correctamente.');
    }

    /**
     * Da de baja un equipo (eliminación lógica).
     *
     * Quién borra y con qué motivo los graba el trait RegistersUserEvents en la
     * fila del equipo. Acá el motivo se valida y se copia a la bitácora, que es
     * lo propio de este módulo: un equipo no se da de baja sin dejar rastro.
     */
    public function destroy(Request $request, Equipo $equipo): RedirectResponse
    {
        $this->authorize('delete', $equipo);

        $request->validate([
            'deleteObservacion' => ['required', 'string', 'min:5', 'max:500'],
        ], [], ['deleteObservacion' => 'motivo']);

        DB::transaction(function () use ($equipo): void {
            $equipo->delete();

            EquipoAuditoria::registrar($equipo, EquipoAuditoria::ACCION_ELIMINAR, [
                'motivo' => $equipo->deleteObservacion,
            ]);
        });

        return redirect()
            ->route('equipos.index')
            ->with('estado', "Equipo «{$equipo->nombre}» eliminado. Queda registrado en la bitácora.");
    }

    /**
     * Se conecta al equipo real vía el microservicio y actualiza su estado
     * (en línea, algoritmo detectado, última conexión).
     */
    public function probarConexion(Equipo $equipo, DeviceService $deviceService): RedirectResponse
    {
        $this->authorize('update', $equipo);

        try {
            $info = $deviceService->info($equipo);

            $equipo->update([
                'en_linea' => true,
                'algoritmo' => $info['algoritmo'] ?? $equipo->algoritmo,
                'ultima_sync' => now(),
            ]);

            return back()->with('estado', "Conectado a «{$equipo->nombre}». Algoritmo: ".($info['algoritmo'] ?? 'N/D'));
        } catch (DeviceServiceException $e) {
            $equipo->update(['en_linea' => false]);

            return back()->with('error', "No se pudo conectar: {$e->getMessage()}");
        }
    }

    /**
     * Descarga el historial de marcaciones del equipo en CSV, opcionalmente
     * acotado a un rango de fechas (parámetros `desde`/`hasta`). Se lee en vivo
     * del equipo vía el microservicio; nunca se muestra en pantalla (el
     * historial es grande y renderizarlo es lento), solo se baja el archivo.
     */
    public function exportarMarcaciones(Request $request, Equipo $equipo, SincronizadorEquipos $sincronizador): Response|RedirectResponse
    {
        $this->authorize('view', $equipo);

        $desde = (string) $request->query('desde', '');
        $hasta = (string) $request->query('hasta', '');

        [$todas, $error] = $sincronizador->marcaciones($equipo, $desde, $hasta);

        if ($error) {
            EquipoAuditoria::registrar($equipo, EquipoAuditoria::ACCION_EXPORTAR, [
                'desde' => $desde ?: null,
                'hasta' => $hasta ?: null,
                'detalle' => $error,
                'exito' => false,
            ]);

            return back()->with('error', $error);
        }

        EquipoAuditoria::registrar($equipo, EquipoAuditoria::ACCION_EXPORTAR, [
            'desde' => $desde ?: null,
            'hasta' => $hasta ?: null,
            'total_marcaciones' => count($todas),
        ]);

        $csv = "\u{FEFF}CI/ID,Nombre,Fecha,Hora\n";

        foreach ($todas as $marcacion) {
            $fecha = $marcacion['timestamp'] ? Carbon::parse($marcacion['timestamp']) : null;
            $nombre = filled($marcacion['nombre'] ?? null) ? $marcacion['nombre'] : 'Sin nombre';

            $csv .= implode(',', [
                $marcacion['user_id'],
                '"'.str_replace('"', '""', $nombre).'"',
                $fecha?->format('d/m/Y') ?? '',
                $fecha?->format('H:i:s') ?? '',
            ])."\n";
        }

        $archivo = 'marcaciones-'.Str::slug($equipo->nombre).'-'.now()->format('Y-m-d').'.csv';

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$archivo}\"",
        ]);
    }

    /**
     * Lee **todo** el historial del equipo y registra en la tabla local
     * `asistencias` (MySQL) lo que falte, sin pasar por descargar/reimportar el
     * CSV.
     *
     * No toma rango: el reloj vuelca su buffer entero de todos modos, así que
     * acotarlo solo servía para descartar después. Se guarda lo que no esté ya
     * —la terna `(ci, fecha, hora)` evita duplicar— incluso si el carnet no
     * está en el padrón, y se descarta únicamente la fecha basura del reloj.
     */
    public function sincronizarMarcaciones(Equipo $equipo, SincronizadorEquipos $sincronizador): RedirectResponse
    {
        $this->authorize('sync', $equipo);

        // Sin rango: se baja el buffer completo del reloj y se guarda lo que
        // falte. Ver App\Services\SincronizadorEquipos.
        $resultado = $sincronizador->sincronizar($equipo);

        if (! $resultado['exito']) {
            return back()->with('error', $resultado['mensaje']);
        }

        // La lectura se cortó: lo que llegó se guardó igual, pero el reloj tenía
        // más. Se avisa como error para que nadie dé por cerrada la corrida.
        if ($resultado['completa'] === false) {
            return back()->with('error', $resultado['mensaje']);
        }

        return back()->with('estado', $resultado['mensaje']);
    }

    /**
     * Vacía el buffer de marcaciones del equipo.
     *
     * El protocolo ZK solo permite borrar TODO el historial del reloj: no hay
     * borrado por rango. Es irreversible, por eso tiene su propio permiso
     * (`Clear:Equipo`) y la vista exige confirmación escrita antes de enviar.
     *
     * Solo se borran las marcaciones: usuarios y huellas quedan intactos, y lo
     * que ya se sincronizó a la tabla local `asistencias` tampoco se toca.
     */
    public function limpiarMarcaciones(Request $request, Equipo $equipo, DeviceService $deviceService): RedirectResponse
    {
        $this->authorize('clear', $equipo);

        $validado = $request->validate([
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        try {
            $deviceService->clearAttendance($equipo);
        } catch (DeviceServiceException $e) {
            EquipoAuditoria::registrar($equipo, EquipoAuditoria::ACCION_LIMPIAR, [
                'motivo' => $validado['motivo'],
                'detalle' => $e->getMessage(),
                'exito' => false,
            ]);

            return back()->with('error', "No se pudo limpiar: {$e->getMessage()}");
        }

        EquipoAuditoria::registrar($equipo, EquipoAuditoria::ACCION_LIMPIAR, [
            'motivo' => $validado['motivo'],
        ]);

        return back()->with('estado', "Se borraron las marcaciones de «{$equipo->nombre}». El equipo quedó con el historial vacío y queda registrado en la bitácora.");
    }
}
