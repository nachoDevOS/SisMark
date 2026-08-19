<?php

use App\Models\Asistencia;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    fakeSiaDatabase();
    $this->actingAs(asSuperAdmin());
});

test('el listado muestra las marcaciones del rango por defecto', function () {
    DB::table('personas')->insert([
        'ci' => '777', 'paterno' => 'Diaz', 'materno' => null, 'nombres' => 'Eva', 'pinReloj' => null, 'marcaDirecta' => false,
    ]);
    DB::table('asistencias')->insert([
        'ci' => '777',
        'fecha' => now()->startOfDay()->toDateTimeString(),
        'hora' => now()->toDateTimeString(),
        'tipo' => 'R',
    ]);

    $this->get(route('marcaciones.list'))
        ->assertOk()
        ->assertSee('Diaz')
        ->assertSee('777');
});

test('el rango de fechas excluye lo que queda fuera', function () {
    DB::table('personas')->insert([
        'ci' => '888', 'paterno' => 'Vieja', 'materno' => null, 'nombres' => 'Marca', 'pinReloj' => null, 'marcaDirecta' => false,
    ]);
    DB::table('asistencias')->insert([
        'ci' => '888',
        'fecha' => now()->subYears(2)->toDateTimeString(),
        'hora' => now()->toDateTimeString(),
        'tipo' => 'R',
    ]);

    // El rango por defecto arranca en el mes actual: la marcación de hace 2 años queda fuera.
    $this->get(route('marcaciones.list'))
        ->assertOk()
        ->assertSee('Sin marcaciones en el rango seleccionado');
});

test('un invitado no puede ver marcaciones', function () {
    auth()->logout();

    $this->get(route('marcaciones.index'))->assertRedirect();
});

test('un usuario sin permiso no puede ver marcaciones', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('marcaciones.index'))->assertForbidden();
});

test('busca marcaciones por apellido del funcionario', function () {
    DB::table('personas')->insert([
        ['ci' => '1', 'paterno' => 'Zabaleta', 'materno' => null, 'nombres' => 'Ana', 'pinReloj' => null, 'marcaDirecta' => false],
        ['ci' => '2', 'paterno' => 'Quiroga', 'materno' => null, 'nombres' => 'Beto', 'pinReloj' => null, 'marcaDirecta' => false],
    ]);
    DB::table('asistencias')->insert([
        ['ci' => '1', 'fecha' => now()->toDateString(), 'hora' => now()->toDateTimeString(), 'tipo' => 'R'],
        ['ci' => '2', 'fecha' => now()->toDateString(), 'hora' => now()->toDateTimeString(), 'tipo' => 'R'],
    ]);

    $this->get(route('marcaciones.list', ['q' => 'Zabaleta']))
        ->assertOk()
        ->assertSee('Zabaleta')
        ->assertDontSee('Quiroga');
});

test('busca marcaciones por nombre y apellido combinados', function () {
    DB::table('personas')->insert([
        ['ci' => '1', 'paterno' => 'Molina', 'materno' => 'Guzman', 'nombres' => 'Ignacio', 'pinReloj' => null, 'marcaDirecta' => false],
        ['ci' => '2', 'paterno' => 'Perez', 'materno' => 'Rojas', 'nombres' => 'Ignacio', 'pinReloj' => null, 'marcaDirecta' => false],
    ]);
    DB::table('asistencias')->insert([
        ['ci' => '1', 'fecha' => now()->toDateString(), 'hora' => now()->toDateTimeString(), 'tipo' => 'R'],
        ['ci' => '2', 'fecha' => now()->toDateString(), 'hora' => now()->toDateTimeString(), 'tipo' => 'R'],
    ]);

    // "ignacio m" cruza nombres + paterno: encuentra a Ignacio Molina y deja
    // fuera a Ignacio Perez.
    $this->get(route('marcaciones.list', ['q' => 'ignacio m']))
        ->assertOk()
        ->assertSee('Molina')
        ->assertDontSee('Perez');
});

