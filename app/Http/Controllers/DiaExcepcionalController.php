<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDiaExcepcionalRequest;
use App\Http\Requests\UpdateDiaExcepcionalRequest;
use App\Models\DiaExcepcional;
use App\Services\RespaldoDocumento;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * CRUD clásico (MVC) de los días excepcionales (feriados/tolerancias que no
 * controlan asistencia), sobre la base local MySQL. Eliminación lógica.
 */
class DiaExcepcionalController extends Controller
{
    /**
     * Listado paginado, de la fecha más reciente a la más antigua, con búsqueda
     * por motivo o por fecha (año, `YYYY-MM-DD` o `dd/mm/YYYY`).
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', DiaExcepcional::class);

        $busqueda = trim((string) $request->query('q', ''));
        $porPagina = $this->porPagina($request);
        $estado = $this->estado($request);

        // Por defecto, el mes en curso completo: del 1.º al último día, porque
        // los feriados se cargan por adelantado y los de fin de mes ya cuentan.
        $desde = (string) $request->query('desde', now()->startOfMonth()->toDateString());
        $hasta = (string) $request->query('hasta', now()->endOfMonth()->toDateString());

        return view('dias-excepcionales.index', compact('busqueda', 'porPagina', 'estado', 'desde', 'hasta'));
    }

    /**
     * Devuelve el listado para AJAX, con todas las filas del calendario (con
     * motivo o sin él), filtrado por estado y por rango de fechas. Sin `desde`
     * o sin `hasta`, ese extremo queda abierto.
     */
    public function list(Request $request): View
    {
        $this->authorize('viewAny', DiaExcepcional::class);

        $busqueda = trim((string) $request->query('q', ''));
        $porPagina = $this->porPagina($request);
        $estado = $this->estado($request);
        $desde = trim((string) $request->query('desde', ''));
        $hasta = trim((string) $request->query('hasta', ''));

        $diasExcepcionales = DiaExcepcional::query()
            ->when($desde !== '', fn (Builder $query) => $query->where('fecha', '>=', $desde))
            ->when($hasta !== '', fn (Builder $query) => $query->where('fecha', '<=', $hasta))
            ->when($estado === 'activos', fn (Builder $query) => $query->where('estado', DiaExcepcional::ACTIVO))
            ->when($estado === 'inactivos', fn (Builder $query) => $query->where('estado', '!=', DiaExcepcional::ACTIVO))
            ->when($busqueda !== '', fn (Builder $query) => $this->filtrarBusqueda($query, $busqueda))
            ->orderByDesc('fecha')
            ->paginate($porPagina)
            ->withQueryString();

        return view('dias-excepcionales.list', compact('diasExcepcionales', 'busqueda'));
    }

