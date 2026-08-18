<?php

use App\Models\Equipo;
use App\Models\EquipoAuditoria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(asSuperAdmin());

    config()->set('services.device_service.url', 'http://microservicio.test');
    config()->set('services.device_service.token', 'token-de-prueba');
});

test('el listado muestra los equipos registrados', function () {
    $equipo = Equipo::factory()->create(['nombre' => 'iClock Entrada']);

    $this->get(route('equipos.index'))
        ->assertOk()
        ->assertSee('iClock Entrada');
});

test('el listado tiene el modal para descargar el CSV por rango de fechas de cada equipo', function () {
    $equipo = Equipo::factory()->create();

    $this->get(route('equipos.index'))
        ->assertOk()
        ->assertSee(route('equipos.marcaciones.exportar', $equipo), escape: false)
        ->assertSee('name="desde"', escape: false)
        ->assertSee('name="hasta"', escape: false);
});

test('muestra el formulario de alta', function () {
    $this->get(route('equipos.create'))
        ->assertOk()
        ->assertSee('Nuevo equipo');
});

test('guarda un equipo nuevo y redirige al listado', function () {
    $datos = [
        'nombre' => 'iClock Bodega',
        'ip' => '192.168.1.60',
        'puerto' => 4370,
        'comm_key' => 0,
        'ubicacion' => 'Bodega',
        'activo' => '1',
    ];

    $this->post(route('equipos.store'), $datos)
        ->assertRedirect(route('equipos.index'))
        ->assertSessionHas('estado');

    $this->assertDatabaseHas('equipos', [
        'nombre' => 'iClock Bodega',
        'ip' => '192.168.1.60',
        'activo' => true,
    ]);
});

test('el formulario no ofrece marcar el equipo como maestro', function () {
    // La replicación de huellas entre equipos no está implementada (ver
    // docs/COMUNICACION-BIOMETRICOS.md §6), así que el campo no se ofrece:
    // marcarlo no cambiaba nada.
    $this->get(route('equipos.create'))
        ->assertOk()
        ->assertDontSee('maestro')
        ->assertDontSee('name="es_master"', escape: false);

    $equipo = Equipo::factory()->create();

    $this->get(route('equipos.edit', $equipo))
        ->assertOk()
        ->assertDontSee('name="es_master"', escape: false);

    $this->get(route('equipos.show', $equipo))
        ->assertOk()
        ->assertDontSee('Maestro');
});

test('un es_master enviado a mano no se guarda', function () {
    $this->post(route('equipos.store'), [
        'nombre' => 'iClock Colado',
        'ip' => '192.168.1.61',
        'puerto' => 4370,
        'comm_key' => 0,
        'activo' => '1',
        'es_master' => '1',
    ])->assertRedirect(route('equipos.index'));

    // La columna sigue en la base con su default, pero ya no es asignable.
    $this->assertDatabaseHas('equipos', [
        'nombre' => 'iClock Colado',
        'es_master' => false,
    ]);
});

test('rechaza una IP inválida', function () {
    $this->post(route('equipos.store'), [
        'nombre' => 'Malo',
        'ip' => 'no-es-ip',
        'puerto' => 4370,
        'comm_key' => 0,
    ])->assertSessionHasErrors('ip');

    $this->assertDatabaseCount('equipos', 0);
});

test('rechaza IP + puerto duplicados', function () {
    Equipo::factory()->create(['ip' => '192.168.1.70', 'puerto' => 4370]);

    $this->post(route('equipos.store'), [
        'nombre' => 'Duplicado',
        'ip' => '192.168.1.70',
        'puerto' => 4370,
        'comm_key' => 0,
    ])->assertSessionHasErrors('ip');

    $this->assertDatabaseCount('equipos', 1);
});

test('muestra la ficha de un equipo', function () {
    $equipo = Equipo::factory()->create(['nombre' => 'iClock Ficha']);

    $this->get(route('equipos.show', $equipo))
        ->assertOk()
        ->assertSee('iClock Ficha');
});

test('muestra el formulario de edición con los datos actuales', function () {
    $equipo = Equipo::factory()->create(['nombre' => 'iClock Editar']);

    $this->get(route('equipos.edit', $equipo))
        ->assertOk()
        ->assertSee('iClock Editar');
});

