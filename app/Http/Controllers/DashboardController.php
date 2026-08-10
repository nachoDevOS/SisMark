<?php

namespace App\Http\Controllers;

use App\Services\ResumenEscritorio;
use Illuminate\View\View;

/**
 * Escritorio: el tablero de inicio.
 *
 * Está ordenado por lo que alguien necesita saber al abrirlo, de arriba hacia
 * abajo: primero si puede confiar en los datos de hoy, después qué pasó hoy, y
 * al final el contexto (tendencia, calidad de los datos y agenda).
 *
 * Todos los números salen de la base local MySQL; el armado vive en
 * {@see ResumenEscritorio}, que es quien se ocupa de que las consultas caigan
 * sobre el índice `(fecha, ci)` de una tabla de 4,4 millones de filas.
 */
class DashboardController extends Controller
{
    public function index(ResumenEscritorio $resumen): View
    {
        // El tablero resume marcaciones, funcionarios y licencias del día: no es
        // una portada neutra, así que pide su propio permiso.
        $this->autorizarPermiso('ViewAny:Escritorio');

        return view('dashboard.index', [
            'captura' => $resumen->captura(),
            'hoy' => $resumen->hoy(),
            'histograma' => $resumen->histogramaHorario(),
            'tendencia' => $resumen->tendencia(),
            'agenda' => $resumen->agenda(),
            'equiposFueraDeLinea' => $resumen->equiposFueraDeLinea(),
        ]);
    }

    /**
     * Panel de calidad de los datos, que la portada carga por AJAX.
     *
     * Va aparte porque es el único que cuenta la tabla entera sin que ningún
     * índice ayude: son casi dos segundos sobre 4,4 millones de filas. Traerlo
     * dentro de la respuesta principal haría esperar todo el escritorio —que
     * arma el resto en unos 115 ms— por el panel menos urgente de todos.
     */
    public function calidad(ResumenEscritorio $resumen): View
    {
        $this->autorizarPermiso('ViewAny:Escritorio');

        return view('dashboard.calidad', ['calidad' => $resumen->calidadDatos()]);
    }
}
