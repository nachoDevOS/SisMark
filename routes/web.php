<?php

use App\Http\Controllers\AsignacionTurnoController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DiaExcepcionalController;
use App\Http\Controllers\DiaTurnoController;
use App\Http\Controllers\EquipoController;
use App\Http\Controllers\LicenciaController;
use App\Http\Controllers\MarcacionController;
use App\Http\Controllers\PersonaController;
use App\Http\Controllers\ReporteMarcacionController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Login propio del sitio (guard 'web' estándar).
Route::middleware('guest')->group(function (): void {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store']);
});

// CRUD clásico (MVC) protegido con la sesión del sitio.
Route::middleware('auth')->group(function (): void {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    // Escritorio: resumen general (equipos, asistencia SIA, gráfico de
    // marcaciones).
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    // El panel de calidad de datos cuenta la tabla entera de marcaciones: se
    // sirve aparte para que la portada no lo espere.
    Route::get('escritorio/ajax/calidad', [DashboardController::class, 'calidad'])->name('dashboard.calidad');

    // Bitácora de acciones sobre las marcaciones. Va antes del resource para que
    // el binding {equipo} del show no capture la palabra «auditoria».
    Route::get('equipos/auditoria', [EquipoController::class, 'auditoria'])->name('equipos.auditoria');
    // CRUD completo (base local).
    Route::resource('equipos', EquipoController::class);
    // Habla en vivo con el microservicio Python (probar conexión, exportar y
    // sincronizar marcaciones del equipo).
    Route::post('equipos/{equipo}/probar-conexion', [EquipoController::class, 'probarConexion'])->name('equipos.probar-conexion');
    Route::get('equipos/{equipo}/marcaciones/exportar', [EquipoController::class, 'exportarMarcaciones'])->name('equipos.marcaciones.exportar');
    Route::post('equipos/{equipo}/marcaciones/sincronizar', [EquipoController::class, 'sincronizarMarcaciones'])->name('equipos.marcaciones.sincronizar');
    // Vacía el buffer de marcaciones del reloj (irreversible, todo o nada).
    Route::post('equipos/{equipo}/marcaciones/limpiar', [EquipoController::class, 'limpiarMarcaciones'])->name('equipos.marcaciones.limpiar');
    Route::resource('usuarios', UserController::class)
        ->parameters(['usuarios' => 'usuario'])
        ->except('show');
    // Roles y su matriz de permisos.
    Route::resource('roles', RoleController::class)->except('show');

    // Funcionarios (solo lectura). Listado con dos fuentes (Mamoré/SIAT) y ficha.
    // La ficha por cédula de Mamoré va antes del resource para que no la capture
    // el binding {persona} del show local.
    Route::get('funcionarios/mamore/{ci}', [PersonaController::class, 'mamoreShow'])->name('funcionarios.mamore');
    // Tabla del listado por AJAX (browse/list, con su paginación).
    Route::get('funcionarios/ajax/list', [PersonaController::class, 'list'])->name('funcionarios.list');
    // Tablas de las solapas de la ficha (local y Mamoré) por AJAX, todas por CI.
    Route::get('funcionarios/ajax/marcaciones', [PersonaController::class, 'marcacionesList'])->name('funcionarios.marcaciones.list');
    Route::get('funcionarios/ajax/licencias', [PersonaController::class, 'licenciasList'])->name('funcionarios.licencias.list');
    Route::get('funcionarios/ajax/turnos', [PersonaController::class, 'turnosList'])->name('funcionarios.turnos.list');
    // Régimen disciplinario del RIP: qué acumuló el funcionario en un mes y qué
    // sanción le correspondería. Va por mes y no por rango porque las escalas
    // del reglamento cuentan «en el mes» y «en la gestión».
    Route::get('funcionarios/ajax/rip', [PersonaController::class, 'ripList'])->name('funcionarios.rip.list');
    Route::resource('funcionarios', PersonaController::class)
        ->parameters(['funcionarios' => 'persona'])
        ->only(['index', 'show']);

    // Horarios (turnos) del SIA: «Administrador de horarios» del escritorio.
    Route::get('horarios/ajax/list', [DiaTurnoController::class, 'list'])->name('horarios.list');
    Route::resource('horarios', DiaTurnoController::class)
        ->parameters(['horarios' => 'horario']);

    // Turnos asignados a cada funcionario (solo lectura): la asignación se cruza
    // con el funcionario por CI, y con el turno por la FK `turno_id`.
    Route::get('turnos-asignados/ajax/list', [AsignacionTurnoController::class, 'list'])->name('turnos-asignados.list');
    // Búsqueda JSON de funcionarios para el combo del formulario de asignación.
    Route::get('turnos-asignados/ajax/funcionarios', [AsignacionTurnoController::class, 'buscarFuncionarios'])->name('turnos-asignados.funcionarios');
    Route::get('turnos-asignados/create', [AsignacionTurnoController::class, 'create'])->name('turnos-asignados.create');
    Route::post('turnos-asignados', [AsignacionTurnoController::class, 'store'])->name('turnos-asignados.store');
    // Concluir = ponerle fecha de fin (el funcionario dejó ese turno, pero la
    // historia queda). Eliminar = la asignación se cargó por error.
    Route::patch('turnos-asignados/{asignacion}/concluir', [AsignacionTurnoController::class, 'concluir'])->name('turnos-asignados.concluir');
    Route::delete('turnos-asignados/{asignacion}', [AsignacionTurnoController::class, 'destroy'])->name('turnos-asignados.destroy');
    Route::get('turnos-asignados', [AsignacionTurnoController::class, 'index'])->name('turnos-asignados.index');

    // Licencias/permisos de personal: listado AJAX + pantalla «Licenciar»
    // (turnos asignados + rango de fechas). Sin edición: se anota o se elimina.
    Route::get('licencias/ajax/list', [LicenciaController::class, 'list'])->name('licencias.list');
    // Búsqueda JSON de funcionarios para el combo de la pantalla «Licenciar».
    Route::get('licencias/ajax/funcionarios', [LicenciaController::class, 'buscarFuncionarios'])->name('licencias.funcionarios');
    // Respaldo de la licencia (certificado, memorándum). Va contra el bucket
    // con un enlace firmado de vida corta: el archivo nunca es público.
    Route::get('licencias/{licencia}/respaldo', [LicenciaController::class, 'respaldo'])->name('licencias.respaldo');
    // Resolución de las solicitudes que llegan de Mamoré en estado «Pendiente».
    // Aprobar y rechazar son rutas distintas y no un campo del formulario: así
    // un envío manipulado no puede convertir un rechazo en una aprobación.
    Route::patch('licencias/{licencia}/aprobar', [LicenciaController::class, 'aprobar'])->name('licencias.aprobar');
    Route::patch('licencias/{licencia}/rechazar', [LicenciaController::class, 'rechazar'])->name('licencias.rechazar');
    // `show` es la ficha de la solicitud completa (todos sus días), que es el
    // paso previo obligado a aprobarla.
    Route::resource('licencias', LicenciaController::class)
        ->only(['index', 'create', 'store', 'show', 'destroy']);

    // Parámetros → Días excepcionales (feriados/tolerancias que no controlan
    // asistencia), base local MySQL. CRUD sin ficha (show).
    Route::get('dias-excepcionales/ajax/list', [DiaExcepcionalController::class, 'list'])->name('dias-excepcionales.list');
    // Respaldo del día (decreto, resolución). Igual que en licencias: va contra
    // el bucket con un enlace firmado de vida corta, el archivo nunca es público.
    Route::get('dias-excepcionales/{diaExcepcional}/respaldo', [DiaExcepcionalController::class, 'respaldo'])->name('dias-excepcionales.respaldo');
    Route::resource('dias-excepcionales', DiaExcepcionalController::class)
        ->parameters(['dias-excepcionales' => 'diaExcepcional'])
        ->only(['index', 'create', 'store', 'edit', 'update', 'destroy']);
    // Reporte imprimible de marcaciones «sin procesar» (todo lo marcado por el
    // funcionario en un rango), con el formato del sistema de escritorio viejo.
    Route::get('funcionarios/{persona}/reporte-marcaciones', [PersonaController::class, 'reporteMarcaciones'])->name('funcionarios.reporte');

    // Dos formas de cargar marcaciones: el CSV que ya exporta
    // "Equipos > Marcaciones > Exportar" y el alta manual de a una (tipo M)
    // desde el modal, para lo que el reloj no registró (papeleta, equipo caído).
    Route::get('marcaciones', [MarcacionController::class, 'index'])->name('marcaciones.index');
    Route::get('marcaciones/ajax/list', [MarcacionController::class, 'list'])->name('marcaciones.list');
    Route::post('marcaciones/importar', [MarcacionController::class, 'importar'])->name('marcaciones.importar');
    Route::post('marcaciones', [MarcacionController::class, 'store'])->name('marcaciones.store');

    // Reportes: selección de funcionario + generación (pantalla, imprimible o
    // CSV). «Sin procesar» = todas las marcaciones crudas del rango.
    Route::get('reportes/marcaciones/sin-procesar', [ReporteMarcacionController::class, 'sinProcesar'])->name('reportes.marcaciones.sin-procesar');
    Route::get('reportes/marcaciones/sin-procesar/generar', [ReporteMarcacionController::class, 'sinProcesarList'])->name('reportes.marcaciones.sin-procesar.generar');
    // «Procesado» = las mismas marcas cruzadas contra el turno asignado, los días
    // excepcionales y las licencias, con entradas, salidas, atrasos y horas.
    Route::get('reportes/marcaciones/procesado', [ReporteMarcacionController::class, 'procesado'])->name('reportes.marcaciones.procesado');
    Route::get('reportes/marcaciones/procesado/generar', [ReporteMarcacionController::class, 'procesadoList'])->name('reportes.marcaciones.procesado.generar');
    // Búsqueda JSON de funcionarios para el combo (select2) del reporte.
    Route::get('reportes/marcaciones/funcionarios', [ReporteMarcacionController::class, 'buscarFuncionarios'])->name('reportes.marcaciones.funcionarios');
});