test('actualiza un equipo existente', function () {
    $equipo = Equipo::factory()->create(['nombre' => 'Viejo']);

    $this->put(route('equipos.update', $equipo), [
        'nombre' => 'Nuevo nombre',
        'ip' => $equipo->ip,
        'puerto' => $equipo->puerto,
        'comm_key' => $equipo->comm_key,
        'activo' => '1',
    ])->assertRedirect(route('equipos.index'));

    expect($equipo->refresh()->nombre)->toBe('Nuevo nombre');
});

test('elimina un equipo (lógicamente)', function () {
    $equipo = Equipo::factory()->create();

    $this->delete(route('equipos.destroy', $equipo), ['deleteObservacion' => 'Equipo dado de baja por falla de hardware.'])
        ->assertRedirect(route('equipos.index'));

    // Eliminación lógica: la fila queda con deleted_at, no se borra.
    $this->assertSoftDeleted('equipos', ['id' => $equipo->id]);
});

test('no elimina un equipo si no se escribe el motivo', function () {
    $equipo = Equipo::factory()->create();

    $this->from(route('equipos.index'))
        ->delete(route('equipos.destroy', $equipo))
        ->assertSessionHasErrors('deleteObservacion');

    $this->assertNotSoftDeleted('equipos', ['id' => $equipo->id]);
    expect(EquipoAuditoria::count())->toBe(0);
});

test('un invitado no puede entrar al listado', function () {
    auth()->logout();

    $this->get(route('equipos.index'))->assertRedirect();
});

test('un usuario sin permiso no puede entrar al listado', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('equipos.index'))->assertForbidden();
});

test('un rol con permisos de Usuarios pero no de Equipos no puede entrar', function () {
    Permission::firstOrCreate(['name' => 'ViewAny:User', 'guard_name' => 'web']);
    $rol = Role::create(['name' => 'solo_usuarios', 'guard_name' => 'web']);
    $rol->givePermissionTo('ViewAny:User');

    $this->actingAs(User::factory()->create()->assignRole($rol));

    $this->get(route('equipos.index'))->assertForbidden();
});

test('probar conexión marca el equipo en línea y guarda el algoritmo', function () {
    Http::fake([
        'microservicio.test/device/info*' => Http::response([
            'en_linea' => true,
            'algoritmo' => 'ZLM60_TFT | Ver 6.60',
        ], 200),
    ]);

    $equipo = Equipo::factory()->create(['en_linea' => false, 'algoritmo' => null]);

    $this->post(route('equipos.probar-conexion', $equipo))
        ->assertRedirect()
        ->assertSessionHas('estado');

    expect($equipo->refresh())
        ->en_linea->toBeTrue()
        ->algoritmo->toBe('ZLM60_TFT | Ver 6.60');

    expect($equipo->ultima_sync)->not->toBeNull();
});

test('probar conexión marca fuera de línea si el equipo no responde', function () {
    Http::fake([
        'microservicio.test/device/info*' => Http::response([
            'detail' => 'No se pudo conectar con el equipo 192.168.1.201:4370',
        ], 503),
    ]);

    $equipo = Equipo::factory()->create(['en_linea' => true]);

    $this->post(route('equipos.probar-conexion', $equipo))
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($equipo->refresh()->en_linea)->toBeFalse();
});

test('cada descarga lee el equipo en vivo, saltando la caché de 15 min', function () {
    Http::fake([
        'microservicio.test/device/attendance*' => Http::response([
            'marcaciones' => [
                ['uid' => 1, 'user_id' => '1', 'nombre' => 'Empleado', 'timestamp' => '2026-07-10T08:00:00'],
            ],
        ], 200),
    ]);

    $equipo = Equipo::factory()->create();

    $this->get(route('equipos.marcaciones.exportar', $equipo))->assertOk();
    $this->get(route('equipos.marcaciones.exportar', ['equipo' => $equipo, 'desde' => '2026-07-05', 'hasta' => '2026-07-15']))->assertOk();

    Http::assertSentCount(2);
});

