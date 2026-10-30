<?php

use App\Models\AsignacionHorario;
use App\Models\ConfiguracionPlazoLicencia;
use App\Models\Licencia;
use App\Models\Persona;
use App\Models\SistemaExterno;
use App\Services\PlazoLicencias;
use Illuminate\Support\Carbon;

/*
| Plazo para registrar licencias: hasta cuántos días atrás y adelante de hoy se
| puede pedir una. Se carga en Configuración con vigencia por día, y lo
| controlan las dos puertas de alta: la API por la que pide Mamoré y la
| pantalla de Recursos Humanos.
|
| Hoy es miércoles 28/10/2026. Con 3 días corridos atrás y 7 adelante, se puede
| pedir del 25/10 al 04/11.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-28');
});

/**
 * Plazo de 3 días atrás y 7 adelante, vigente desde el 01/10.
 */
function plazoTresYSiete(): ConfiguracionPlazoLicencia
{
    return ConfiguracionPlazoLicencia::factory()->create([
        'dias_atraso' => 3, 'dias_anticipacion' => 7, 'vigente_desde' => '2026-10-01',
    ]);
}

/**
 * Guarda un plazo por la pantalla de Configuración.
 */
function guardarPlazo(array $datos = [])
{
    return test()->put(route('configuracion.plazo-licencias.update'), $datos + [
        'dias_atraso' => 3,
        'dias_anticipacion' => 7,
        'dias_habiles' => 0,
        'plazo_desde' => '2026-10-28',
        'plazo_motivo' => 'Instructivo RR.HH. 012/2026',
    ]);
}

/**
 * 111 tiene horario de lunes a viernes todo el año.
 */
function horarioTodoElAnio(): void
{
    foreach (range(2, 6) as $dia) {
        AsignacionHorario::factory()->create([
            'ci' => '111', 'horario_id' => horario($dia)->id, 'desde' => '2026-01-01', 'hasta' => '2026-12-31',
        ]);
    }
}

/**
 * Alta de un funcionario desde la pantalla «Licenciar», de horario completo.
 */
function licenciarDesdeSismark(string $desde, string $hasta, array $extra = [])
{
    return test()->post(route('licencias.store'), $extra + [
        'modo' => 'uno', 'ci' => '111', 'desde' => $desde, 'hasta' => $hasta,
        'tipo' => Licencia::TIPO_INSTITUCIONAL, 'tCompleto' => 1, 'goceHaberes' => 1, 'motivo' => 'Salud',
    ]);
}

/**
 * Solicitud del funcionario por la API, como la manda Mamoré.
 */
function pedirDesdeMamore(string $desde, string $hasta)
{
    $mamore = SistemaExterno::create(['slug' => 'mamore', 'nombre' => 'Mamoré', 'activo' => true]);

    return test()
        ->withToken($mamore->createToken('mamore', ['licencias:read', 'licencias:write'])->plainTextToken)
        ->postJson('/api/v1/funcionarios/111/licencias', [
            'desde' => $desde, 'hasta' => $hasta, 'motivo' => 'Cita médica', 'tCompleto' => true,
        ]);
}

// --- Configuración ---------------------------------------------------------

test('se carga desde hoy, con motivo y quién lo cargó, y rige en adelante', function (): void {
    $admin = superAdmin();
    $this->actingAs($admin);

    guardarPlazo()->assertSessionHasNoErrors()->assertRedirect(route('configuracion.edit').'#plazo-licencias');

    $plazo = ConfiguracionPlazoLicencia::sole();

    expect($plazo->dias_atraso)->toBe(3)
        ->and($plazo->dias_anticipacion)->toBe(7)
        ->and($plazo->dias_habiles)->toBeFalse()
        ->and($plazo->getRawOriginal('vigente_desde'))->toBe('2026-10-28')
        ->and($plazo->vigente_hasta)->toBeNull()
        ->and($plazo->motivo)->toBe('Instructivo RR.HH. 012/2026')
        ->and($plazo->registerUser_id)->toBe($admin->id)
        ->and(ConfiguracionPlazoLicencia::vigente(Carbon::parse('2027-06-01'))->is($plazo))->toBeTrue()
        ->and(ConfiguracionPlazoLicencia::vigente(Carbon::parse('2026-10-27')))->toBeNull();
});

