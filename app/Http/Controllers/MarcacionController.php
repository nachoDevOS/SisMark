<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportarMarcacionesRequest;
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
 * Tanto el listado como el import (y la sincronización de equipos) trabajan ya
 * sobre la base local MySQL vía App\Services\RegistroAsistencia.
 *
 * El alta manual de una marcación está DESACTIVADA desde el 2026-08-13: el
 * código quedó comentado más abajo, no borrado.
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
            ->orderByDesc('fecha')
            ->orderByDesc('hora')
            ->paginate($porPagina)
            ->withQueryString();

        // La columna «Funcionario» (nombre y cargo) sale de Mamoré y, si el CI no
        // está ahí, de la base local (App\Services\ResolutorNombres).
        $fichas = $resolutor->fichasPorCi($marcaciones->pluck('ci'));

        return view('marcaciones.list', compact('marcaciones', 'fichas'));
    }

    // -------------------------------------------------------------------------
    // DESACTIVADO (2026-08-13): alta manual de una marcación (tipo M).
    //
    // Queda comentado y no borrado para poder reponerlo tal cual estaba. Lo
    // acompañan, también comentados: la ruta `marcaciones.store` en
    // routes/web.php, StoreMarcacionRequest, el componente <x-modal-marcacion />
    // con sus dos usos, y las pruebas del módulo.
    //
    // Al reponerlo hay que volver a importar App\Http\Requests\StoreMarcacionRequest.
    // -------------------------------------------------------------------------
    //
    // /**
    //  * Registra una marcación manual (tipo M) sobre la base local. La hora se
    //  * guarda sobre la fecha base 1899-12-30, como el resto de las marcaciones.
    //  */
    // public function store(StoreMarcacionRequest $request): RedirectResponse
    // {
    //     $this->authorize('create', Asistencia::class);
    //
    //     $ci = $request->validated('ci');
    //     $fecha = Carbon::parse($request->validated('fecha'))->startOfDay();
    //     $hora = Carbon::parse($request->validated('hora'))->format('H:i:s');
    //
    //     // `fecha` se compara entera y no con `whereDate()`: siempre está guardada
    //     // a medianoche, y así la búsqueda cae sobre el índice único (ci, fecha,
    //     // hora) en vez de recorrer todas las marcaciones de esa cédula. `hora` sí
    //     // va por `whereTime()`: hay filas viejas del SIA con una fecha base
    //     // distinta de 1899-12-30, y compararla entera las dejaría pasar como si
    //     // no existieran.
    //     $yaExiste = Asistencia::query()
    //         ->where('ci', $ci)
    //         ->where('fecha', $fecha)
    //         ->whereTime('hora', $hora)
    //         ->exists();
    //
    //     if ($yaExiste) {
    //         return back()->with('error', 'Ya existe una marcación para ese funcionario en esa fecha y hora.');
    //     }
    //
    //     Asistencia::create([
    //         'ci' => $ci,
    //         'fecha' => $fecha,
    //         'hora' => '1899-12-30 '.$hora,
    //         'tipo' => Asistencia::TIPO_MANUAL,
    //         'observacion' => $request->validated('observacion'),
    //     ]);
    //
    //     return redirect($this->destino($request, $ci))
    //         ->with('estado', 'Marcación manual registrada correctamente.');
    // }
    //
    // /**
    //  * A dónde volver después de registrar: a la ficha desde la que se abrió el
    //  * modal (`local` o `mamore`) o, si se registró desde el listado, al
    //  * listado. Solo se aceptan esos dos orígenes conocidos, así un valor
    //  * manipulado nunca redirige fuera del sitio.
    //  */
    // private function destino(Request $request, string $ci): string
    // {
    //     return match ((string) $request->input('origen', '')) {
    //         'local' => route('funcionarios.show', ['persona' => $ci]),
    //         'mamore' => route('funcionarios.mamore', ['ci' => $ci]),
    //         default => route('marcaciones.index'),
    //     };
    // }

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