test('descarga las marcaciones del equipo en CSV', function () {
    Http::fake([
        'microservicio.test/device/attendance*' => Http::response([
            'marcaciones' => [
                ['uid' => 1, 'user_id' => '7633685', 'nombre' => 'Ignacio Molina Guzman', 'timestamp' => '2026-07-09T21:05:48'],
            ],
        ], 200),
    ]);

    $equipo = Equipo::factory()->create(['nombre' => 'iClock Prueba']);

    $response = $this->get(route('equipos.marcaciones.exportar', $equipo))
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');

    expect($response->headers->get('content-disposition'))->toContain('marcaciones-iclock-prueba-');
    expect($response->getContent())
        ->toContain('CI/ID,Nombre,Fecha,Hora')
        ->toContain('7633685,"Ignacio Molina Guzman",09/07/2026,21:05:48');
});

test('exporta el csv filtrado por rango de fechas', function () {
    Http::fake([
        'microservicio.test/device/attendance*' => Http::response([
            'marcaciones' => [
                ['uid' => 1, 'user_id' => '1', 'nombre' => 'Dentro del rango', 'timestamp' => '2026-07-10T08:00:00'],
                ['uid' => 2, 'user_id' => '2', 'nombre' => 'Fuera del rango', 'timestamp' => '2026-07-20T08:00:00'],
            ],
        ], 200),
    ]);

    $equipo = Equipo::factory()->create();

    $response = $this->get(route('equipos.marcaciones.exportar', ['equipo' => $equipo, 'desde' => '2026-07-05', 'hasta' => '2026-07-15']))
        ->assertOk();

    expect($response->getContent())
        ->toContain('Dentro del rango')
        ->not->toContain('Fuera del rango');
});

test('la exportación le reenvía el rango al microservicio para que filtre en origen', function () {
    Http::fake([
        'microservicio.test/device/attendance*' => Http::response(['marcaciones' => []], 200),
    ]);

    $equipo = Equipo::factory()->create();

    $this->get(route('equipos.marcaciones.exportar', ['equipo' => $equipo, 'desde' => '2026-07-01', 'hasta' => '2026-07-23']))
        ->assertOk();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'desde=2026-07-01')
        && str_contains($request->url(), 'hasta=2026-07-23'));
});

test('la descarga CSV redirige con error si el equipo no responde', function () {
    Http::fake([
        'microservicio.test/device/attendance*' => Http::response([
            'detail' => 'No se pudo conectar con el equipo',
        ], 503),
    ]);

    $equipo = Equipo::factory()->create();

    $this->get(route('equipos.marcaciones.exportar', $equipo))
        ->assertRedirect()
        ->assertSessionHas('error', 'No se pudo conectar con el equipo');
});

test('sincroniza las marcaciones del equipo directo a la BD local', function () {
    DB::table('personas')->insert([
        'ci' => '7633685', 'paterno' => 'Molina', 'materno' => null, 'nombres' => 'Ignacio', 'pinReloj' => '7633685', 'marcaDirecta' => false,
    ]);

    Http::fake([
        'microservicio.test/device/attendance*' => Http::response([
            'marcaciones' => [
                ['uid' => 1, 'user_id' => '7633685', 'nombre' => 'NN', 'timestamp' => '2026-07-10T08:00:00'],
            ],
        ], 200),
    ]);

    $equipo = Equipo::factory()->create();

    $this->post(route('equipos.marcaciones.sincronizar', $equipo), ['desde' => '2026-07-01', 'hasta' => '2026-07-15'])
        ->assertRedirect()
        ->assertSessionHas('estado', fn (string $mensaje) => str_contains($mensaje, '1 marcación(es) nueva(s)'));

    expect(DB::table('asistencias')->where('ci', '7633685')->count())->toBe(1);
});

test('la sincronización a la BD respeta el rango de fechas', function () {
    DB::table('personas')->insert([
        'ci' => '5', 'paterno' => 'Test', 'materno' => null, 'nombres' => 'Uno', 'pinReloj' => '5', 'marcaDirecta' => false,
    ]);

    Http::fake([
        'microservicio.test/device/attendance*' => Http::response([
            'marcaciones' => [
                ['uid' => 1, 'user_id' => '5', 'nombre' => 'NN', 'timestamp' => '2026-07-10T08:00:00'],
                ['uid' => 2, 'user_id' => '5', 'nombre' => 'NN', 'timestamp' => '2026-07-20T08:00:00'],
            ],
        ], 200),
    ]);

    $equipo = Equipo::factory()->create();

    $this->post(route('equipos.marcaciones.sincronizar', $equipo), ['desde' => '2026-07-01', 'hasta' => '2026-07-15'])
        ->assertRedirect();

    expect(DB::table('asistencias')->where('ci', '5')->count())->toBe(1);
});

