<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreHorarioRequest;
use App\Http\Requests\UpdateHorarioRequest;
use App\Models\Horario;
use App\Models\Turno;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * CRUD clásico (MVC) de los horarios: el «Administrador de horarios».
 * Trabaja sobre la tabla local MySQL `horarios` (migrada de DiaTurnos del SIA).
 */
class HorarioController extends Controller
{
    /**
     * Campos hora del formulario (clave del request, PascalCase) → atributo del
     * modelo local (camelCase). Cada valor "HH:MM" del form se guarda como
     * datetime sobre la fecha base 1899-12-30.
     *
     * @var array<string, string>
     */
    private const CAMPOS_HORA = [
        'HEntrada' => 'hEntrada',
        'HTolerancia' => 'hTolerancia',
        'EMinima' => 'eMinima',
        'EMaxima' => 'eMaxima',
        'HSalida' => 'hSalida',
        'STolerancia' => 'sTolerancia',
        'SMinima' => 'sMinima',
        'SMaxima' => 'sMaxima',
    ];

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

        return view('horarios.show', compact('horario'));
    }

    /**
     * Formulario de alta.
     */
    public function create(): View
    {
        $this->authorize('create', Horario::class);

        return view('horarios.create');
    }

    /**
     * Guarda un horario nuevo. El código del horario (idHorario, char(3)) se genera
     * automático como en el sistema de escritorio: el formulario no lo pide.
     */
    public function store(StoreHorarioRequest $request): RedirectResponse
    {
        $this->authorize('create', Horario::class);

        $horario = new Horario;
        $horario->idHorario = $this->generarCodigo();
        $this->asignarDatos($horario, $request->validated());
        $horario->save();

        return redirect()
            ->route('horarios.index')
            ->with('estado', 'Horario registrado correctamente.');
    }

    /**
     * Formulario de edición.
     */
    public function edit(Horario $horario): View
    {
        $this->authorize('update', $horario);

        return view('horarios.edit', compact('horario'));
    }

    /**
     * Actualiza un horario. El idHorario (código) no se toca.
     */
    public function update(UpdateHorarioRequest $request, Horario $horario): RedirectResponse
    {
        $this->authorize('update', $horario);

        $this->asignarDatos($horario, $request->validated());
        $horario->save();

        return redirect()
            ->route('horarios.index')
            ->with('estado', 'Horario actualizado correctamente.');
    }

    /**
     * Elimina un horario (eliminación lógica: SoftDeletes en el modelo Horario).
     */
    public function destroy(Horario $horario): RedirectResponse
    {
        $this->authorize('delete', $horario);

        // Un horario dado de baja no se procesa: si está dentro de un turno, ese
        // día dejaría de controlarse para todos los que lo tienen, sin aviso. Se
        // cuentan también los turnos eliminados, porque sus asignaciones pasadas
        // siguen explicando los reportes de esas fechas.
        $turnos = Turno::withTrashed()
            ->whereIn('id', DB::table('horario_turno')->where('horario_id', $horario->id)->select('turno_id'))
            ->orderBy('nombre')
            ->pluck('nombre');

        if ($turnos->isNotEmpty()) {
            return redirect()
                ->route('horarios.index')
                ->with('error', 'No se puede eliminar el horario: está en uso por el turno «'.$turnos->implode('», «').'».');
        }

        try {
            $horario->delete();
        } catch (QueryException) {
            // La tabla `horarios` está referenciada por licencias y asignaciones
            // (FK horario_id). Si el horario está en uso, la base rechaza el borrado:
            // se avisa en vez de reventar con un error 500.
            return redirect()
                ->route('horarios.index')
                ->with('error', 'No se puede eliminar el horario: está en uso por licencias o asignaciones.');
        }

        return redirect()
            ->route('horarios.index')
            ->with('estado', 'Horario eliminado.');
    }

    /**
     * Copia los datos validados al modelo, convirtiendo cada "HH:MM" del
     * formulario a datetime sobre la fecha base 1899-12-30 (patrón del SIA).
     *
     * @param  array<string, mixed>  $datos
     */
    private function asignarDatos(Horario $horario, array $datos): void
    {
        $horario->dia = $datos['Dia'];
        $horario->nombreHorario = $datos['nombreHorario'];

        foreach (self::CAMPOS_HORA as $campoForm => $campoModelo) {
            $horario->{$campoModelo} = Carbon::createFromFormat('Y-m-d H:i', '1899-12-30 '.$datos[$campoForm]);
        }

        $horario->hTrabajadas = $datos['HTrabajadas'];
        $horario->siguienteDia = $datos['SiguienteDia'] ?? false;
    }

    /**
     * Genera un código de horario único de 3 caracteres [A-Z0-9], como los que
     * ya usa la tabla (ej. 011, 0A4, 0ZX).
     */
    private function generarCodigo(): string
    {
        do {
            $codigo = Str::upper(Str::random(3));
        } while (Horario::query()->where('idHorario', $codigo)->exists());

        return $codigo;
    }
}