test('el motivo es obligatorio y no se carga desde un día que ya pasó', function (array $datos, string $campo): void {
    $this->actingAs(superAdmin());

    guardarPlazo($datos)->assertSessionHasErrors($campo);

    expect(ConfiguracionPlazoLicencia::count())->toBe(0);
})->with([
    'sin motivo' => [['plazo_motivo' => ''], 'plazo_motivo'],
    'desde ayer' => [['plazo_desde' => '2026-10-27'], 'plazo_desde'],
    'días negativos' => [['dias_atraso' => -1], 'dias_atraso'],
    'más de un año' => [['dias_anticipacion' => 367], 'dias_anticipacion'],
]);

test('cargar el siguiente cierra el anterior el día previo', function (): void {
    $this->actingAs(superAdmin());

    guardarPlazo()->assertSessionHasNoErrors();
    guardarPlazo(['dias_atraso' => 5, 'dias_habiles' => 1, 'plazo_desde' => '2026-11-10'])->assertSessionHasNoErrors();

    [$nuevo, $anterior] = ConfiguracionPlazoLicencia::query()->orderByDesc('vigente_desde')->get()->all();

    expect($anterior->vigente_hasta->toDateString())->toBe('2026-11-09')
        ->and($nuevo->vigente_hasta)->toBeNull()
        ->and($nuevo->dias_habiles)->toBeTrue()
        // Cada día se cuenta con el plazo que regía ese día.
        ->and(ConfiguracionPlazoLicencia::vigente(Carbon::parse('2026-11-09'))->dias_atraso)->toBe(3)
        ->and(ConfiguracionPlazoLicencia::vigente(Carbon::parse('2026-11-10'))->dias_atraso)->toBe(5);
});

test('una carga entre medio queda cerrada antes de la programada', function (): void {
    $this->actingAs(superAdmin());

    guardarPlazo(['plazo_desde' => '2026-12-01'])->assertSessionHasNoErrors();
    guardarPlazo(['plazo_desde' => '2026-11-01'])->assertSessionHasNoErrors();

    expect(ConfiguracionPlazoLicencia::vigente(Carbon::parse('2026-11-01'))->vigente_hasta->toDateString())->toBe('2026-11-30');
});

test('hoy ya cargado no se reescribe; lo programado sí se reemplaza sin duplicar', function (): void {
    $this->actingAs(superAdmin());

    guardarPlazo()->assertSessionHasNoErrors();
    guardarPlazo(['dias_atraso' => 10])->assertSessionHasErrors('plazo_desde');

    guardarPlazo(['plazo_desde' => '2026-11-15'])->assertSessionHasNoErrors();
    guardarPlazo(['plazo_desde' => '2026-11-15', 'dias_atraso' => 4, 'plazo_motivo' => 'Corrección'])->assertSessionHasNoErrors();

    expect(ConfiguracionPlazoLicencia::count())->toBe(2)
        ->and(ConfiguracionPlazoLicencia::vigente()->dias_atraso)->toBe(3)
        ->and(ConfiguracionPlazoLicencia::vigente(Carbon::parse('2026-11-15'))->dias_atraso)->toBe(4)
        ->and(ConfiguracionPlazoLicencia::vigente(Carbon::parse('2026-11-15'))->motivo)->toBe('Corrección');
});

test('la pantalla muestra el plazo de hoy y su historial', function (): void {
    $this->actingAs(superAdmin());
    plazoTresYSiete();

    $this->get(route('configuracion.edit'))
        ->assertOk()
        ->assertSee('Plazo para registrar licencias')
        ->assertSee('Rige hoy: Se puede registrar del domingo 25/10/2026 al miércoles 04/11/2026.')
        // El ejemplo arranca con los valores vigentes; el navegador lo recalcula al escribir.
        ->assertSee('Ejemplo con la fecha de hoy (miércoles 28/10/2026): se puede registrar del domingo 25/10/2026 al miércoles 04/11/2026.')
        ->assertSee('3 días corridos')
        ->assertSee('Vigente');
});

test('si la carga rebota, el ejemplo muestra lo que se había escrito', function (): void {
    $this->actingAs(superAdmin());

    $this->from(route('configuracion.edit'))
        ->followingRedirects()
        ->put(route('configuracion.plazo-licencias.update'), [
            'dias_atraso' => 3, 'dias_anticipacion' => 5, 'dias_habiles' => 1,
            'plazo_desde' => '2026-10-28', 'plazo_motivo' => '',
        ])
        ->assertSee('se puede registrar del viernes 23/10/2026 al miércoles 04/11/2026.');
});

