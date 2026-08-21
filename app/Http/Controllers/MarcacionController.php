<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportarMarcacionesRequest;
use App\Http\Requests\StoreMarcacionRequest;
use App\Models\Asistencia;
use App\Services\RegistroAsistencia;
use App\Services\ResolutorNombres;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Listado de las marcaciones desde la base local (MySQL, tabla `asistencias`,
 * migrada del SIA) y la importación del CSV que exporta EquipoController.
 *
 * La tabla tiene ~4.4 millones de filas, por eso el rango arranca en el mes
 * actual: nunca se lista ni se cuenta la tabla completa.
 *
 * Tanto el listado como el import y el alta manual (y la sincronización de
 * equipos) trabajan ya sobre la base local MySQL vía
 * App\Services\RegistroAsistencia.
 */
class MarcacionController extends Controller
{
    /**
     * Listado paginado de marcaciones, filtrado por rango de fechas.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Asistencia::class);

        // Por defecto: del 1.º del mes hasta hoy (deja fuera las fechas basura
        // futuras que arrastra el SIA, ej. años 2064/2103).
        $desde = $request->query('desde', now()->startOfMonth()->toDateString());
        $hasta = $request->query('hasta', now()->toDateString());
        $tipo = $request->query('tipo', '');
        $porPagina = $this->porPagina($request, 10);

        return view('marcaciones.index', compact('desde', 'hasta', 'tipo', 'porPagina'));
    }

    /**
     * Devuelve el listado para AJAX.
     */
    public function list(Request $request, ResolutorNombres $resolutor): View
    {
        $this->authorize('viewAny', Asistencia::class);

        $desde = $request->query('desde', now()->startOfMonth()->toDateString());
        $hasta = $request->query('hasta', now()->toDateString());
        $buscar = trim((string) $request->query('q', ''));
        $tipo = $request->query('tipo', '');
        $porPagina = $this->porPagina($request, 10);

        $marcaciones = Asistencia::query()
            ->enRango($desde, $hasta)
            ->when($buscar !== '', fn (Builder $query) => $query->buscar($buscar))
            ->when($tipo !== '', fn (Builder $query) => $query->where('tipo', $tipo))
            // Ordena por el `id` de la tabla, del más alto al más bajo.
            //
            // El id no es cronológico: las filas entraron con el orden en que
            // el cursor de la migración las trajo del SIA, que es el de la
            // clave del origen (IdPersona, Fecha, Hora). Como el `ci` es texto,
            // cada funcionario ocupa un bloque de ids contiguos con su
            // historial adentro, así que el listado agrupa por persona y las
            // fechas no bajan en orden.
            //
            // Alcanza solo: el id es único, así que el orden es total y estable
            // entre páginas sin necesidad de desempatar por fecha ni hora.
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

    /**
     * Importa a la tabla local `asistencias` el CSV que ya genera
     * EquipoController::exportarMarcaciones() (columnas CI/ID, Nombre,
     * Fecha, Hora). El CI se cruza contra `personas.ci`; lo que no matchea un
     * funcionario o ya existe (mismo ci+fecha+hora) se cuenta pero no se inserta.
     */
    public function importar(ImportarMarcacionesRequest $request, RegistroAsistencia $registro): RedirectResponse
    {
        $this->authorize('create', Asistencia::class);

        $ruta = $request->file('archivo')->getRealPath();
        $separador = $this->detectarSeparador($ruta);
        $manejador = fopen($ruta, 'r');

        $filas = [];
        $esPrimeraFila = true;

        while (($columnas = fgetcsv($manejador, 0, $separador)) !== false) {
            // Salta las líneas en blanco que suele dejar Excel al final.
            if (count(array_filter($columnas, fn ($celda): bool => trim((string) $celda) !== '')) === 0) {
                continue;
            }

            [$ci, , $fechaCsv, $horaCsv] = array_pad($columnas, 4, null);

            $fecha = $this->parsearFecha(trim((string) $fechaCsv));
            $hora = $this->parsearHora(trim((string) $horaCsv));

            // La primera fila que no parsea como fecha/hora es el encabezado: se
            // descarta sin contarla. El resto de filas ilegibles van como
            // inválidas (momento nulo) para que el servicio las cuente.
            if ((! $fecha || ! $hora) && $esPrimeraFila) {
                $esPrimeraFila = false;

                continue;
            }

            $esPrimeraFila = false;

            $filas[] = [
                'ci' => $ci,
                'momento' => $fecha && $hora ? $fecha->copy()->setTime($hora->hour, $hora->minute, $hora->second) : null,
            ];
        }

        fclose($manejador);

        $conteo = $registro->registrar($filas);

        return back()->with('estado', $registro->mensaje($conteo));
    }

    /**
     * Detecta el separador del CSV. Excel en español guarda con ';', mientras
     * que el que exporta el sistema usa ','. Se decide por el que más aparece
     * en la primera línea.
     */
    private function detectarSeparador(string $ruta): string
    {
        $manejador = fopen($ruta, 'r');
        $primeraLinea = (string) fgets($manejador);
        fclose($manejador);

        return substr_count($primeraLinea, ';') > substr_count($primeraLinea, ',') ? ';' : ',';
    }

    /**
     * Parsea la fecha probando los formatos que puede dejar el export propio
     * (d/m/Y) o un reguardado desde Excel (d-m-Y, ISO). Devuelve la fecha a
     * medianoche, o null si ninguno encaja.
     */
    private function parsearFecha(string $valor): ?Carbon
    {
        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d'] as $formato) {
            try {
                return Carbon::createFromFormat('!'.$formato, $valor);
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * Parsea la hora con o sin segundos. Devuelve null si no encaja.
     */
    private function parsearHora(string $valor): ?Carbon
    {
        foreach (['H:i:s', 'H:i'] as $formato) {
            try {
                return Carbon::createFromFormat('!'.$formato, $valor);
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }
}
