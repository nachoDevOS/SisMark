<?php

use App\Http\Controllers\Api\AsistenciaFuncionarioController;
use App\Http\Controllers\Api\SolicitudLicenciaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API de asistencia (solo lectura)
|--------------------------------------------------------------------------
|
| La consumen los sistemas externos que necesitan mostrarle a un funcionario su
| propia asistencia sin darle acceso a SisMark. Hoy el único consumidor es
| Mamoré, donde los funcionarios ya tienen cuenta.
|
| Todo es GET: SisMark es el dueño de los datos de asistencia y nadie los
| escribe desde afuera. La autenticación es por clave compartida (`apikey`),
| que identifica al **sistema** consumidor, no a la persona.
|
| El limitador es por clave y no por IP: detrás de un proxy todos los pedidos
| llegan con la misma IP, así que limitar por ahí castigaría a un consumidor
| legítimo por el tráfico de otro.
*/

Route::middleware(['apikey', 'throttle:api'])
    ->prefix('v1')
    ->group(function (): void {
        Route::get('funcionarios/{ci}/marcaciones', [AsistenciaFuncionarioController::class, 'marcaciones'])
            ->name('api.funcionarios.marcaciones');

        Route::get('funcionarios/{ci}/asistencia', [AsistenciaFuncionarioController::class, 'asistencia'])
            ->name('api.funcionarios.asistencia');

        Route::get('funcionarios/{ci}/licencias', [AsistenciaFuncionarioController::class, 'licencias'])
            ->name('api.funcionarios.licencias');

        // Ficha de una licencia propia, con el desglose día por día. Va antes
        // del respaldo para que el binding no confunda las rutas.
        Route::get('funcionarios/{ci}/licencias/{licencia}', [AsistenciaFuncionarioController::class, 'licencia'])
            ->whereNumber('licencia')
            ->name('api.funcionarios.licencias.show');

        // Enlace temporal al respaldo de una licencia (certificado, memorándum).
        // Es el único punto de esta API donde el identificador no es la cédula
        // sino el id de una fila, así que el controlador comprueba que esa
        // licencia sea de esa cédula: sin eso, el id corrido dejaría bajarse los
        // certificados médicos de todo el personal.
        Route::get('funcionarios/{ci}/licencias/{licencia}/respaldo', [AsistenciaFuncionarioController::class, 'respaldo'])
            ->name('api.funcionarios.licencias.respaldo');

        // Único endpoint de escritura: la solicitud que hace el propio
        // funcionario desde Mamoré. Queda «Pendiente» hasta que Recursos
        // Humanos la resuelva desde SisMark.
        Route::post('funcionarios/{ci}/licencias', [SolicitudLicenciaController::class, 'store'])
            ->name('api.funcionarios.licencias.store');

        // Baja lógica de la propia solicitud, mientras siga «Pendiente». El
        // controlador comprueba las dos cosas: que la licencia sea de esa cédula
        // y que nadie la haya resuelto todavía.
        Route::delete('funcionarios/{ci}/licencias/{licencia}', [SolicitudLicenciaController::class, 'destroy'])
            ->name('api.funcionarios.licencias.destroy');
    });