test('la sincronización redirige con error si el equipo no responde', function () {
    Http::fake([
        'microservicio.test/device/attendance*' => Http::response(['detail' => 'No se pudo conectar con el equipo'], 503),
    ]);

    $equipo = Equipo::factory()->create();

    $this->post(route('equipos.marcaciones.sincronizar', $equipo))
        ->assertRedirect()
        ->assertSessionHas('error', 'No se pudo conectar con el equipo');
});

test('un usuario sin permiso no puede sincronizar a la BD', function () {
    $this->actingAs(User::factory()->create());

    $equipo = Equipo::factory()->create();

    $this->post(route('equipos.marcaciones.sincronizar', $equipo))->assertForbidden();
});

test('el modal del listado ofrece descargar y enviar a la BD por rango', function () {
    $equipo = Equipo::factory()->create();

    $this->get(route('equipos.index'))
        ->assertOk()
        ->assertSee(route('equipos.marcaciones.sincronizar', $equipo), escape: false)
        // Cada acción del modal dice qué hace, para no confundir bajar un
        // archivo con grabar las marcaciones en la base.
        ->assertSee('Enviar a la base del SIA')
        ->assertSee('Registra las marcaciones en el sistema. No baja ningún archivo.')
        ->assertSee('Baja un archivo a tu computadora. No modifica nada.');
});

test('limpiar marcaciones le pide al microservicio vaciar el buffer del equipo', function () {
    Http::fake([
        'microservicio.test/device/attendance/clear*' => Http::response(['en_linea' => true, 'limpiado' => true], 200),
    ]);

    $equipo = Equipo::factory()->create(['nombre' => 'iClock Entrada', 'ip' => '192.168.1.90', 'puerto' => 4370]);

    $this->post(route('equipos.marcaciones.limpiar', $equipo), ['motivo' => 'Memoria del equipo llena.'])
        ->assertRedirect()
        ->assertSessionHas('estado', fn (string $mensaje) => str_contains($mensaje, 'iClock Entrada'));

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/device/attendance/clear')
        && str_contains($request->url(), 'ip=192.168.1.90')
        && $request->hasHeader('X-Auth-Token', 'token-de-prueba'));
});

test('limpiar marcaciones no toca la asistencia ya guardada en la BD local', function () {
    Http::fake([
        'microservicio.test/device/attendance/clear*' => Http::response(['limpiado' => true], 200),
    ]);

    DB::table('personas')->insert([
        'ci' => '7633685', 'paterno' => 'Molina', 'materno' => null, 'nombres' => 'Ignacio', 'pinReloj' => '7633685', 'marcaDirecta' => false,
    ]);
    DB::table('asistencias')->insert([
        'ci' => '7633685', 'fecha' => '2026-07-10 00:00:00', 'hora' => '2026-07-10 08:00:00', 'tipo' => 'E',
    ]);

    $this->post(route('equipos.marcaciones.limpiar', Equipo::factory()->create()), ['motivo' => 'Memoria del equipo llena.'])
        ->assertRedirect();

    expect(DB::table('asistencias')->where('ci', '7633685')->count())->toBe(1);
});

test('limpiar marcaciones redirige con error si el equipo no responde', function () {
    Http::fake([
        'microservicio.test/device/attendance/clear*' => Http::response(['detail' => 'No se pudo conectar con el equipo'], 503),
    ]);

    $equipo = Equipo::factory()->create();

    $this->post(route('equipos.marcaciones.limpiar', $equipo), ['motivo' => 'Memoria del equipo llena.'])
        ->assertRedirect()
        ->assertSessionHas('error', fn (string $mensaje) => str_contains($mensaje, 'No se pudo conectar con el equipo'));
});

test('un usuario sin permiso de borrado no puede limpiar las marcaciones del equipo', function () {
    Http::fake();

    $this->actingAs(User::factory()->create());

    $this->post(route('equipos.marcaciones.limpiar', Equipo::factory()->create()))->assertForbidden();

    Http::assertNothingSent();
});

test('no limpia el equipo si no se escribe el motivo', function () {
    Http::fake();

    $this->post(route('equipos.marcaciones.limpiar', Equipo::factory()->create()))
        ->assertSessionHasErrors('motivo');

    // No se le pidió nada al reloj: el motivo se valida antes de tocarlo.
    Http::assertNothingSent();
    expect(EquipoAuditoria::count())->toBe(0);
});