test('cargar el plazo pide el permiso de Configuración', function (): void {
    $this->actingAs(usuarioCon('Create:Licencia'));

    guardarPlazo()->assertForbidden();

    expect(ConfiguracionPlazoLicencia::count())->toBe(0);
});

// --- La regla ----------------------------------------------------------------

test('sin plazo cargado no frena nada', function (): void {
    expect(app(PlazoLicencias::class)->motivoFueraDePlazo(Carbon::parse('2025-01-01'), Carbon::parse('2027-12-31')))->toBeNull()
        ->and(app(PlazoLicencias::class)->resumen())->toBeNull();
});

test('los bordes del plazo entran y un día más no', function (string $desde, string $hasta, ?string $dice): void {
    plazoTresYSiete();

    $motivo = app(PlazoLicencias::class)->motivoFueraDePlazo(Carbon::parse($desde), Carbon::parse($hasta));

    $dice === null
        ? expect($motivo)->toBeNull()
        : expect($motivo)->toContain($dice);
})->with([
    'hoy' => ['2026-10-28', '2026-10-28', null],
    'justo 3 días atrás' => ['2026-10-25', '2026-10-25', null],
    'justo 7 días adelante' => ['2026-11-04', '2026-11-04', null],
    'el rango entero en el borde' => ['2026-10-25', '2026-11-04', null],
    '4 días atrás' => ['2026-10-24', '2026-10-24', 'de hace más de 3 días corridos: hoy se puede pedir desde el domingo 25/10/2026'],
    'un rango que empieza antes' => ['2026-10-20', '2026-10-28', 'de hace más de 3 días corridos'],
    '8 días adelante' => ['2026-11-05', '2026-11-05', 'con más de 7 días corridos de anticipación: hoy se puede pedir hasta el miércoles 04/11/2026'],
    'un rango que termina después' => ['2026-10-28', '2026-11-30', 'de anticipación'],
]);

test('cuenta el plazo del día en que se pide, no el de las fechas licenciadas', function (): void {
    plazoTresYSiete();
    // Desde noviembre se afloja a 30 días atrás: hoy todavía no rige.
    ConfiguracionPlazoLicencia::guardar(['dias_atraso' => 30, 'dias_anticipacion' => 7, 'dias_habiles' => false], Carbon::parse('2026-11-01'), 'Afloja');

    $plazo = app(PlazoLicencias::class);

    expect($plazo->motivoFueraDePlazo(Carbon::parse('2026-10-10'), Carbon::parse('2026-10-10')))->not->toBeNull()
        ->and($plazo->motivoFueraDePlazo(Carbon::parse('2026-10-10'), Carbon::parse('2026-10-10'), Carbon::parse('2026-11-02')))->toBeNull();
});

// --- Desde Mamoré (API) --------------------------------------------------------

test('la API rechaza lo pedido fuera de plazo y no anota nada', function (): void {
    plazoTresYSiete();
    horarioTodoElAnio();

    pedirDesdeMamore('2026-10-13', '2026-10-13')
        ->assertUnprocessable()
        ->assertJsonPath('message', 'No se puede registrar una licencia de hace más de 3 días corridos: hoy se puede pedir desde el domingo 25/10/2026.');

    expect(Licencia::count())->toBe(0);
});

test('la API rechaza lo pedido con demasiada anticipación', function (): void {
    plazoTresYSiete();
    horarioTodoElAnio();

    pedirDesdeMamore('2026-11-30', '2026-11-30')
        ->assertUnprocessable()
        ->assertJsonPath('message', fn (string $mensaje): bool => str_contains($mensaje, 'de anticipación'));

    expect(Licencia::count())->toBe(0);
});

test('la API anota lo pedido dentro del plazo', function (): void {
    plazoTresYSiete();
    horarioTodoElAnio();

    pedirDesdeMamore('2026-10-26', '2026-10-26')->assertCreated();

    expect(Licencia::where('ci', '111')->count())->toBe(1);
});

// --- Desde SisMark -------------------------------------------------------------

test('Recursos Humanos tampoco anota fuera de plazo', function (): void {
    plazoTresYSiete();
    horarioTodoElAnio();
    $this->actingAs(usuarioCon('Create:Licencia'));

    licenciarDesdeSismark('2026-10-13', '2026-10-13')
        ->assertSessionHasErrors(['desde' => 'No se puede registrar una licencia de hace más de 3 días corridos: hoy se puede pedir desde el domingo 25/10/2026.']);

    expect(Licencia::count())->toBe(0);
});