test('busca marcaciones por CI del funcionario', function () {
    DB::table('personas')->insert([
        ['ci' => '111', 'paterno' => 'Rocabado', 'materno' => null, 'nombres' => 'Ana', 'pinReloj' => null, 'marcaDirecta' => false],
        ['ci' => '222', 'paterno' => 'Salvatierra', 'materno' => null, 'nombres' => 'Beto', 'pinReloj' => null, 'marcaDirecta' => false],
    ]);
    DB::table('asistencias')->insert([
        ['ci' => '111', 'fecha' => now()->toDateString(), 'hora' => now()->toDateTimeString(), 'tipo' => 'R'],
        ['ci' => '222', 'fecha' => now()->toDateString(), 'hora' => now()->toDateTimeString(), 'tipo' => 'R'],
    ]);

    $this->get(route('marcaciones.list', ['q' => '111']))
        ->assertOk()
        ->assertSee('Rocabado')
        ->assertDontSee('Salvatierra');
});

test('filtra por tipo de marcación', function () {
    DB::table('personas')->insert([
        ['ci' => '1', 'paterno' => 'Relojero', 'materno' => null, 'nombres' => 'Ana', 'pinReloj' => null, 'marcaDirecta' => false],
        ['ci' => '2', 'paterno' => 'Manualino', 'materno' => null, 'nombres' => 'Beto', 'pinReloj' => null, 'marcaDirecta' => false],
    ]);
    DB::table('asistencias')->insert([
        ['ci' => '1', 'fecha' => now()->toDateString(), 'hora' => now()->toDateTimeString(), 'tipo' => 'R'],
        ['ci' => '2', 'fecha' => now()->toDateString(), 'hora' => now()->toDateTimeString(), 'tipo' => 'M'],
    ]);

    $this->get(route('marcaciones.list', ['tipo' => 'R']))
        ->assertOk()
        ->assertSee('Relojero')
        ->assertDontSee('Manualino');
});

test('una marcación manual no se pinta con el color de reloj', function () {
    DB::table('personas')->insert([
        'ci' => '333', 'paterno' => 'Manual', 'materno' => null, 'nombres' => 'Uno', 'pinReloj' => null, 'marcaDirecta' => false,
    ]);
    DB::table('asistencias')->insert([
        'ci' => '333',
        'fecha' => now()->toDateString(),
        'hora' => now()->toDateTimeString(),
        'tipo' => 'M',
    ]);

    // El CSS estático del layout siempre define .pill--ok (regla, no dato);
    // se verifica el <span> renderizado puntual, no una búsqueda de substring.
    $this->get(route('marcaciones.list'))
        ->assertOk()
        ->assertSee('<span class="pill pill--advertencia">M</span>', escape: false);
});

test('importa un csv nuevo y crea la marcación en asistencias', function () {
    DB::table('personas')->insert([
        'ci' => '4176235', 'paterno' => 'Perez', 'materno' => null, 'nombres' => 'Juan', 'pinReloj' => '4176235', 'marcaDirecta' => false,
    ]);

    $csv = "\u{FEFF}CI/ID,Nombre,Fecha,Hora\n4176235,\"Perez Juan\",15/07/2026,08:05:00\n";
    $archivo = UploadedFile::fake()->createWithContent('marcaciones.csv', $csv);

    $this->post(route('marcaciones.importar'), ['archivo' => $archivo])
        ->assertRedirect()
        ->assertSessionHas('estado', fn (string $mensaje) => str_contains($mensaje, '1 marcación(es) nueva(s)'));

    expect(DB::table('asistencias')->where('ci', '4176235')->count())->toBe(1);
});

