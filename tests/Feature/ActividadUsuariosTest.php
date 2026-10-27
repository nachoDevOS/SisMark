<?php

use App\Models\Actividad;
use App\Models\Persona;
use App\Models\SistemaExterno;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;

/*
| Actividad de usuarios (docs/ACTIVIDAD-DE-USUARIOS.md): cada pedido queda
| registrado, los importantes con su frase, y nunca una contraseña ni un token.
*/

function ingresar(string $email, string $password): TestResponse
{
    return test()->post(route('login'), ['email' => $email, 'password' => $password]);
}

test('entrar y salir quedan registrados', function (): void {
    $usuario = User::factory()->create(['email' => 'ana@beni.gob.bo', 'password' => 'secreta123']);

    ingresar('ana@beni.gob.bo', 'secreta123')->assertRedirect();
    $this->post(route('logout'));

    expect(Actividad::where('accion', Actividad::INGRESO)->where('user_id', $usuario->id)->exists())->toBeTrue()
        ->and(Actividad::where('accion', Actividad::SALIDA)->where('user_id', $usuario->id)->exists())->toBeTrue();
});

test('un intento fallido guarda el correo pero nunca la contraseña', function (): void {
    User::factory()->create(['email' => 'ana@beni.gob.bo', 'password' => 'secreta123']);

    ingresar('ana@beni.gob.bo', 'equivocada-999');

    $fallido = Actividad::where('accion', Actividad::INGRESO_FALLIDO)->sole();

    expect($fallido->email)->toBe('ana@beni.gob.bo')
        ->and($fallido->descripcion)->toContain('contraseña incorrecta')
        ->and(Actividad::all()->toJson())->not->toContain('equivocada-999')
        ->and(Actividad::all()->toJson())->not->toContain('secreta123');
});

test('una cuenta inhabilitada queda como ingreso rechazado, no como entrada y salida', function (): void {
    $usuario = User::factory()->create(['email' => 'ana@beni.gob.bo', 'password' => 'secreta123']);
    $usuario->forceFill(['activo' => false])->save();

    ingresar('ana@beni.gob.bo', 'secreta123');

    expect(Actividad::where('accion', Actividad::INGRESO_RECHAZADO)->exists())->toBeTrue()
        ->and(Actividad::where('accion', Actividad::INGRESO)->exists())->toBeFalse()
        ->and(Actividad::where('accion', Actividad::SALIDA)->exists())->toBeFalse();
});

test('el sexto intento fallido queda bloqueado y se registra el bloqueo', function (): void {
    User::factory()->create(['email' => 'ana@beni.gob.bo', 'password' => 'secreta123']);

    foreach (range(1, 5) as $intento) {
        ingresar('ana@beni.gob.bo', 'mal');
    }

    // Ni la contraseña correcta entra mientras dura el bloqueo.
    ingresar('ana@beni.gob.bo', 'secreta123')->assertSessionHasErrors(['email' => 'Demasiados intentos fallidos. Probá de nuevo en 15 minuto(s).']);

    expect(Actividad::where('accion', Actividad::BLOQUEO)->exists())->toBeTrue()
        ->and(auth()->check())->toBeFalse();

    RateLimiter::clear('ana@beni.gob.bo|127.0.0.1');
    ingresar('ana@beni.gob.bo', 'secreta123')->assertRedirect(route('dashboard'));
});

test('cambiar la contraseña queda registrado sin la contraseña', function (): void {
    $usuario = User::factory()->create(['password' => 'vieja-12345']);

    $this->actingAs($usuario)->put(route('password.cambiar.update'), [
        'password_actual' => 'vieja-12345',
        'password' => 'nueva-67890',
        'password_confirmation' => 'nueva-67890',
    ]);

    expect(Actividad::where('accion', Actividad::CAMBIO_PASSWORD)->where('user_id', $usuario->id)->exists())->toBeTrue()
        ->and(Actividad::all()->toJson())->not->toContain('nueva-67890')
        ->and(Actividad::all()->toJson())->not->toContain('vieja-12345');
});

test('todo pedido queda registrado, con su estado y duración', function (): void {
    $usuario = usuarioCon('ViewAny:Persona');

    $this->actingAs($usuario)->get(route('funcionarios.index'))->assertOk();

    $pedido = Actividad::where('ruta', 'funcionarios.index')->sole();

    expect($pedido->user_id)->toBe($usuario->id)
        ->and($pedido->importante)->toBeFalse()
        ->and($pedido->accion)->toBe(Actividad::PEDIDO)
        ->and($pedido->modulo)->toBe('Funcionarios')
        ->and($pedido->estado)->toBe(200)
        ->and($pedido->metodo)->toBe('GET')
        ->and($pedido->url)->toBe('/funcionarios');
});