test('marcar la casilla sin el permiso no sirve', function (): void {
    plazoTresYSiete();
    horarioTodoElAnio();
    $this->actingAs(usuarioCon('Create:Licencia'));

    licenciarDesdeSismark('2026-10-13', '2026-10-13', ['fueraDePlazo' => 1])->assertSessionHasErrors('desde');

    expect(Licencia::count())->toBe(0);
});

test('con el permiso hay que marcar la casilla: ni el Administrador lo pasa sin querer', function (string $quien): void {
    plazoTresYSiete();
    horarioTodoElAnio();
    $this->actingAs($quien === 'admin' ? superAdmin() : usuarioCon('Create:Licencia', 'Override:Licencia'));

    licenciarDesdeSismark('2026-10-13', '2026-10-13')
        ->assertSessionHasErrors(['desde' => 'No se puede registrar una licencia de hace más de 3 días corridos: hoy se puede pedir desde el domingo 25/10/2026. Si corresponde igual, marcá «Registrar fuera de plazo».']);

    expect(Licencia::count())->toBe(0);

    licenciarDesdeSismark('2026-10-13', '2026-10-13', ['fueraDePlazo' => 1])->assertSessionHasNoErrors();

    expect(Licencia::where('ci', '111')->where('fecha', 'like', '2026-10-13%')->count())->toBe(1);
})->with(['admin', 'permiso']);

test('dentro del plazo Recursos Humanos anota sin marcar nada', function (): void {
    plazoTresYSiete();
    horarioTodoElAnio();
    $this->actingAs(usuarioCon('Create:Licencia'));

    licenciarDesdeSismark('2026-10-26', '2026-10-30')->assertSessionHasNoErrors();

    expect(Licencia::where('ci', '111')->count())->toBe(5);
});

test('el formulario avisa el plazo y ofrece la casilla solo a quien puede usarla', function (): void {
    plazoTresYSiete();

    $this->actingAs(usuarioCon('Create:Licencia', 'ViewAny:Licencia'))
        ->get(route('licencias.create'))
        ->assertOk()
        ->assertSee('Se puede registrar del domingo 25/10/2026 al miércoles 04/11/2026.')
        ->assertDontSee('Registrar fuera de plazo');

    $this->actingAs(usuarioCon('Create:Licencia', 'ViewAny:Licencia', 'Override:Licencia'))
        ->get(route('licencias.create'))
        ->assertSee('Registrar fuera de plazo');
});

test('el modal de la ficha también avisa el plazo y ofrece la casilla', function (): void {
    plazoTresYSiete();
    $persona = Persona::factory()->create(['ci' => '7633685']);

    $this->actingAs(superAdmin())
        ->get(route('funcionarios.show', $persona))
        ->assertOk()
        ->assertSee('Se puede registrar del domingo 25/10/2026 al miércoles 04/11/2026.')
        ->assertSee('Registrar fuera de plazo');
});

test('la lista de licencias de la API trae el plazo de hoy para el calendario de Mamoré', function (bool $hayPlazo): void {
    if ($hayPlazo) {
        plazoTresYSiete();
    }

    $mamore = SistemaExterno::create(['slug' => 'mamore', 'nombre' => 'Mamoré', 'activo' => true]);

    $respuesta = $this->withToken($mamore->createToken('mamore', ['licencias:read'])->plainTextToken)
        ->getJson('/api/v1/funcionarios/111/licencias?desde=2026-10-01&hasta=2026-10-31')
        ->assertOk();

    $hayPlazo
        ? $respuesta->assertJsonPath('plazo', [
            'desde' => '2026-10-25',
            'hasta' => '2026-11-04',
            'diasHabiles' => false,
            'mensaje' => 'Se puede registrar del domingo 25/10/2026 al miércoles 04/11/2026.',
        ])
        : $respuesta->assertJsonPath('plazo', null);
})->with(['con plazo' => true, 'sin plazo' => false]);

test('un plazo con un solo límite deja el otro lado en null', function (): void {
    ConfiguracionPlazoLicencia::factory()->create([
        'dias_atraso' => 0, 'dias_anticipacion' => 7, 'vigente_desde' => '2026-10-01',
    ]);

    expect(app(PlazoLicencias::class)->paraApi())->toMatchArray(['desde' => null, 'hasta' => '2026-11-04']);
});
