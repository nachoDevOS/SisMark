<?php

namespace App\Http\Controllers;

use App\Models\Horario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Consulta de los horarios: los de cada turno y los heredados del SIA.
 *
 * Es de solo lectura. Los horarios se cargan al crear el turno, que es su
 * dueño, y no se editan: cambiarle la hora a uno ya asignado reescribiría los
 * reportes pasados de todos los que lo tuvieron.
 */
class HorarioController extends Controller
{
    /**
     * Listado de horarios, ordenados por día y nombre.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Horario::class);

        $buscar = trim((string) $request->query('buscar', ''));
        $dia = (string) $request->query('dia', '');
        $porPagina = $this->porPagina($request);

        return view('horarios.index', compact('buscar', 'dia', 'porPagina'));
    }

    /**
     * Devuelve el listado para AJAX.
     */
    public function list(Request $request): View
    {
        $this->authorize('viewAny', Horario::class);

        $buscar = trim((string) $request->query('q', ''));
        $dia = (string) $request->query('dia', '');
        $porPagina = $this->porPagina($request);

        $horarios = Horario::query()
            ->with('turno')
            ->when($buscar !== '', fn (Builder $query) => $query->where('nombreHorario', 'like', "%{$buscar}%"))
            ->when($dia !== '', fn (Builder $query) => $query->where('dia', $dia))
            ->ordenado()
            ->paginate($porPagina)
            ->withQueryString();

        return view('horarios.list', compact('horarios'));
    }

    /**
     * Ficha de solo lectura de un horario.
     */
    public function show(Horario $horario): View
    {
        $this->authorize('view', $horario);

        $horario->load('turno');

        return view('horarios.show', compact('horario'));
    }
}