test('no duplica una marcación que ya existe en asistencias', function () {
    DB::table('personas')->insert([
        'ci' => '555', 'paterno' => 'Gomez', 'materno' => null, 'nombres' => 'Ana', 'pinReloj' => '555', 'marcaDirecta' => false,
    ]);
    DB::table('asistencias')->insert([
        'ci' => '555',
        'fecha' => '2026-07-15 00:00:00',
        'hora' => '1899-12-30 08:05:00',
        'tipo' => 'R',
    ]);

    $csv = "CI/ID,Nombre,Fecha,Hora\n555,\"Gomez Ana\",15/07/2026,08:05:00\n";
    $archivo = UploadedFile::fake()->createWithContent('marcaciones.csv', $csv);

    $this->post(route('marcaciones.importar'), ['archivo' => $archivo])
        ->assertRedirect()
        ->assertSessionHas('estado', fn (string $mensaje) => str_contains($mensaje, '0 marcación(es) nueva(s)') && str_contains($mensaje, '1 ya existían'));

    expect(DB::table('asistencias')->where('ci', '555')->count())->toBe(1);
});

test('una fila sin funcionario en el padrón se guarda igual y queda contada aparte', function () {
    $csv = "CI/ID,Nombre,Fecha,Hora\n999999,\"Sin Registro\",15/07/2026,08:05:00\n";
    $archivo = UploadedFile::fake()->createWithContent('marcaciones.csv', $csv);

    $this->post(route('marcaciones.importar'), ['archivo' => $archivo])
        ->assertRedirect()
        ->assertSessionHas('estado', fn (string $mensaje) => str_contains($mensaje, '1 guardada(s) sin funcionario en el padrón'));

    // La marcación es un hecho: alguien marcó. Que el padrón esté
    // desactualizado no puede borrarla, porque se perdería para siempre en
    // cuanto se limpie el reloj. Cuando ese carnet entre a `personas`, la
    // marcación aparece sola: el vínculo se resuelve en la consulta.
    expect(DB::table('asistencias')->where('ci', '999999')->count())->toBe(1);
});

test('la marcación huérfana aparece en la ficha del funcionario cuando se lo da de alta', function () {
    $csv = "CI/ID,Nombre,Fecha,Hora\n888888,\"Sin Registro\",15/07/2026,08:05:00\n";
    $archivo = UploadedFile::fake()->createWithContent('marcaciones.csv', $csv);

    $this->post(route('marcaciones.importar'), ['archivo' => $archivo])->assertRedirect();

    // Todavía no está en el padrón: la marcación existe pero no cruza con nadie.
    expect(Asistencia::query()->where('ci', '888888')->whereHas('persona')->count())->toBe(0);

    DB::table('personas')->insert([
        'ci' => '888888', 'paterno' => 'Nuevo', 'materno' => null, 'nombres' => 'Ingreso',
        'pinReloj' => '888888', 'marcaDirecta' => false,
    ]);

    // Sin correr ningún reproceso: el alta del funcionario alcanza.
    expect(Asistencia::query()->where('ci', '888888')->whereHas('persona')->count())->toBe(1);
});

test('importa un csv reguardado desde Excel con separador punto y coma', function () {
    DB::table('personas')->insert([
        'ci' => '4176235', 'paterno' => 'Perez', 'materno' => null, 'nombres' => 'Juan', 'pinReloj' => '4176235', 'marcaDirecta' => false,
    ]);

    $csv = "CI/ID;Nombre;Fecha;Hora\n4176235;\"Perez Juan\";15/07/2026;08:05:00\n";
    $archivo = UploadedFile::fake()->createWithContent('marcaciones.csv', $csv);

    $this->post(route('marcaciones.importar'), ['archivo' => $archivo])
        ->assertRedirect()
        ->assertSessionHas('estado', fn (string $mensaje) => str_contains($mensaje, '1 marcación(es) nueva(s)'));

    expect(DB::table('asistencias')->where('ci', '4176235')->count())->toBe(1);
});

test('importa filas con la hora sin segundos', function () {
    DB::table('personas')->insert([
        'ci' => '999', 'paterno' => 'Sinseg', 'materno' => null, 'nombres' => 'Test', 'pinReloj' => '999', 'marcaDirecta' => false,
    ]);

    $csv = "CI/ID,Nombre,Fecha,Hora\n999,\"Sinseg Test\",15/07/2026,08:05\n";
    $archivo = UploadedFile::fake()->createWithContent('marcaciones.csv', $csv);

    $this->post(route('marcaciones.importar'), ['archivo' => $archivo])
        ->assertRedirect()
        ->assertSessionHas('estado', fn (string $mensaje) => str_contains($mensaje, '1 marcación(es) nueva(s)'));

    expect(DB::table('asistencias')->where('ci', '999')->count())->toBe(1);
});

