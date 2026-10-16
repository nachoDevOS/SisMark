<?php

namespace App\Http\Controllers;

use App\Exceptions\MamoreException;
use App\Services\DirectorioMamore;
use App\Services\ExcelFuncionariosSinTurno;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Reportes sobre los funcionarios mismos, no sobre sus marcaciones.
 *
 * «Sin turno» lista a quien tiene contrato firmado y hoy no tiene turno ni
 * horario vigente: es lo mismo que el listado de funcionarios con «Con
 * contrato» + «Sin turnos», pero acotable por dirección, unidad y tipo de
 * contrato, e imprimible para pasárselo a cada dirección.
 */
class ReporteFuncionarioController extends Controller
{
    /**
     * Filas por página de la tabla en pantalla. El imprimible las trae todas.
     */
    private const POR_PAGINA = 50;

    /**
     * Pantalla de filtros. Las direcciones y unidades las carga por AJAX desde
     * el mismo catálogo que el reporte por dirección; los tipos de contrato
     * vienen acá, y si Mamoré no responde el combo queda solo con «Todos».
     */
    public function sinTurno(DirectorioMamore $directorio): View
    {
        $this->autorizarPermiso('ViewAny:Reporte');

        try {
            $tipos = $directorio->configurado() ? $directorio->tiposDeContrato() : [];
        } catch (MamoreException) {
            $tipos = [];
        }

        return view('reportes.funcionarios.sin-turno.report', compact('tipos'));
    }

    /**
     * Genera el reporte: `print=1` es el imprimible y `print=2` el Excel, los
     * dos con todas las filas; cualquier otra cosa, la tabla paginada que se
     * pide por AJAX.
     */
    public function sinTurnoList(Request $request, DirectorioMamore $directorio, ExcelFuncionariosSinTurno $excel): View|BinaryFileResponse|RedirectResponse
    {
        $this->autorizarPermiso('ViewAny:Reporte');

        $impresion = (int) $request->query('print', 0);

        // El Excel saca los datos del sistema: mismo permiso aparte que los
        // demás reportes.
        if ($impresion === 2) {
            $this->autorizarPermiso('Export:Reporte');
        }
        $direccion = (int) $request->query('direccion', 0) ?: null;
        // La unidad cuelga de la dirección: suelta no se acepta.
        $unidad = $direccion === null ? null : ((int) $request->query('unidad', 0) ?: null);
        $tipo = (int) $request->query('tipo', 0) ?: null;

        $datos = [
            'direccion' => $direccion,
            'unidad' => $unidad,
            'tipo' => $tipo,
            'nombreDireccion' => $direccion === null ? 'Todas las direcciones' : (trim((string) $request->query('nombreDireccion', '')) ?: 'Dirección'),
            'nombreUnidad' => $unidad === null ? 'Todas las unidades' : (trim((string) $request->query('nombreUnidad', '')) ?: 'Unidad'),
            'nombreTipo' => $tipo === null ? 'Todos los tipos' : (trim((string) $request->query('nombreTipo', '')) ?: 'Tipo'),
        ];

        try {
            if ($impresion === 1) {
                return view('reportes.funcionarios.sin-turno.print', $datos + $directorio->todosSinTurno($direccion, $unidad, $tipo));
            }

            if ($impresion === 2) {
                $todos = $directorio->todosSinTurno($direccion, $unidad, $tipo);

                return response()
                    ->download(
                        $excel->generar($todos['filas'], $todos['total'], $datos['nombreDireccion'], $datos['nombreUnidad'], $datos['nombreTipo']),
                        $excel->nombreArchivo($direccion === null ? null : $datos['nombreDireccion']),
                    )
                    ->deleteFileAfterSend();
            }

            $pagina = max(1, (int) $request->query('page', 1));
            $resultado = $directorio->sinTurno($direccion, $unidad, $tipo, $pagina, self::POR_PAGINA);
        } catch (MamoreException $e) {
            $mensaje = 'No se pudo consultar Mamoré, así que el reporte no se generó: '.$e->getMessage();

            return $impresion !== 0
                ? redirect()->route('reportes.funcionarios.sin-turno')->with('error', $mensaje)
                : view('reportes.marcaciones.procesado.error', ['mensaje' => $mensaje]);
        }

        $funcionarios = new LengthAwarePaginator(
            $resultado['filas'],
            $resultado['total'],
            self::POR_PAGINA,
            $pagina,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return view('reportes.funcionarios.sin-turno.lista', $datos + compact('funcionarios'));
    }
}