test('la bitácora guarda quién limpió el equipo, por qué y con qué datos', function () {
    Http::fake([
        'microservicio.test/device/attendance/clear*' => Http::response(['limpiado' => true], 200),
    ]);

    $usuario = auth()->user();
    $equipo = Equipo::factory()->create([
        'nombre' => 'iClock Entrada',
        'ip' => '192.168.1.90',
        'puerto' => 4370,
        'ubicacion' => 'Puerta principal',
        'algoritmo' => 'ZMM720_TFT | Ver 6.60',
    ]);

    $this->post(route('equipos.marcaciones.limpiar', $equipo), ['motivo' => 'Memoria del equipo llena.'])
        ->assertRedirect();

    $registro = EquipoAuditoria::sole();

    expect($registro)
        ->accion->toBe(EquipoAuditoria::ACCION_LIMPIAR)
        ->motivo->toBe('Memoria del equipo llena.')
        ->registerUser_id->toBe($usuario->id)
        ->equipo_id->toBe($equipo->id)
        ->exito->toBeTrue();

    // Foto del equipo al momento de la acción, sin la clave del reloj.
    expect($registro->datos_equipo)
        ->toMatchArray([
            'nombre' => 'iClock Entrada',
            'ip' => '192.168.1.90',
            'puerto' => 4370,
            'ubicacion' => 'Puerta principal',
            'algoritmo' => 'ZMM720_TFT | Ver 6.60',
        ])
        ->not->toHaveKey('comm_key');
});

test('la bitácora guarda la baja del equipo con su motivo', function () {
    $equipo = Equipo::factory()->create(['nombre' => 'iClock Bodega']);

    $this->delete(route('equipos.destroy', $equipo), ['deleteObservacion' => 'Equipo quemado por descarga eléctrica.'])
        ->assertRedirect(route('equipos.index'));

    expect(EquipoAuditoria::sole())
        ->accion->toBe(EquipoAuditoria::ACCION_ELIMINAR)
        ->motivo->toBe('Equipo quemado por descarga eléctrica.');

    // El motivo queda también en la propia fila del equipo, vía RegistersUserEvents.
    $this->assertDatabaseHas('equipos', [
        'id' => $equipo->id,
        'deleteObservacion' => 'Equipo quemado por descarga eléctrica.',
        'deleteUser_id' => auth()->id(),
    ]);
});

test('la bitácora guarda quién exportó el CSV y cuántas marcaciones se llevó', function () {
    Http::fake([
        'microservicio.test/device/attendance*' => Http::response([
            'marcaciones' => [
                ['uid' => 1, 'user_id' => '1', 'nombre' => 'Uno', 'timestamp' => '2026-07-10T08:00:00'],
                ['uid' => 2, 'user_id' => '2', 'nombre' => 'Dos', 'timestamp' => '2026-07-11T08:00:00'],
            ],
        ], 200),
    ]);

    $equipo = Equipo::factory()->create();

    $this->get(route('equipos.marcaciones.exportar', ['equipo' => $equipo, 'desde' => '2026-07-01', 'hasta' => '2026-07-15']))
        ->assertOk();

    expect(EquipoAuditoria::sole())
        ->accion->toBe(EquipoAuditoria::ACCION_EXPORTAR)
        ->registerUser_id->toBe(auth()->id())
        ->total_marcaciones->toBe(2)
        ->desde->toBe('2026-07-01')
        ->hasta->toBe('2026-07-15')
        ->exito->toBeTrue();
});

test('la bitácora guarda quién envió las marcaciones a la BD y el resultado', function () {
    DB::table('personas')->insert([
        'ci' => '7633685', 'paterno' => 'Molina', 'materno' => null, 'nombres' => 'Ignacio', 'pinReloj' => '7633685', 'marcaDirecta' => false,
    ]);

    Http::fake([
        'microservicio.test/device/attendance*' => Http::response([
            'marcaciones' => [
                ['uid' => 1, 'user_id' => '7633685', 'nombre' => 'NN', 'timestamp' => '2026-07-10T08:00:00'],
            ],
        ], 200),
    ]);

    $this->post(route('equipos.marcaciones.sincronizar', Equipo::factory()->create()))->assertRedirect();

    expect(EquipoAuditoria::sole())
        ->accion->toBe(EquipoAuditoria::ACCION_SINCRONIZAR)
        ->registerUser_id->toBe(auth()->id())
        ->total_marcaciones->toBe(1)
        ->detalle->toContain('1 marcación(es) nueva(s)');
});