test('una fila con fecha basura futura del reloj (RTC) se descarta y no rompe el import', function () {
    DB::table('personas')->insert([
        'ci' => '7655482', 'paterno' => 'Torrez', 'materno' => null, 'nombres' => 'Rene', 'pinReloj' => '7655482', 'marcaDirecta' => false,
    ]);

    $csv = "CI/ID,Nombre,Fecha,Hora\n7655482,\"Torrez Rene\",19/08/2103,02:52:58\n";
    $archivo = UploadedFile::fake()->createWithContent('marcaciones.csv', $csv);

    $this->post(route('marcaciones.importar'), ['archivo' => $archivo])
        ->assertRedirect()
        ->assertSessionHas('estado', fn (string $mensaje) => str_contains($mensaje, '0 marcación(es) nueva(s)') && str_contains($mensaje, '1 fila(s) inválida(s)'));

    expect(DB::table('asistencias')->count())->toBe(0);
});

test('la columna funcionario usa el nombre de Mamoré cuando existe', function () {
    config()->set('services.mamore.url', 'http://mamore.test/api/personal');
    config()->set('services.mamore.key', 'secreta');

    Http::fake([
        'mamore.test/api/personal/people/ci/*' => Http::response([
            'data' => ['id' => 1, 'ci' => '777', 'full_name' => 'MARIELA CRUZ PORCO'],
        ], 200),
    ]);

    // Existe también localmente, pero Mamoré tiene prioridad.
    DB::table('personas')->insert([
        'ci' => '777', 'paterno' => 'Diaz', 'materno' => null, 'nombres' => 'Eva', 'pinReloj' => null, 'marcaDirecta' => false,
    ]);
    DB::table('asistencias')->insert([
        'ci' => '777', 'fecha' => today(), 'hora' => '1899-12-30 08:00:00', 'tipo' => 'R',
    ]);

    $this->get(route('marcaciones.list'))
        ->assertOk()
        ->assertSee('MARIELA CRUZ PORCO')
        ->assertDontSee('Eva Diaz');
});

test('la columna funcionario muestra el cargo que informa Mamoré', function () {
    fakeMamore(['777' => ['nombre' => 'MARIELA CRUZ PORCO', 'cargo' => 'Analista II', 'direccion' => 'SDAF']]);

    DB::table('asistencias')->insert([
        'ci' => '777', 'fecha' => today(), 'hora' => '1899-12-30 08:00:00', 'tipo' => 'R',
    ]);

    $this->get(route('marcaciones.list'))
        ->assertOk()
        ->assertSee('MARIELA CRUZ PORCO')
        ->assertSee('Analista II');
});

test('la columna funcionario cae a la BD local si no está en Mamoré', function () {
    config()->set('services.mamore.url', 'http://mamore.test/api/personal');
    config()->set('services.mamore.key', 'secreta');

    Http::fake(['mamore.test/*' => Http::response(['message' => 'not found'], 404)]);

    DB::table('personas')->insert([
        'ci' => '888', 'paterno' => 'Roca', 'materno' => null, 'nombres' => 'Luis', 'pinReloj' => null, 'marcaDirecta' => false,
    ]);
    DB::table('asistencias')->insert([
        'ci' => '888', 'fecha' => today(), 'hora' => '1899-12-30 08:00:00', 'tipo' => 'R',
    ]);

    $this->get(route('marcaciones.list'))
        ->assertOk()
        ->assertSee('Roca');
});

