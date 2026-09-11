<?php

namespace App\Http\Controllers;

use App\Exceptions\MamoreException;
use App\Models\Asistencia;
use App\Models\Persona;
use App\Services\DirectorioMamore;
use App\Services\ExcelMarcacionesProcesadas;
use App\Services\ProcesadorAsistencia;
use App\Services\ReporteDireccion;
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

        $impresion = (int) $request->query('print', 0);

        $persona = $resolutor->fichaPorCi((string) $request->query('persona', ''));

        if ($persona === null) {
            return $this->reporteNoGenerado($impresion, 'Elegí un funcionario para generar el reporte.');
        }

        $desde = Carbon::parse((string) $request->query('desde', now()->startOfMonth()->toDateString()));
        $hasta = Carbon::parse((string) $request->query('hasta', now()->toDateString()));

        // Rango invertido: se da vuelta en vez de devolver un reporte vacío.
        if ($hasta->lessThan($desde)) {
            [$desde, $hasta] = [$hasta, $desde];
        }

        // Sin contratos verificados no se procesa nada. Es deliberado: los
        // contratos deciden qué días se controlan, así que generar el reporte
        // sin poder consultarlos daría faltas en días que quizá estaban
        // cubiertos. Se prefiere no dar reporte antes que dar uno que no se
        // puede sostener.
        try {
            $dias = $procesador->procesar($persona['ci'], $desde, $hasta);
        } catch (MamoreException $e) {
            return $this->reporteNoGenerado(
                $impresion,
                'No se pudieron verificar los contratos en Mamoré, así que el reporte no se generó: '.$e->getMessage(),
            );
        }

        $totales = $procesador->totales($dias);

        $datos = [
            'persona' => $persona,
            'dias' => $dias,
            'totales' => $totales,
            'desde' => $desde->toDateString(),
            'hasta' => $hasta->toDateString(),
            // La solapa de la ficha del funcionario pide `encabezado=0`: quién es
            // ya está arriba, con foto y datos completos, y repetirlo sobre la
            // tabla solo le roba lugar. El imprimible y el Excel lo conservan
            // siempre: ahí la hoja sale sola y tiene que identificarse.
            'conEncabezado' => $request->boolean('encabezado', true),
        ];

        return match ($impresion) {
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
     * Formulario de selección del reporte **por dirección**: se elige una
     * dirección administrativa y un rango, y sale una fila por funcionario con
     * sus totales.
     *
     * Responde lo que el reporte individual no puede: con aquel hay que saber de
     * antemano a quién mirar, así que el atraso de quien nadie pensó en
     * consultar no aparecía nunca.
     */
    public function porDireccion(Request $request): View
    {
        $this->autorizarPermiso('ViewAny:Reporte');

        $desde = $request->query('desde', now()->startOfMonth()->toDateString());
        $hasta = $request->query('hasta', now()->toDateString());

        return view('reportes.marcaciones.procesado.direccion-report', compact('desde', 'hasta'));
    }

    /**
     * Direcciones y unidades administrativas para los combos, con cuánta gente
     * tuvo cada una en el rango elegido.
     *
     * Van por AJAX y no embebidas en la vista porque el conteo depende del
     * rango: cambiar las fechas cambia quién entra, y un «(47)» calculado con
     * las fechas de ayer mentiría sobre las filas que va a traer el reporte.
     *
     * Las dos viajan juntas en una sola respuesta: la unidad se elige recién
     * después de la dirección, pero pedirlas por separado gastaría un segundo
     * viaje —de una cuota de 60 por minuto— para traer algo que ya vino en el
     * primero.
     */
    public function direcciones(Request $request, DirectorioMamore $directorio): JsonResponse
    {
        $this->autorizarPermiso('ViewAny:Reporte');

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
     * Genera el reporte de la dirección elegida: `print=1` es el imprimible, y
     * cualquier otra cosa la tabla en pantalla, que se pide por AJAX.
     */
    public function porDireccionList(Request $request, ReporteDireccion $reporte): View|RedirectResponse
    {
        $this->autorizarPermiso('ViewAny:Reporte');

        $impresion = (int) $request->query('print', 0);
        $direccion = (int) $request->query('direccion', 0);
        $nombreDireccion = trim((string) $request->query('nombre', ''));

        // La unidad es opcional: sin ella entra la dirección entera, que es el
        // caso normal. Cero y vacío valen lo mismo que no mandarla.
        $unidad = (int) $request->query('unidad', 0) ?: null;
        $nombreUnidad = trim((string) $request->query('nombreUnidad', ''));

        if ($direccion <= 0) {
            return $this->reporteNoGenerado($impresion, 'Elegí una dirección para generar el reporte.', 'reportes.marcaciones.direccion');
        }

        $desde = Carbon::parse((string) $request->query('desde', now()->startOfMonth()->toDateString()));
        $hasta = Carbon::parse((string) $request->query('hasta', now()->toDateString()));

        // Rango invertido: se da vuelta en vez de devolver un reporte vacío.
        if ($hasta->lessThan($desde)) {
            [$desde, $hasta] = [$hasta, $desde];
        }

        // Igual que el individual: sin poder verificar los contratos no se emite
        // el reporte. Acá pesa más todavía, porque los contratos deciden además
        // **quién entra en la lista**, no solo qué días se le controlan.
        try {
            $filas = $reporte->filas($direccion, $desde, $hasta, $unidad);
        } catch (MamoreException $e) {
            return $this->reporteNoGenerado(
                $impresion,
                'No se pudieron verificar los contratos en Mamoré, así que el reporte no se generó: '.$e->getMessage(),
                'reportes.marcaciones.direccion',
            );
        }

        $datos = [
            'filas' => $filas,
            'totales' => $reporte->totales($filas),
            'direccion' => $direccion,
            'nombreDireccion' => $nombreDireccion ?: 'Dirección',
            'unidad' => $unidad,
            // Qué alcance tiene la hoja. Sin unidad elegida lo dice explícito:
            // un encabezado que solo nombra la dirección deja sin saber si el
            // reporte es de toda ella o de una parte.
            'nombreUnidad' => $unidad === null
                ? 'Todas las unidades'
                : ($nombreUnidad ?: 'Unidad'),
            'desde' => $desde->toDateString(),
            'hasta' => $hasta->toDateString(),
            // Los minutos de atraso se acumulan por mes calendario y no se
            // suman entre meses, así que un rango que cruza meses se abre en una
            // fila por mes. Dentro de un solo mes el total del rango ya es
            // mensual y la fila por persona alcanza.
            'cruzaMeses' => ReporteDireccion::cruzaMeses($desde, $hasta),
        ];

        return $impresion === 1
            ? view('reportes.marcaciones.procesado.direccion-print', $datos)
            : view('reportes.marcaciones.procesado.direccion-lista', $datos);
    }

    /**
     * Qué devolver cuando el reporte procesado no se puede generar.
     *
     * La tabla en pantalla **siempre se pide por AJAX** —desde la pantalla de
     * reportes y desde la solapa de la ficha—, así que ahí va un parcial con el
     * aviso. Una redirección la seguiría `fetch` sin avisarle a quien la pidió, y
     * la pantalla de selección entera terminaría dibujada adentro del recuadro
     * de la tabla, con su sidebar y su barra superior.
     *
     * El imprimible y el Excel sí se abren como navegación normal (un enlace),
     * así que ahí la redirección de siempre es lo correcto. `$ruta` dice a qué
     * pantalla se vuelve, porque el reporte por dirección tiene la suya y caer
     * en la del individual dejaría el aviso donde no se lo pidió.
     */
    private function reporteNoGenerado(
        int $impresion,
        string $mensaje,
        string $ruta = 'reportes.marcaciones.procesado',
    ): View|RedirectResponse {
        return $impresion === 0
            ? view('reportes.marcaciones.procesado.error', ['mensaje' => $mensaje])
            : redirect()->route($ruta)->with('error', $mensaje);
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