test('la bitácora también deja constancia de los intentos fallidos', function () {
    Http::fake([
        'microservicio.test/device/attendance/clear*' => Http::response(['detail' => 'No se pudo conectar con el equipo'], 503),
    ]);

    $this->post(route('equipos.marcaciones.limpiar', Equipo::factory()->create()), ['motivo' => 'Memoria del equipo llena.'])
        ->assertRedirect();

    expect(EquipoAuditoria::sole())
        ->exito->toBeFalse()
        ->detalle->toContain('No se pudo conectar con el equipo');
});

test('la pantalla de bitácora muestra usuario, acción, equipo y motivo', function () {
    Http::fake([
        'microservicio.test/device/attendance/clear*' => Http::response(['limpiado' => true], 200),
    ]);

    $equipo = Equipo::factory()->create(['nombre' => 'iClock Entrada', 'ip' => '192.168.1.90']);

    $this->post(route('equipos.marcaciones.limpiar', $equipo), ['motivo' => 'Memoria del equipo llena.'])
        ->assertSessionHas('estado');

    $this->get(route('equipos.auditoria'))
        ->assertOk()
        ->assertSee(auth()->user()->name)
        ->assertSee('Limpió el equipo')
        ->assertSee('iClock Entrada')
        ->assertSee('192.168.1.90')
        ->assertSee('Memoria del equipo llena.');
});

test('la bitácora se puede filtrar por acción', function () {
    // Se comparan por el nombre del equipo y no por la etiqueta de la acción:
    // el select de filtros ya trae todas las etiquetas en la página.
    EquipoAuditoria::factory()->limpieza()->create([
        'datos_equipo' => ['nombre' => 'Equipo Limpiado', 'ip' => '10.0.0.1', 'puerto' => 4370],
    ]);
    EquipoAuditoria::factory()->create([
        'datos_equipo' => ['nombre' => 'Equipo Exportado', 'ip' => '10.0.0.2', 'puerto' => 4370],
    ]);

    $this->get(route('equipos.auditoria', ['accion' => EquipoAuditoria::ACCION_LIMPIAR]))
        ->assertOk()
        ->assertSee('Equipo Limpiado')
        ->assertDontSee('Equipo Exportado');
});

test('la bitácora sigue mostrando los datos de un equipo ya eliminado', function () {
    $equipo = Equipo::factory()->create(['nombre' => 'iClock Retirado', 'ip' => '192.168.1.99']);

    $this->delete(route('equipos.destroy', $equipo), ['deleteObservacion' => 'Equipo retirado de la oficina.']);

    $this->get(route('equipos.auditoria'))
        ->assertOk()
        // El equipo ya no está en el listado, pero la foto guardada sí.
        ->assertSee('iClock Retirado')
        ->assertSee('192.168.1.99');
});

test('un usuario sin permiso no puede ver la bitácora', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('equipos.auditoria'))->assertForbidden();
});

test('el listado ofrece limpiar las marcaciones con confirmación escrita', function () {
    $equipo = Equipo::factory()->create();

    $this->get(route('equipos.index'))
        ->assertOk()
        ->assertSee(route('equipos.marcaciones.limpiar', $equipo), escape: false)
        // Rotulada como borrado, no como «limpieza»: destruye información.
        ->assertSee('Borrar marcaciones')
        ->assertSee('dropdown-menu__peligro')
        ->assertSee('Escribí LIMPIAR para confirmar');
});

test('el componente Alpine del dropdown no se escapa del atributo x-data', function () {
    Equipo::factory()->create();

    $html = $this->get(route('equipos.index'))->assertOk()->getContent();

    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);

    // Una sola comilla doble dentro de x-data="{ ... }" corta el atributo: el
    // resto del JS deja de ser código y se dibuja como texto en la tabla.
    // Acá se mira el texto renderizado, no el HTML crudo, para detectarlo.
    $textoVisible = $dom->textContent;

    expect($textoVisible)
        ->not->toContain('revokeObjectURL')
        ->not->toContain('$refs.formLimpiar')
        ->not->toContain('this.respaldando');
});

