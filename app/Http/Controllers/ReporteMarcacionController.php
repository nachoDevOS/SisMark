<?php

namespace App\Http\Controllers;

use App\Exceptions\MamoreException;
use App\Models\Asistencia;
use App\Models\Persona;
use App\Services\DirectorioMamore;
use App\Services\ExcelMarcacionesProcesadas;
use App\Services\ProcesadorAsistencia;
use App\Services\ResolutorNombres;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Reportes de marcaciones desde la base local (MySQL). Sigue el patrón de
 * selección + generación: primero se elige el funcionario y el rango, y después
 * se genera el reporte en pantalla, imprimible o en CSV según el botón usado.
 */
class ReporteMarcacionController extends Controller
{
    /**
     * Formulario de selección del reporte «marcaciones sin procesar»: busca un
     * funcionario por CI o nombre y muestra los candidatos para elegir uno.
     */
    public function sinProcesar(Request $request): View
    {
        $this->autorizarPermiso('ViewAny:Reporte');

        $desde = $request->query('desde', now()->startOfMonth()->toDateString());
        $hasta = $request->query('hasta', now()->toDateString());

        return view('reportes.marcaciones.sinProcesar.report', compact('desde', 'hasta'));
    }

    /**
     * Búsqueda de funcionarios por CI o nombre para el combo (select2) del
     * formulario. Devuelve hasta 20 coincidencias como JSON.
     *
     * Busca en Mamoré, que además del nombre trae el cargo y la dirección. Si la
     * API no está configurada o falla, cae a la base local (SIAT) para no dejar
     * el reporte inutilizable.
     */
    public function buscarFuncionarios(Request $request, DirectorioMamore $directorio): JsonResponse
    {
        $this->autorizarPermiso('ViewAny:Reporte');

        $q = trim((string) $request->query('q', ''));

        if ($q === '') {
            return response()->json([]);
        }

        if ($directorio->configurado()) {
            try {
                return response()->json($directorio->buscar($q)->map(fn (array $persona): array => [
                    'id' => $persona['ci'],
                    'texto' => $directorio->etiqueta($persona),
                    // La miniatura, que es la que pinta el combo; sin foto va
                    // null y la fila cae al ícono genérico.
                    'foto' => $persona['imageThumb'] ?? null,
                ])->values());
            } catch (MamoreException) {
                // Sigue con la base local.
            }
        }

        $funcionarios = Persona::query()->buscar($q)->orderBy('paterno')->limit(20)->get();

        return response()->json($funcionarios->map(function (Persona $persona): array {
            $ci = trim((string) $persona->ci);
            $pin = trim((string) $persona->pinReloj);
            $nombre = $persona->nombre_completo ?: 'Sin nombre';

            return [
                'id' => $ci,
                'texto' => $ci.' — '.$nombre.($pin !== '' ? " (PIN {$pin})" : ''),
                // SIAT no guarda fotos: solo las tiene Mamoré.
                'foto' => null,
            ];
        })->values());
    }

    /**
     * Formulario de selección del reporte «marcaciones procesadas»: mismo combo
     * de funcionario que el crudo, pero el resultado cruza las marcas contra el
     * turno asignado, los días excepcionales y las licencias.
     */
    public function procesado(Request $request): View
    {
        $this->autorizarPermiso('ViewAny:Reporte');

        $desde = $request->query('desde', now()->startOfMonth()->toDateString());
        $hasta = $request->query('hasta', now()->toDateString());

        return view('reportes.marcaciones.procesado.report', compact('desde', 'hasta'));
    }