test('buscar en Funcionarios es importante y guarda lo que se escribió', function (): void {
    $this->actingAs(usuarioCon('ViewAny:Persona'))
        ->get(route('funcionarios.list', ['q' => '7633685', 'fuente' => 'sia']));

    $busqueda = Actividad::where('accion', Actividad::BUSCAR)->sole();

    expect($busqueda->importante)->toBeTrue()
        ->and($busqueda->descripcion)->toBe('Buscó «7633685» en Funcionarios')
        ->and($busqueda->datos['q'])->toBe('7633685');
});

test('listar sin buscar queda solo como detalle', function (): void {
    $this->actingAs(usuarioCon('ViewAny:Persona'))->get(route('funcionarios.list', ['fuente' => 'sia']));

    expect(Actividad::where('importante', true)->exists())->toBeFalse()
        ->and(Actividad::where('ruta', 'funcionarios.list')->exists())->toBeTrue();
});

test('cada reporte dice si se generó, se imprimió o se bajó en Excel', function (int $print, string $accion, string $verbo): void {
    $this->actingAs(usuarioCon('ViewAny:Reporte', 'Export:Reporte'))
        ->get(route('reportes.funcionarios.sin-turno.generar', ['print' => $print]));

    $evento = Actividad::where('importante', true)->first();

    // Sin Mamoré el reporte puede no salir: si salió mal, no es un evento.
    if ($evento !== null) {
        expect($evento->accion)->toBe($accion)
            ->and($evento->descripcion)->toStartWith($verbo);
    } else {
        expect(Actividad::where('ruta', 'reportes.funcionarios.sin-turno.generar')->value('estado'))->toBeGreaterThanOrEqual(300);
    }
})->with([
    'en pantalla' => [0, Actividad::REPORTE, 'Generó'],
    'imprimible' => [1, Actividad::IMPRIMIR, 'Imprimió'],
    'Excel' => [2, Actividad::EXPORTAR, 'Bajó en Excel'],
]);

test('una ficha que no existe no se «miró»: queda como detalle con su error', function (): void {
    $this->actingAs(usuarioCon('ViewAny:Persona', 'View:Persona'))->get('/funcionarios/no-existe-999');

    expect(Actividad::where('accion', Actividad::VER)->exists())->toBeFalse()
        ->and(Actividad::where('url', '/funcionarios/no-existe-999')->value('estado'))->toBe(404);
});

test('las claves sensibles se tapan en cualquier nivel y también en la URL', function (): void {
    $this->actingAs(usuarioCon('ViewAny:Persona'))
        ->get('/funcionarios?api_token=abc123&filtro[clave_secreta]=xyz&q=hola');

    $pedido = Actividad::latest('id')->first();

    expect($pedido->datos['api_token'])->toBe('••••')
        ->and($pedido->datos['filtro']['clave_secreta'])->toBe('••••')
        ->and($pedido->datos['q'])->toBe('hola')
        ->and($pedido->url)->not->toContain('abc123')
        ->and($pedido->url)->not->toContain('xyz');
});

test('el chequeo de salud /up no se registra', function (): void {
    $this->get('/up');

    expect(Actividad::count())->toBe(0);
});

test('mirar la pantalla de actividad no se registra', function (): void {
    $this->actingAs(usuarioCon('ViewAny:Actividad'));

    $this->get(route('actividades.index'))->assertOk();
    $this->get(route('actividades.index', ['todo' => 1, 'page' => 2]))->assertOk();

    expect(Actividad::where('ruta', 'actividades.index')->exists())->toBeFalse();
});

test('un pedido de la API guarda la CI de la ruta aunque sea detalle', function (): void {
    $mamore = SistemaExterno::create(['slug' => 'mamore', 'nombre' => 'Mamoré', 'activo' => true]);

    $this->withToken($mamore->createToken('mamore', ['horarios:read'])->plainTextToken)
        ->getJson('/api/v1/funcionarios/7633685/horarios');

    $pedido = Actividad::where('sistema_externo_id', $mamore->id)->sole();

    expect($pedido->importante)->toBeFalse()
        ->and($pedido->ci)->toBe('7633685');
});

test('las pestañas de la ficha guardan la CI de la query', function (): void {
    $this->actingAs(usuarioCon('ViewAny:Persona', 'View:Persona'))
        ->get(route('funcionarios.marcaciones.list', ['ci' => '7633685']));

    expect(Actividad::where('ruta', 'funcionarios.marcaciones.list')->value('ci'))->toBe('7633685');
});

