<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTurnoRequest;
use App\Models\AsignacionTurno;
use App\Models\Horario;
use App\Models\Turno;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Turnos: la jornada semanal que agrupa horarios.
 *
 * Sin edición: un turno se crea o se elimina (ver {@see Turno}). Lo único que
 * cambia después es la marca de sugerido.
 */
class TurnoController extends Controller
{
    /**
     * Listado de turnos.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Turno::class);

        $buscar = trim((string) $request->query('buscar', ''));
        $sugerido = $this->filtroSugerido($request);
        $porPagina = $this->porPagina($request);

        return view('turnos.index', compact('buscar', 'sugerido', 'porPagina'));
    }

    /**
     * Devuelve el listado para AJAX.
     */
    public function list(Request $request): View
    {
        $this->authorize('viewAny', Turno::class);

        $buscar = trim((string) $request->query('q', ''));
        $sugerido = $this->filtroSugerido($request);
        $porPagina = $this->porPagina($request);

        $turnos = Turno::query()
            ->with('horarios')
            ->when($buscar !== '', fn (Builder $query) => $query->where('nombre', 'like', "%{$buscar}%"))
            ->when($sugerido !== '', fn (Builder $query) => $query->where('sugerido', $sugerido === '1'))
            ->orderByDesc('sugerido')
            ->orderBy('nombre')
            ->paginate($porPagina)
            ->withQueryString();

        return view('turnos.list', compact('turnos'));
    }

    /**
     * Ficha de solo lectura del turno, con sus horarios.
     */
    public function show(Turno $turno): View
    {
        $this->authorize('view', $turno);

        $turno->load('horarios');

        return view('turnos.show', compact('turno'));
    }

    /**
     * Formulario de alta.
     */
    public function create(): View
    {
        $this->authorize('create', Turno::class);

        $horarios = Horario::query()->ordenado()->get();

        return view('turnos.create', compact('horarios'));
    }

    /**
     * Guarda el turno con sus horarios.
     */
    public function store(StoreTurnoRequest $request): RedirectResponse
    {
        $this->authorize('create', Turno::class);

        $datos = $request->validated();

        $turno = DB::transaction(function () use ($datos): Turno {
            $turno = Turno::create([
                'nombre' => trim($datos['nombre']),
                'sugerido' => $datos['sugerido'] ?? false,
                'observacion' => $datos['observacion'] ?? null,
            ]);

            $turno->horarios()->attach($datos['horarioIds']);

            return $turno;
        });

        return redirect()
            ->route('turnos.show', $turno)
            ->with('estado', 'Turno registrado correctamente.');
    }

    /**
     * Marca o desmarca el turno como sugerido. No toca sus horarios.
     */
    public function sugerido(Turno $turno): RedirectResponse
    {
        $this->authorize('update', $turno);

        $turno->sugerido = ! $turno->sugerido;
        $turno->save();

        return back()->with('estado', $turno->sugerido
            ? 'El turno quedó como sugerido.'
            : 'El turno ya no es sugerido.');
    }

    /**
     * Elimina el turno (eliminación lógica). Sus horarios quedan vinculados, así
     * que lo procesado con él sigue dando lo mismo.
     */
    public function destroy(Turno $turno): RedirectResponse
    {
        $this->authorize('delete', $turno);

        // Con gente que lo tiene hoy o lo va a tener, eliminarlo dejaría esas
        // asignaciones colgando de un turno que ya no se ofrece.
        $enUso = AsignacionTurno::query()
            ->where('turno_id', $turno->id)
            ->where('hasta', '>=', today())
            ->count();

        if ($enUso > 0) {
            return redirect()
                ->route('turnos.index')
                ->with('error', "No se puede eliminar el turno «{$turno->nombre}»: tiene {$enUso} asignación(es) vigente(s) o futura(s). Concluilas primero.");
        }

        $turno->delete();

        return redirect()
            ->route('turnos.index')
            ->with('estado', 'Turno eliminado.');
    }

    /**
     * Valor del filtro «turno sugerido»: «1», «0» o cadena vacía (sin filtrar).
     */
    private function filtroSugerido(Request $request): string
    {
        $valor = (string) $request->query('sugerido', '');

        return in_array($valor, ['0', '1'], true) ? $valor : '';
    }
}