    /**
     * Genera el reporte procesado del funcionario elegido. El destino depende
     * del parámetro `print`: 1 = imprimible, 2 = Excel, otro = lista en pantalla.
     */
    public function procesadoList(
        Request $request,
        ResolutorNombres $resolutor,
        ProcesadorAsistencia $procesador,
        ExcelMarcacionesProcesadas $excel,
    ): View|Response|BinaryFileResponse|RedirectResponse {
        $this->autorizarPermiso('ViewAny:Reporte');

        // Descargar el Excel saca los datos del sistema, así que se puede dar
        // por separado de verlos en pantalla. Va antes de resolver la ficha:
        // la autorización no depende de que el funcionario exista.
        if ((int) $request->query('print', 0) === 2) {
            $this->autorizarPermiso('Export:Reporte');
        }

        $persona = $resolutor->fichaPorCi((string) $request->query('persona', ''));

        if ($persona === null) {
            return redirect()
                ->route('reportes.marcaciones.procesado')
                ->with('error', 'Elegí un funcionario para generar el reporte.');
        }

        $desde = Carbon::parse((string) $request->query('desde', now()->startOfMonth()->toDateString()));
        $hasta = Carbon::parse((string) $request->query('hasta', now()->toDateString()));

        // Rango invertido: se da vuelta en vez de devolver un reporte vacío.
        if ($hasta->lessThan($desde)) {
            [$desde, $hasta] = [$hasta, $desde];
        }

        $dias = $procesador->procesar($persona['ci'], $desde, $hasta);
        $totales = $procesador->totales($dias);

        $datos = [
            'persona' => $persona,
            'dias' => $dias,
            'totales' => $totales,
            'desde' => $desde->toDateString(),
            'hasta' => $hasta->toDateString(),
        ];

        return match ((int) $request->query('print', 0)) {
            1 => view('reportes.marcaciones.procesado.print', $datos),
            2 => response()
                ->download(
                    $excel->generar($persona, $dias, $totales, $datos['desde'], $datos['hasta']),
                    $excel->nombreArchivo($persona),
                )
                ->deleteFileAfterSend(),
            default => view('reportes.marcaciones.procesado.lista', $datos),
        };
    }

    /**
     * Genera el reporte del funcionario elegido. El destino depende del
     * parámetro `print`: 1 = versión imprimible, 2 = CSV, otro = lista en pantalla.
     */
    public function sinProcesarList(Request $request, ResolutorNombres $resolutor): View|Response|RedirectResponse
    {
        $this->autorizarPermiso('ViewAny:Reporte');

        // Descargar el CSV saca los datos del sistema, así que se puede dar por
        // separado de verlos en pantalla. Va antes de resolver la ficha: la
        // autorización no depende de que el funcionario exista.
        if ((int) $request->query('print', 0) === 2) {
            $this->autorizarPermiso('Export:Reporte');
        }

        $persona = $resolutor->fichaPorCi((string) $request->query('persona', ''));

        if ($persona === null) {
            return redirect()
                ->route('reportes.marcaciones.sin-procesar')
                ->with('error', 'Elegí un funcionario para generar el reporte.');
        }

        $desde = (string) $request->query('desde', now()->startOfMonth()->toDateString());
        $hasta = (string) $request->query('hasta', now()->toDateString());
        $tipo = (string) $request->query('tipo', '');

        // Las marcaciones se cruzan por CI: la ficha puede venir de Mamoré, que
        // no tiene relación con la tabla local de asistencia.
        $marcaciones = Asistencia::query()
            ->where('ci', $persona['ci'])
            ->enRango($desde, $hasta)
            ->when($tipo !== '', fn (Builder $query) => $query->where('tipo', $tipo))
            ->orderBy('fecha')
            ->orderBy('hora')
            ->get();

        $datos = compact('persona', 'marcaciones', 'desde', 'hasta', 'tipo');

        return match ((int) $request->query('print', 0)) {
            1 => view('reportes.marcaciones.sinProcesar.print', $datos),
            2 => $this->descargarCsv($persona, $marcaciones),
            default => view('reportes.marcaciones.sinProcesar.lista', $datos),
        };
    }

    /**
     * Arma el CSV de las marcaciones (Fecha, Hora, Tipo) para descargar.
     *
     * @param  array<string, mixed>  $persona
     * @param  Collection<int, Asistencia>  $marcaciones
     */
    private function descargarCsv(array $persona, $marcaciones): Response
    {
        $csv = "\u{FEFF}Fecha,Hora,Tipo\n";

        foreach ($marcaciones as $marcacion) {
            $csv .= implode(',', [
                $marcacion->fecha?->format('d/m/Y') ?? '',
                $marcacion->hora?->format('H:i:s') ?? '',
                trim((string) $marcacion->tipo),
            ])."\n";
        }

        $archivo = 'marcaciones-'.Str::slug($persona['ci']).'-'.now()->format('Y-m-d').'.csv';

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$archivo}\"",
        ]);
    }
}