test('el modal de limpieza descarga el respaldo completo antes de borrar', function () {
    $equipo = Equipo::factory()->create(['nombre' => 'iClock Entrada']);

    $this->get(route('equipos.index'))
        ->assertOk()
        // El botón dispara el respaldo, no el submit directo del formulario.
        ->assertSee('respaldarYLimpiar()', escape: false)
        ->assertSee('Descargar todo y borrar')
        // Nombre del CSV anunciado al usuario: "respaldo" + equipo + fecha de hoy.
        ->assertSee('respaldo-iclock-entrada-'.now()->toDateString().'.csv');
});

test('el respaldo previo al borrado pide el historial completo, sin rango', function () {
    Http::fake([
        'microservicio.test/device/attendance*' => Http::response([
            'marcaciones' => [
                ['uid' => 1, 'user_id' => '1', 'nombre' => 'Empleado', 'timestamp' => '2026-07-10T08:00:00'],
            ],
        ], 200),
    ]);

    // Es la misma URL que usa el modal de limpieza: sin desde/hasta trae todo.
    $this->get(route('equipos.marcaciones.exportar', Equipo::factory()->create()))->assertOk();

    Http::assertSent(fn ($request) => ! str_contains($request->url(), 'desde=')
        && ! str_contains($request->url(), 'hasta='));
});

test('guarda las horas de sincronización automática del equipo', function () {
    $this->post(route('equipos.store'), [
        'nombre' => 'iClock Portón',
        'ip' => '192.168.1.90',
        'puerto' => 4370,
        'comm_key' => 0,
        'activo' => '1',
        'sync_automatica' => '1',
        'sync_horarios' => ['08:30', '13:00', '19:00'],
    ])->assertRedirect(route('equipos.index'));

    $equipo = Equipo::query()->where('ip', '192.168.1.90')->first();

    expect($equipo->sync_automatica)->toBeTrue()
        ->and($equipo->horariosSync())->toBe(['08:30', '13:00', '19:00']);
});

test('encender la sincronización automática exige al menos una hora', function () {
    $this->post(route('equipos.store'), [
        'nombre' => 'iClock Portón',
        'ip' => '192.168.1.91',
        'puerto' => 4370,
        'comm_key' => 0,
        'sync_automatica' => '1',
        // El repetidor manda una fila vacía cuando el usuario no cargó ninguna.
        'sync_horarios' => [''],
    ])->assertSessionHasErrors('sync_horarios');

    $this->assertDatabaseMissing('equipos', ['ip' => '192.168.1.91']);
});

test('rechaza una hora de sincronización que no es una hora', function () {
    $this->post(route('equipos.store'), [
        'nombre' => 'iClock Portón',
        'ip' => '192.168.1.92',
        'puerto' => 4370,
        'comm_key' => 0,
        'sync_automatica' => '1',
        'sync_horarios' => ['25:99'],
    ])->assertSessionHasErrors('sync_horarios.0');
});

test('se pueden cambiar las horas de un equipo ya registrado', function () {
    $equipo = Equipo::factory()->create([
        'sync_automatica' => true,
        'sync_horarios' => ['08:30'],
    ]);

    $this->put(route('equipos.update', $equipo), [
        'nombre' => $equipo->nombre,
        'ip' => $equipo->ip,
        'puerto' => $equipo->puerto,
        'comm_key' => $equipo->comm_key,
        'activo' => '1',
        'sync_automatica' => '1',
        'sync_horarios' => ['07:00', '15:30'],
    ])->assertRedirect(route('equipos.index'));

    expect($equipo->fresh()->horariosSync())->toBe(['07:00', '15:30']);
});

test('apagar la sincronización automática no exige horas', function () {
    $equipo = Equipo::factory()->create([
        'sync_automatica' => true,
        'sync_horarios' => ['08:30'],
    ]);

    $this->put(route('equipos.update', $equipo), [
        'nombre' => $equipo->nombre,
        'ip' => $equipo->ip,
        'puerto' => $equipo->puerto,
        'comm_key' => $equipo->comm_key,
        'activo' => '1',
        'sync_horarios' => [],
    ])->assertRedirect(route('equipos.index'));

    expect($equipo->fresh()->sync_automatica)->toBeFalse();
});

