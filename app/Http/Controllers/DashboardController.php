<?php

namespace App\Http\Controllers;

use App\Services\ResumenEscritorio;
use Illuminate\Http\RedirectResponse;
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
    /**
     * Pantallas a las que se manda a quien no tiene el Escritorio, en el orden
     * del menú lateral: permiso que exige cada una y su ruta.
     *
     * @var array<string, string>
     */
    private const PANTALLAS_DE_INICIO = [
        'ViewAny:Persona' => 'funcionarios.index',
        'ViewAny:Asistencia' => 'marcaciones.index',
        'ViewAny:DiaExcepcional' => 'dias-excepcionales.index',
        'ViewAny:DiaTurno' => 'horarios.index',
        'ViewAny:AsignacionTurno' => 'turnos-asignados.index',
        'ViewAny:Licencia' => 'licencias.index',
        'ViewAny:Reporte' => 'reportes.marcaciones.sin-procesar',
        'ViewAny:Equipo' => 'equipos.index',
        'ViewAny:User' => 'usuarios.index',
        'ViewAny:Role' => 'roles.index',
        'ViewAny:SistemaExterno' => 'tokens-api.index',
    ];

    public function index(ResumenEscritorio $resumen): View|RedirectResponse
    {
        // El tablero resume marcaciones, funcionarios y licencias del día: no es
        // una portada neutra, así que pide su propio permiso. Pero `/` es
        // también a donde lleva el login, así que a quien no lo tiene se lo
        // manda a la primera pantalla de su rol en vez de recibirlo con un 403.
        $usuario = request()->user();

        if (! $usuario?->can('ViewAny:Escritorio')) {
            foreach (self::PANTALLAS_DE_INICIO as $permiso => $ruta) {
                if ($usuario?->can($permiso)) {
                    return redirect()->route($ruta);
                }
            }

            abort(403);
        }

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
