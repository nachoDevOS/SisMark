<?php

namespace App\Http\Controllers;

use App\Services\ResumenEscritorio;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Escritorio: el tablero de inicio.
 *
 * Lo justo para la mañana de Recursos Humanos: arriba los avisos que piden
 * hacer algo, después los cuatro números del día y los accesos a las
 * pantallas que más se usan.
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
        'ViewAny:Horario' => 'horarios.index',
        'ViewAny:AsignacionHorario' => 'horarios-asignados.index',
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
            'hoy' => $resumen->hoy(),
            'equiposFueraDeLinea' => $resumen->equiposFueraDeLinea(),
            'proximoExcepcional' => $resumen->proximoExcepcional(),
            'ultimaMarcacion' => $resumen->ultimaMarcacion(),
        ]);
    }
}