test('la columna funcionario muestra «Sin persona» si el CI no está en ningún sistema', function () {
    config()->set('services.mamore.url', 'http://mamore.test/api/personal');
    config()->set('services.mamore.key', 'secreta');

    Http::fake(['mamore.test/*' => Http::response(['message' => 'not found'], 404)]);

    // Marcación de un CI que no existe ni en Mamoré ni en personas local.
    DB::table('asistencias')->insert([
        'ci' => '999999', 'fecha' => today(), 'hora' => '1899-12-30 08:00:00', 'tipo' => 'R',
    ]);

    $this->get(route('marcaciones.list'))
        ->assertOk()
        ->assertSee('Sin persona');
});

test('registra una marcación manual de tipo M', function () {
    DB::table('personas')->insert([
        'ci' => '888', 'paterno' => 'Roca', 'materno' => null, 'nombres' => 'Luis', 'pinReloj' => null, 'marcaDirecta' => false,
    ]);

    $this->post(route('marcaciones.store'), [
        'ci' => '888', 'fecha' => '2026-07-20', 'hora' => '08:30',
        'observacion' => 'Papeleta firmada por el jefe de unidad.',
    ])
        ->assertRedirect(route('marcaciones.index'))
        ->assertSessionHas('estado');

    $marcacion = DB::table('asistencias')->where('ci', '888')->first();

    expect($marcacion)->not->toBeNull()
        ->and(trim($marcacion->tipo))->toBe('M')
        ->and($marcacion->fecha)->toContain('2026-07-20')
        ->and($marcacion->hora)->toContain('08:30:00')
        ->and($marcacion->observacion)->toBe('Papeleta firmada por el jefe de unidad.');
});

test('la marcación manual exige el motivo', function () {
    DB::table('personas')->insert([
        'ci' => '888', 'paterno' => 'Roca', 'materno' => null, 'nombres' => 'Luis', 'pinReloj' => null, 'marcaDirecta' => false,
    ]);

    $this->post(route('marcaciones.store'), ['ci' => '888', 'fecha' => '2026-07-20', 'hora' => '08:30'])
        ->assertSessionHasErrors('observacion');

    // Un motivo de dos letras no explica nada: se pide un mínimo.
    $this->post(route('marcaciones.store'), [
        'ci' => '888', 'fecha' => '2026-07-20', 'hora' => '08:30', 'observacion' => 'ok',
    ])->assertSessionHasErrors('observacion');

    expect(DB::table('asistencias')->count())->toBe(0);
});

test('el modal de marcación manual pide el motivo', function () {
    $this->get(route('marcaciones.index'))
        ->assertOk()
        ->assertSee('name="observacion" rows="2" required', escape: false);
});

test('registrada desde la ficha, la marcación vuelve a la ficha', function () {
    DB::table('personas')->insert([
        'ci' => '888', 'paterno' => 'Roca', 'materno' => null, 'nombres' => 'Luis', 'pinReloj' => null, 'marcaDirecta' => false,
    ]);

    $this->post(route('marcaciones.store'), [
        'ci' => '888', 'fecha' => '2026-07-20', 'hora' => '08:30', 'origen' => 'local',
        'observacion' => 'Olvidó marcar la entrada.',
    ])->assertRedirect(route('funcionarios.show', ['persona' => '888']));

    $this->post(route('marcaciones.store'), [
        'ci' => '888', 'fecha' => '2026-07-20', 'hora' => '09:30', 'origen' => 'mamore',
        'observacion' => 'Olvidó marcar la entrada.',
    ])->assertRedirect(route('funcionarios.mamore', ['ci' => '888']));

    // Un origen desconocido no saca al usuario del sistema.
    $this->post(route('marcaciones.store'), [
        'ci' => '888', 'fecha' => '2026-07-20', 'hora' => '10:30', 'origen' => 'https://otro-sitio.test',
        'observacion' => 'Olvidó marcar la entrada.',
    ])->assertRedirect(route('marcaciones.index'));
});

