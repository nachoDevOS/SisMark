<?php

use App\Http\Controllers\Api\AsignacionTurnoApiController;
use App\Http\Controllers\Api\AsistenciaFuncionarioController;
use App\Http\Controllers\Api\SolicitudLicenciaController;
use App\Http\Controllers\Api\TurnoSugeridoController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API de asistencia
|--------------------------------------------------------------------------
|
| La consumen los sistemas externos que necesitan mostrarle a un funcionario su
| propia asistencia sin darle acceso a SisMark. Hoy el único consumidor es
| Mamoré, donde los funcionarios ya tienen cuenta.
|
| Es de lectura salvo tres excepciones, y las tres están comentadas donde se
| declaran: la solicitud de licencia y su baja —que nacen «Pendiente» y no
| surten efecto solas— y la asignación de turno al dar de alta un contrato, que
| sí se registra directo porque crea la obligación de marcar en vez de
| justificar una ausencia.
|
| **Autenticación: token de Sanctum sobre `App\Models\SistemaExterno`.**
|
| Antes era una sola clave compartida en `SISMARK_API_KEY`. Con una sola clave
| no se le puede cortar el acceso a un consumidor sin cortárselo a todos, ni
| rotarla sin coordinar el mismo día con cada equipo, ni saber cuál pidió qué.
| Ahora cada consumidor es una fila con su propio token, su propio cupo y su
| propio interruptor `activo`, que corta en el próximo pedido.
|
| El token **identifica al sistema, no a la persona**: quién es el funcionario
| lo decide el consumidor desde su propia sesión. Ver la advertencia en
| {@see \App\Http\Controllers\Api\AsistenciaFuncionarioController}.
|
| Los alcances (`abilities`) están declarados en `SistemaExterno::ALCANCES`. Van
| por área y no por endpoint: partirlos más fino obligaría a reemitir el token
| de Mamoré cada vez que se agrega una ruta.
|
| Emisión: pantalla «Tokens de API» (`/tokens-api`), con su permiso propio, la
| contraseña de quien la hace y bitácora. Por consola, de respaldo:
| `php artisan sismark:token {slug}`.
|
| El limitador es por consumidor y no por IP: detrás de un proxy todos los
| pedidos llegan con la misma IP, así que limitar por ahí castigaría a un
| consumidor legítimo por el tráfico de otro.
*/

Route::middleware(['auth:sanctum', 'throttle:api'])
    ->prefix('v1')
    ->group(function (): void {
        // El horario que SisMark sugiere para un contrato nuevo. Es el único
        // endpoint que no cuelga de una cédula: es un catálogo, no el dato de
        // una persona.
        Route::get('turnos/sugeridos', [TurnoSugeridoController::class, 'index'])
            ->middleware('abilities:turnos:read')
            ->name('api.turnos.sugeridos');

        Route::get('funcionarios/{ci}/marcaciones', [AsistenciaFuncionarioController::class, 'marcaciones'])
            ->middleware('abilities:asistencia:read')
            ->name('api.funcionarios.marcaciones');

        Route::get('funcionarios/{ci}/asistencia', [AsistenciaFuncionarioController::class, 'asistencia'])
            ->middleware('abilities:asistencia:read')
            ->name('api.funcionarios.asistencia');

        Route::get('funcionarios/{ci}/licencias', [AsistenciaFuncionarioController::class, 'licencias'])
            ->middleware('abilities:licencias:read')
            ->name('api.funcionarios.licencias');

        // Ficha de una licencia propia, con el desglose día por día. Va antes
        // del respaldo para que el binding no confunda las rutas.
        Route::get('funcionarios/{ci}/licencias/{licencia}', [AsistenciaFuncionarioController::class, 'licencia'])
            ->middleware('abilities:licencias:read')
            ->whereNumber('licencia')
            ->name('api.funcionarios.licencias.show');

        // Enlace temporal al respaldo de una licencia (certificado, memorándum).
        // Es el único punto de esta API donde el identificador no es la cédula
        // sino el id de una fila, así que el controlador comprueba que esa
        // licencia sea de esa cédula: sin eso, el id corrido dejaría bajarse los
        // certificados médicos de todo el personal.
        Route::get('funcionarios/{ci}/licencias/{licencia}/respaldo', [AsistenciaFuncionarioController::class, 'respaldo'])
            ->middleware('abilities:licencias:read')
            ->name('api.funcionarios.licencias.respaldo');

        // Escritura: la solicitud que hace el propio funcionario desde Mamoré.
        // Queda «Pendiente» hasta que Recursos Humanos la resuelva desde
        // SisMark.
        Route::post('funcionarios/{ci}/licencias', [SolicitudLicenciaController::class, 'store'])
            ->middleware('abilities:licencias:write')
            ->name('api.funcionarios.licencias.store');

        // Baja lógica de la propia solicitud, mientras siga «Pendiente». El
        // controlador comprueba las dos cosas: que la licencia sea de esa cédula
        // y que nadie la haya resuelto todavía.
        Route::delete('funcionarios/{ci}/licencias/{licencia}', [SolicitudLicenciaController::class, 'destroy'])
            ->middleware('abilities:licencias:write')
            ->name('api.funcionarios.licencias.destroy');

        // El horario que ya tiene asignado el funcionario, para que lo vea desde
        // el sistema del consumidor. Va con `turnos:read` y no con `write`:
        // mostrarle a alguien su horario no es asignárselo.
        Route::get('funcionarios/{ci}/turnos', [AsignacionTurnoApiController::class, 'index'])
            ->middleware('abilities:turnos:read')
            ->name('api.funcionarios.turnos.index');

        // Asigna el horario al dar de alta un contrato en Mamoré, con el rango
        // del contrato. A diferencia de la licencia, esta escritura sí surte
        // efecto sola: un turno crea la obligación de marcar, no la borra. El
        // motivo largo está en el controlador.
        Route::post('funcionarios/{ci}/turnos', [AsignacionTurnoApiController::class, 'store'])
            ->middleware('abilities:turnos:write')
            ->name('api.funcionarios.turnos.store');

        // Mueve la vigencia de lo ya asignado cuando el contrato cambia de
        // fechas: una adenda que renueva, una conclusión anticipada, una fecha
        // corregida. Sin esto, extender un contrato dejaba los días nuevos sin
        // turno y por lo tanto sin control de asistencia.
        Route::put('funcionarios/{ci}/turnos', [AsignacionTurnoApiController::class, 'update'])
            ->middleware('abilities:turnos:write')
            ->name('api.funcionarios.turnos.update');

        // Baja lógica del horario cuando el contrato se anula. La fila se queda
        // con `deleted_at`: el turno es el respaldo de por qué se le exigió
        // marcar a esa persona en esas fechas, y borrarlo dejaría sin
        // explicación las faltas que ya se le imputaron.
        Route::delete('funcionarios/{ci}/turnos', [AsignacionTurnoApiController::class, 'destroy'])
            ->middleware('abilities:turnos:write')
            ->name('api.funcionarios.turnos.destroy');
    });
