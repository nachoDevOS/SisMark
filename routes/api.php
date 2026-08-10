<?php

use App\Http\Controllers\Api\AsistenciaFuncionarioController;
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
    });