test('el modal de marcación manual está en el listado y en la ficha del funcionario', function () {
    DB::table('personas')->insert([
        'ci' => '888', 'paterno' => 'Roca', 'materno' => null, 'nombres' => 'Luis', 'pinReloj' => null, 'marcaDirecta' => false,
    ]);

    // En el listado el CI se escribe a mano.
    $this->get(route('marcaciones.index'))
        ->assertOk()
        ->assertSee('Nueva marcación')
        ->assertSee('Nueva marcación manual')
        ->assertSee('id="ci-global"', escape: false);

    // En la ficha el funcionario ya viene dado y el origen hace que se vuelva ahí.
    $this->get(route('funcionarios.show', ['persona' => '888']))
        ->assertOk()
        ->assertSee('Nueva marcación manual')
        ->assertSee('<input type="hidden" name="ci" value="888">', escape: false)
        ->assertSee('<input type="hidden" name="origen" value="local">', escape: false)
        ->assertDontSee('id="ci-global"', escape: false);
});

test('un usuario sin permiso de crear no ve el modal de marcación manual', function () {
    DB::table('personas')->insert([
        'ci' => '888', 'paterno' => 'Roca', 'materno' => null, 'nombres' => 'Luis', 'pinReloj' => null, 'marcaDirecta' => false,
    ]);

    foreach (['ViewAny:Asistencia', 'ViewAny:Persona', 'View:Persona'] as $permiso) {
        Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']);
    }

    $rol = Role::create(['name' => 'solo_lectura_marcaciones', 'guard_name' => 'web']);
    $rol->givePermissionTo('ViewAny:Asistencia', 'ViewAny:Persona', 'View:Persona');

    $this->actingAs(User::factory()->create()->assignRole($rol));

    $this->get(route('marcaciones.index'))
        ->assertOk()
        ->assertDontSee('Nueva marcación manual');

    $this->get(route('funcionarios.show', ['persona' => '888']))
        ->assertOk()
        ->assertDontSee('Nueva marcación manual');
});

test('la marcación manual valida CI, fecha y hora', function () {
    $this->post(route('marcaciones.store'), ['ci' => '', 'fecha' => '', 'hora' => ''])
        ->assertSessionHasErrors(['ci', 'fecha', 'hora']);

    expect(DB::table('asistencias')->count())->toBe(0);
});

test('la marcación manual rechaza un CI que no es de ningún funcionario', function () {
    $this->post(route('marcaciones.store'), ['ci' => '000', 'fecha' => '2026-07-20', 'hora' => '08:30'])
        ->assertSessionHasErrors('ci');

    expect(DB::table('asistencias')->count())->toBe(0);
});

test('la marcación manual no duplica la misma ci, fecha y hora', function () {
    DB::table('personas')->insert([
        'ci' => '888', 'paterno' => 'Roca', 'materno' => null, 'nombres' => 'Luis', 'pinReloj' => null, 'marcaDirecta' => false,
    ]);
    DB::table('asistencias')->insert([
        'ci' => '888', 'fecha' => '2026-07-20 00:00:00', 'hora' => '1899-12-30 08:30:00', 'tipo' => 'M',
    ]);

    $this->post(route('marcaciones.store'), [
        'ci' => '888', 'fecha' => '2026-07-20', 'hora' => '08:30',
        'observacion' => 'Se vuelve a cargar por las dudas.',
    ])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(DB::table('asistencias')->where('ci', '888')->count())->toBe(1);
});

test('un usuario sin permiso no puede registrar una marcación manual', function () {
    $this->actingAs(User::factory()->create());

    $this->post(route('marcaciones.store'), ['ci' => '888', 'fecha' => '2026-07-20', 'hora' => '08:30'])
        ->assertForbidden();
});

test('un usuario sin permiso de crear marcaciones no puede importar', function () {
    $this->actingAs(User::factory()->create());

    $archivo = UploadedFile::fake()->createWithContent('marcaciones.csv', "CI/ID,Nombre,Fecha,Hora\n");

    $this->post(route('marcaciones.importar'), ['archivo' => $archivo])->assertForbidden();
});

