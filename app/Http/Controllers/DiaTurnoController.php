<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDiaTurnoRequest;
use App\Http\Requests\UpdateDiaTurnoRequest;
use App\Models\Turno;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * CRUD clásico (MVC) de los horarios (turnos): el «Administrador de horarios».
 * Trabaja sobre la tabla local MySQL `turnos` (migrada de DiaTurnos del SIA).
 */
class DiaTurnoController extends Controller
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
        $this->authorize('viewAny', Turno::class);

        $buscar = trim((string) $request->query('buscar', ''));
        $dia = (string) $request->query('dia', '');
        $sugerido = $this->filtroSugerido($request);
        $porPagina = $this->porPagina($request);

        return view('horarios.index', compact('buscar', 'dia', 'sugerido', 'porPagina'));
    }

    /**
     * Devuelve el listado para AJAX.
     */
    public function list(Request $request): View
    {
        $this->authorize('viewAny', Turno::class);

        $buscar = trim((string) $request->query('q', ''));
        $dia = (string) $request->query('dia', '');
        $sugerido = $this->filtroSugerido($request);
        $porPagina = $this->porPagina($request);

        $horarios = Turno::query()
            ->when($buscar !== '', fn (Builder $query) => $query->where('nombreTurno', 'like', "%{$buscar}%"))
            ->when($dia !== '', fn (Builder $query) => $query->where('dia', $dia))
            ->when($sugerido !== '', fn (Builder $query) => $query->where('sugerido', $sugerido === '1'))
            // Los sugeridos arriba: son los pocos que se consultan seguido entre
            // los cientos que arrastra el SIA.
            ->orderByDesc('sugerido')
            ->ordenado()
            ->paginate($porPagina)
            ->withQueryString();

        return view('horarios.list', compact('horarios'));
    }

    /**
     * Ficha de solo lectura de un horario.
     */
    public function show(Turno $horario): View
    {
        $this->authorize('view', $horario);

        return view('horarios.show', compact('horario'));
    }

    /**
     * Formulario de alta.
     */
    public function create(): View
    {
        $this->authorize('create', Turno::class);

        return view('horarios.create');
    }

    /**
     * Guarda un horario nuevo. El código del turno (idTurno, char(3)) se genera
     * automático como en el sistema de escritorio: el formulario no lo pide.
     */
    public function store(StoreDiaTurnoRequest $request): RedirectResponse
    {
        $this->authorize('create', Turno::class);

        $horario = new Turno;
        $horario->idTurno = $this->generarCodigo();
        $this->asignarDatos($horario, $request->validated());
        $horario->save();

        return redirect()
            ->route('horarios.index')
            ->with('estado', 'Turno registrado correctamente.');
    }

    /**
     * Formulario de edición.
     */
    public function edit(Turno $horario): View
    {
        $this->authorize('update', $horario);

        return view('horarios.edit', compact('horario'));
    }

    /**
     * Actualiza un horario. El idTurno (código) no se toca.
     */
    public function update(UpdateDiaTurnoRequest $request, Turno $horario): RedirectResponse
    {
        $this->authorize('update', $horario);

        $this->asignarDatos($horario, $request->validated());
        $horario->save();

        return redirect()
            ->route('horarios.index')
            ->with('estado', 'Turno actualizado correctamente.');
    }

    /**
     * Elimina un horario (eliminación lógica: SoftDeletes en el modelo Turno).
     */
    public function destroy(Turno $horario): RedirectResponse
    {
        $this->authorize('delete', $horario);

        try {
            $horario->delete();
        } catch (QueryException) {
            // La tabla `turnos` está referenciada por licencias y asignaciones
            // (FK turno_id). Si el turno está en uso, la base rechaza el borrado:
            // se avisa en vez de reventar con un error 500.
            return redirect()
                ->route('horarios.index')
                ->with('error', 'No se puede eliminar el turno: está en uso por licencias o asignaciones.');
        }

        return redirect()
            ->route('horarios.index')
            ->with('estado', 'Turno eliminado.');
    }

    /**
     * Valor del filtro «horario sugerido»: «1», «0» o cadena vacía (sin filtrar).
     *
     * Solo se aceptan esos tres: cualquier otra cosa que llegue por la URL se
     * trata como «sin filtrar», así un valor manipulado no cambia el listado por
     * un camino no previsto.
     */
    private function filtroSugerido(Request $request): string
    {
        $valor = (string) $request->query('sugerido', '');

        return in_array($valor, ['0', '1'], true) ? $valor : '';
    }

    /**
     * Copia los datos validados al modelo, convirtiendo cada "HH:MM" del
     * formulario a datetime sobre la fecha base 1899-12-30 (patrón del SIA).
     *
     * @param  array<string, mixed>  $datos
     */
    private function asignarDatos(Turno $horario, array $datos): void
    {
        $horario->dia = $datos['Dia'];
        $horario->nombreTurno = $datos['NombreTurno'];

        foreach (self::CAMPOS_HORA as $campoForm => $campoModelo) {
            $horario->{$campoModelo} = Carbon::createFromFormat('Y-m-d H:i', '1899-12-30 '.$datos[$campoForm]);
        }

        $horario->hTrabajadas = $datos['HTrabajadas'];
        $horario->siguienteDia = $datos['SiguienteDia'] ?? false;
        $horario->sugerido = $datos['Sugerido'] ?? false;
    }

    /**
     * Genera un código de turno único de 3 caracteres [A-Z0-9], como los que
     * ya usa la tabla (ej. 011, 0A4, 0ZX).
     */
    private function generarCodigo(): string
    {
        do {
            $codigo = Str::upper(Str::random(3));
        } while (Turno::query()->where('idTurno', $codigo)->exists());

        return $codigo;
    }
}