test('la pantalla muestra el nombre del funcionario junto a la CI', function (): void {
    config(['services.mamore.url' => null]);
    Persona::factory()->create(['ci' => '7633685', 'paterno' => 'MOLINA', 'materno' => 'GUZMAN', 'nombres' => 'IGNACIO']);
    Actividad::factory()->detalle()->create(['ci' => '7633685', 'url' => '/api/v1/funcionarios/7633685/horarios']);

    $this->actingAs(usuarioCon('ViewAny:Actividad'))
        ->get(route('actividades.index', ['todo' => 1]))
        ->assertOk()
        ->assertSee('MOLINA')
        ->assertSee('CI 7633685');
});

test('sin nombre queda la CI, y lo registrado antes la toma de la URL', function (): void {
    config(['services.mamore.url' => null]);
    Actividad::factory()->detalle()->create(['ci' => null, 'url' => '/api/v1/funcionarios/5596473/asistencia?desde=2026-10-01']);

    $this->actingAs(usuarioCon('ViewAny:Actividad'))
        ->get(route('actividades.index', ['todo' => 1]))
        ->assertOk()
        ->assertSee('CI 5596473');
});

test('si Mamoré no responde la pantalla igual sale', function (): void {
    config(['services.mamore.url' => 'https://mamore.test/api', 'services.mamore.token' => 'token']);
    Http::fake(['*' => Http::response(null, 500)]);
    Actividad::factory()->detalle()->create(['ci' => '7633685', 'url' => '/api/v1/funcionarios/7633685/horarios']);

    $this->actingAs(usuarioCon('ViewAny:Actividad'))
        ->get(route('actividades.index', ['todo' => 1]))
        ->assertOk()
        ->assertSee('CI 7633685');
});

test('la actividad no se edita ni se borra', function (): void {
    $actividad = Actividad::factory()->create();

    expect(fn () => $actividad->update(['descripcion' => 'otra cosa']))->toThrow(LogicException::class)
        ->and(fn () => $actividad->delete())->toThrow(LogicException::class)
        ->and(Actividad::find($actividad->id)->descripcion)->toStartWith('Miró la ficha');
});

test('la pantalla arranca en lo importante de hoy y «Ver todo» suma el detalle', function (): void {
    Actividad::factory()->create(['descripcion' => 'Miró la ficha del funcionario CI 7633685']);
    Actividad::factory()->detalle()->create(['url' => '/funcionarios/ajax/list?page=3']);

    $usuario = usuarioCon('ViewAny:Actividad');

    $this->actingAs($usuario)->get(route('actividades.index'))
        ->assertOk()
        ->assertSee('Miró la ficha del funcionario CI 7633685')
        ->assertDontSee('/funcionarios/ajax/list?page=3');

    $this->actingAs($usuario)->get(route('actividades.index', ['todo' => 1]))
        ->assertOk()
        ->assertSee('/funcionarios/ajax/list?page=3');
});

test('se filtra por CI y por intentos fallidos', function (): void {
    Actividad::factory()->create(['ci' => '7633685', 'descripcion' => 'Miró la ficha del funcionario CI 7633685']);
    Actividad::factory()->create(['ci' => '10799999', 'descripcion' => 'Miró la ficha del funcionario CI 10799999']);
    Actividad::factory()->create(['accion' => Actividad::INGRESO_FALLIDO, 'ci' => null, 'descripcion' => 'Intento de ingreso fallido con x@y.z']);

    $usuario = usuarioCon('ViewAny:Actividad');

    $this->actingAs($usuario)->get(route('actividades.index', ['ci' => '7633685']))
        ->assertSee('CI 7633685')
        ->assertDontSee('CI 10799999');

    $this->actingAs($usuario)->get(route('actividades.index', ['fallidos' => 1]))
        ->assertSee('Intento de ingreso fallido con x@y.z')
        ->assertDontSee('CI 7633685');
});

test('se filtra por método', function (): void {
    Actividad::factory()->detalle()->create(['metodo' => 'GET', 'url' => '/funcionarios/ajax/list?page=7']);
    Actividad::factory()->detalle()->create(['metodo' => 'PUT', 'url' => '/api/v1/funcionarios/12438528/turnos']);

    $this->actingAs(usuarioCon('ViewAny:Actividad'))
        ->get(route('actividades.index', ['todo' => 1, 'metodo' => 'PUT']))
        ->assertOk()
        ->assertSee('/api/v1/funcionarios/12438528/turnos')
        ->assertDontSee('/funcionarios/ajax/list?page=7');
});

test('sin el permiso no se ve', function (): void {
    $this->actingAs(usuarioCon('ViewAny:Persona'))->get(route('actividades.index'))->assertForbidden();
});