test('un invitado no puede importar marcaciones', function () {
    auth()->logout();

    $archivo = UploadedFile::fake()->createWithContent('marcaciones.csv', "CI/ID,Nombre,Fecha,Hora\n");

    $this->post(route('marcaciones.importar'), ['archivo' => $archivo])->assertRedirect();
});

test('importar CSV va antes que el alta manual en la cabecera', function () {
    $this->get(route('marcaciones.index'))
        ->assertOk()
        ->assertSeeInOrder(['Importar CSV', 'Nueva marcación']);
});

test('el import explica el formato del archivo antes de elegirlo', function () {
    $this->get(route('marcaciones.index'))
        ->assertOk()
        ->assertSee('Importar marcaciones desde un CSV')
        // De dónde sale el archivo y un ejemplo de sus columnas.
        ->assertSee('Descargar CSV')
        ->assertSee('CI/ID,Nombre,Fecha,Hora')
        // El campo va dentro del modal, no suelto en la cabecera.
        ->assertSee('id="archivo-csv"', escape: false);
});

test('el modal del import se reabre solo con el error si el archivo no sirve', function () {
    $archivo = UploadedFile::fake()->create('marcaciones.pdf', 10, 'application/pdf');

    // `from()` es lo que hace que la validación rebote al listado, como en el
    // navegador: sin referer, Laravel manda a la raíz.
    $this->from(route('marcaciones.index'))
        ->post(route('marcaciones.importar'), ['archivo' => $archivo])
        ->assertRedirect(route('marcaciones.index'))
        ->assertSessionHasErrors('archivo');

    $this->from(route('marcaciones.index'))
        ->followingRedirects()
        ->post(route('marcaciones.importar'), ['archivo' => $archivo])
        ->assertOk()
        // `abierto: true` deja el modal abierto con el error a la vista.
        ->assertSee('x-data="{ abierto: true, archivo: \'\' }"', escape: false)
        ->assertSee('debe ser un archivo de tipo: csv, txt');
});

test('el origen de cada marcación se explica con palabras, no solo con la letra', function () {
    DB::table('personas')->insert([
        ['ci' => '1', 'paterno' => 'Relojero', 'materno' => null, 'nombres' => 'Ana', 'pinReloj' => null, 'marcaDirecta' => false],
        ['ci' => '2', 'paterno' => 'Legado', 'materno' => null, 'nombres' => 'Beto', 'pinReloj' => null, 'marcaDirecta' => false],
    ]);
    DB::table('asistencias')->insert([
        ['ci' => '1', 'fecha' => now()->toDateString(), 'hora' => now()->toDateTimeString(), 'tipo' => 'R'],
        ['ci' => '2', 'fecha' => now()->toDateString(), 'hora' => now()->toDateTimeString(), 'tipo' => 'A'],
    ]);

    // En la tabla: la letra que guarda la base, más su significado al lado.
    $this->get(route('marcaciones.list'))
        ->assertOk()
        ->assertSee('Origen')
        ->assertSee('<span class="pill pill--ok">R</span>', escape: false)
        ->assertSee('Reloj')
        ->assertSee('Sin identificar');

    // Y en el filtro, cada opción dice qué es.
    $this->get(route('marcaciones.index'))
        ->assertOk()
        ->assertSee('R · Reloj')
        ->assertSee('M · Manual')
        ->assertSee('A · Sin identificar')
        // Los dos campos de fecha dejan de ser dos cajas sin nombre.
        ->assertSeeInOrder(['Desde', 'Hasta', 'Origen']);
});

test('un usuario sin permiso de crear no ve la acción de importar', function () {
    foreach (['ViewAny:Asistencia'] as $permiso) {
        Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']);
    }

    $rol = Role::create(['name' => 'solo_lectura_import', 'guard_name' => 'web']);
    $rol->givePermissionTo('ViewAny:Asistencia');

    $this->actingAs(User::factory()->create()->assignRole($rol));

    // Antes eran dos acciones; el alta manual se desactivó el 2026-08-13.
    $this->get(route('marcaciones.index'))
        ->assertOk()
        ->assertDontSee('Importar CSV');
});