    /**
     * Aplica la búsqueda: siempre por motivo (texto parcial) y, si el término
     * parece una fecha, también por la columna `fecha`.
     */
    private function filtrarBusqueda(Builder $query, string $busqueda): Builder
    {
        return $query->where(function (Builder $query) use ($busqueda): void {
            $query->where('motivoInasistencia', 'like', "%{$busqueda}%");

            if (preg_match('/^\d{4}$/', $busqueda)) {
                $query->orWhereYear('fecha', $busqueda);
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $busqueda)) {
                $query->orWhereDate('fecha', $busqueda);
            } elseif (preg_match('#^\d{1,2}/\d{1,2}/\d{4}$#', $busqueda)) {
                $query->orWhereDate('fecha', Carbon::createFromFormat('d/m/Y', $busqueda)->toDateString());
            }
        });
    }

    /**
     * Formulario de alta.
     */
    public function create(): View
    {
        $this->authorize('create', DiaExcepcional::class);

        return view('dias-excepcionales.create');
    }

    /**
     * Guarda un día excepcional nuevo. La validación la hace el Request.
     */
    public function store(StoreDiaExcepcionalRequest $request, RespaldoDocumento $respaldos): RedirectResponse
    {
        $this->authorize('create', DiaExcepcional::class);

        $datos = $request->safe()->except('respaldo');

        if ($request->hasFile('respaldo')) {
            [$datos['adjunto'], $datos['adjuntoNombre']] = $respaldos->guardar(
                $request->file('respaldo'),
                'dias-excepcionales',
            );
        }

        // La fecha ya tiene su fila vacía del calendario del SIA: se completa
        // esa en vez de sumar otra.
        $vacia = DiaExcepcional::query()
            ->whereDate('fecha', $datos['fecha'])
            ->whereNull('motivoInasistencia')
            ->first();

        $datos['estado'] = DiaExcepcional::ACTIVO;

        $vacia === null ? DiaExcepcional::create($datos) : $vacia->update($datos);

        return redirect()
            ->route('dias-excepcionales.index')
            ->with('estado', 'Día excepcional registrado correctamente.');
    }

    /**
     * Formulario de edición.
     */
    public function edit(DiaExcepcional $diaExcepcional): View
    {
        $this->authorize('update', $diaExcepcional);

        return view('dias-excepcionales.edit', ['diaExcepcional' => $diaExcepcional]);
    }

    /**
     * Actualiza un día excepcional. La validación la hace el Request.
     *
     * Un respaldo nuevo reemplaza al anterior y borra el viejo del bucket: acá,
     * a diferencia de las licencias, el archivo es de una sola fila, así que
     * nadie más queda apuntando a él. No mandar archivo deja el que ya estaba.
     */
    public function update(UpdateDiaExcepcionalRequest $request, DiaExcepcional $diaExcepcional, RespaldoDocumento $respaldos): RedirectResponse
    {
        $this->authorize('update', $diaExcepcional);

        $datos = $request->safe()->except('respaldo');
        $anterior = null;

        if ($request->hasFile('respaldo')) {
            $anterior = $diaExcepcional->adjunto;

            [$datos['adjunto'], $datos['adjuntoNombre']] = $respaldos->guardar(
                $request->file('respaldo'),
                'dias-excepcionales',
            );
        }

        $diaExcepcional->update($datos);

        // Recién después de guardar la fila nueva: si la escritura falla, el
        // archivo viejo sigue en pie y la fila lo sigue encontrando.
        $respaldos->borrar($anterior);

        return redirect()
            ->route('dias-excepcionales.index')
            ->with('estado', 'Día excepcional actualizado correctamente.');
    }

    /**
     * Da de baja o vuelve a dar de alta el día. Dado de baja sigue en el
     * listado pero no cuenta en los reportes: el día se procesa como uno común.
     */
    public function cambiarEstado(DiaExcepcional $diaExcepcional): RedirectResponse
    {
        $this->authorize('update', $diaExcepcional);

        $diaExcepcional->update([
            'estado' => $diaExcepcional->activo ? DiaExcepcional::INACTIVO : DiaExcepcional::ACTIVO,
        ]);

        $fecha = $diaExcepcional->fecha?->format('d/m/Y');

        return back()->with('estado', $diaExcepcional->activo
            ? "Día excepcional del {$fecha} activado: vuelve a contar en los reportes."
            : "Día excepcional del {$fecha} dado de baja: los reportes lo procesan como un día común.");
    }

    /**
     * Filtro de estado del listado: `todos`, `activos` o `inactivos`.
     */
    private function estado(Request $request): string
    {
        $estado = (string) $request->query('estado', 'todos');

        return in_array($estado, ['todos', 'activos', 'inactivos'], true) ? $estado : 'todos';
    }

    /**
     * Elimina (lógicamente) un día excepcional.
     *
     * El respaldo **no** se borra del bucket: la eliminación es lógica y la
     * fila se puede restaurar, con su documento incluido.
     */
    public function destroy(DiaExcepcional $diaExcepcional): RedirectResponse
    {
        $this->authorize('delete', $diaExcepcional);

        $diaExcepcional->delete();

        return redirect()
            ->route('dias-excepcionales.index')
            ->with('estado', 'Día excepcional eliminado.');
    }

    /**
     * Redirige al respaldo del día con un enlace temporal y firmado.
     *
     * El archivo no se sirve por acá ni se hace público: se comprueba el
     * permiso de lectura y recién ahí se pide al bucket un enlace de vida corta.
     */
    public function respaldo(DiaExcepcional $diaExcepcional, RespaldoDocumento $respaldos): RedirectResponse
    {
        $this->authorize('viewAny', DiaExcepcional::class);

        $enlace = $respaldos->enlace($diaExcepcional->adjunto);

        return $enlace === null
            ? back()->with('error', 'El día excepcional no tiene respaldo cargado, o el archivo ya no está disponible.')
            : redirect()->away($enlace);
    }
}