test('el formulario ofrece configurar el horario de sincronización', function () {
    $this->get(route('equipos.create'))
        ->assertOk()
        ->assertSee('Sincronizar automáticamente en días y horas fijas')
        ->assertSee('name="sync_horarios[]"', escape: false);
});

test('la ficha muestra las horas configuradas', function () {
    $equipo = Equipo::factory()->create([
        'sync_automatica' => true,
        'sync_horarios' => ['08:30', '19:00'],
    ]);

    $this->get(route('equipos.show', $equipo))
        ->assertOk()
        ->assertSee('08:30 · 19:00');
});

test('guarda los días de sincronización automática del equipo', function () {
    $this->post(route('equipos.store'), [
        'nombre' => 'iClock Portón',
        'ip' => '192.168.1.93',
        'puerto' => 4370,
        'comm_key' => 0,
        'activo' => '1',
        'sync_automatica' => '1',
        'sync_horarios' => ['08:30'],
        // Lunes a viernes, con la numeración de `Turno::DIAS`.
        'sync_dias' => ['2', '3', '4', '5', '6'],
    ])->assertRedirect(route('equipos.index'));

    $equipo = Equipo::query()->where('ip', '192.168.1.93')->first();

    expect($equipo->diasSync())->toBe([2, 3, 4, 5, 6])
        ->and($equipo->nombresDiasSync())->toBe(['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes']);
});

test('sin días marcados el equipo queda sincronizando todos los días', function () {
    $this->post(route('equipos.store'), [
        'nombre' => 'iClock Portón',
        'ip' => '192.168.1.94',
        'puerto' => 4370,
        'comm_key' => 0,
        'activo' => '1',
        'sync_automatica' => '1',
        'sync_horarios' => ['08:30'],
    ])->assertRedirect(route('equipos.index'));

    $equipo = Equipo::query()->where('ip', '192.168.1.94')->first();

    // Lista vacía significa «todos»: el domingo también le toca.
    expect($equipo->diasSync())->toBe([])
        ->and($equipo->tocaSincronizar(Carbon::parse('2026-07-12 08:30:00')))->toBeTrue();
});

test('rechaza un día de sincronización que no es un día de la semana', function () {
    $this->post(route('equipos.store'), [
        'nombre' => 'iClock Portón',
        'ip' => '192.168.1.95',
        'puerto' => 4370,
        'comm_key' => 0,
        'sync_automatica' => '1',
        'sync_horarios' => ['08:30'],
        'sync_dias' => ['9'],
    ])->assertSessionHasErrors('sync_dias.0');

    $this->assertDatabaseMissing('equipos', ['ip' => '192.168.1.95']);
});

test('se pueden cambiar los días de un equipo ya registrado', function () {
    $equipo = Equipo::factory()->create([
        'sync_automatica' => true,
        'sync_horarios' => ['08:30'],
        'sync_dias' => [2, 3, 4, 5, 6],
    ]);

    $this->put(route('equipos.update', $equipo), [
        'nombre' => $equipo->nombre,
        'ip' => $equipo->ip,
        'puerto' => $equipo->puerto,
        'comm_key' => $equipo->comm_key,
        'activo' => '1',
        'sync_automatica' => '1',
        'sync_horarios' => ['08:30'],
        'sync_dias' => ['7'], // solo sábado
    ])->assertRedirect(route('equipos.index'));

    expect($equipo->fresh()->diasSync())->toBe([7]);
});

test('el formulario ofrece elegir los días de sincronización', function () {
    $this->get(route('equipos.create'))
        ->assertOk()
        ->assertSee('Días de sincronización')
        ->assertSee('name="sync_dias[]"', escape: false)
        ->assertSee('Miércoles');
});

test('la ficha muestra los días y las horas configuradas', function () {
    $equipo = Equipo::factory()->create([
        'sync_automatica' => true,
        'sync_horarios' => ['08:30', '19:00'],
        'sync_dias' => [2, 6],
    ]);

    $this->get(route('equipos.show', $equipo))
        ->assertOk()
        ->assertSee('Lunes, Viernes')
        ->assertSee('08:30 · 19:00');
});

test('la ficha dice «todos los días» cuando no hay días elegidos', function () {
    $equipo = Equipo::factory()->create([
        'sync_automatica' => true,
        'sync_horarios' => ['08:30'],
        'sync_dias' => null,
    ]);

    $this->get(route('equipos.show', $equipo))
        ->assertOk()
        ->assertSee('Todos los días');
});