test('el rango incluye los dos días extremos y deja fuera los vecinos', function () {
    DB::table('personas')->insert([
        'ci' => '888', 'paterno' => 'Borde', 'materno' => null, 'nombres' => 'Ana', 'pinReloj' => null, 'marcaDirecta' => false,
    ]);

    foreach (['2026-07-31' => '07:00:00', '2026-08-01' => '08:00:00', '2026-08-04' => '09:00:00', '2026-08-05' => '10:00:00'] as $dia => $hora) {
        DB::table('asistencias')->insert([
            'ci' => '888', 'fecha' => $dia.' 00:00:00', 'hora' => '1899-12-30 '.$hora, 'tipo' => 'R',
        ]);
    }

    $marcaciones = Asistencia::query()->enRango('2026-08-01', '2026-08-04')->pluck('fecha');

    expect($marcaciones)->toHaveCount(2)
        ->and($marcaciones->map(fn ($fecha): string => $fecha->toDateString())->all())
        ->toBe(['2026-08-01', '2026-08-04']);
});

test('el rango filtra sin envolver la columna en una función', function () {
    // `whereDate()` genera `date(fecha) >= ?`, que anula el índice (fecha, ci) y
    // manda a MySQL a recorrer los 4,4 millones de filas: 8,6 s por página del
    // listado contra 15 ms, y el count(*) de la paginación, 5,9 s contra 0,5 ms.
    $sql = Asistencia::query()->enRango('2026-08-01', '2026-08-04')->toSql();

    expect($sql)->not->toContain('date(')
        ->and($sql)->toContain('"fecha" >=')
        ->and($sql)->toContain('"fecha" <');

    // El corte de arriba va por el día siguiente, para que entre todo el día `hasta`.
    expect(cortesDelRango('2026-08-01', '2026-08-04'))
        ->toBe(['2026-08-01 00:00:00', '2026-08-05 00:00:00']);
});

test('el rango deja pasar los extremos vacíos', function () {
    expect(cortesDelRango('', ''))->toBe([])
        ->and(cortesDelRango(null, null))->toBe([])
        ->and(cortesDelRango('2026-08-01', ''))->toBe(['2026-08-01 00:00:00']);
});

test('el listado no envuelve la fecha en una función al paginar', function () {
    DB::table('personas')->insert([
        'ci' => '888', 'paterno' => 'Perez', 'materno' => null, 'nombres' => 'Ana', 'pinReloj' => null, 'marcaDirecta' => false,
    ]);
    DB::table('asistencias')->insert([
        'ci' => '888', 'fecha' => now()->startOfDay()->toDateTimeString(),
        'hora' => '1899-12-30 08:00:00', 'tipo' => 'R',
    ]);

    // La consulta del listado y el count(*) de la paginación son dos consultas
    // distintas: las dos tienen que quedar sargables, no solo la primera.
    $consultas = [];
    DB::listen(function ($query) use (&$consultas): void {
        $consultas[] = $query->sql;
    });

    $this->get(route('marcaciones.list'))->assertOk()->assertSee('888');

    $sobreAsistencias = array_filter($consultas, fn (string $sql): bool => str_contains($sql, '"asistencias"'));

    expect($sobreAsistencias)->not->toBeEmpty()
        ->and(array_filter($sobreAsistencias, fn (string $sql): bool => str_contains($sql, 'date(')))->toBeEmpty()
        ->and(array_filter($sobreAsistencias, fn (string $sql): bool => str_contains($sql, 'count(')))->not->toBeEmpty();
});

/**
 * Los cortes de fecha que arma el scope, como los ve la base. Van al query como
 * objetos Carbon —el grammar los formatea al ejecutar—, así que se comparan
 * formateados y no contra el objeto.
 *
 * @return list<string>
 */
function cortesDelRango(?string $desde, ?string $hasta): array
{
    return array_map(
        fn ($corte): string => $corte instanceof DateTimeInterface ? $corte->format('Y-m-d H:i:s') : (string) $corte,
        Asistencia::query()->enRango($desde, $hasta)->getBindings()
    );
}
