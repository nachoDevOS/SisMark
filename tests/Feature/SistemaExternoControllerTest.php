<?php

use App\Models\SistemaExterno;
use App\Models\SistemaExternoAuditoria;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function sistemaDePrueba(array $sobrescribir = []): SistemaExterno
{
    return SistemaExterno::create(array_merge([
        'slug' => 'mamore',
        'nombre' => 'Mamoré',
    ], $sobrescribir));
}

test('el listado muestra los sistemas registrados', function () {
    sistemaDePrueba();

    $this->actingAs(asSuperAdmin())
        ->get(route('tokens-api.list'))
        ->assertOk()
        ->assertSee('Mamoré')
        ->assertSee('mamore');
});

test('las pantallas del módulo abren', function () {
    $sistema = sistemaDePrueba();
    $sistema->createToken('servicio-mamore', ['turnos:read']);
    $usuario = asSuperAdmin();

    // Las cuatro vistas nuevas: un error de Blade acá no lo atrapa ninguna de
    // las pruebas de comportamiento, que solo miran redirecciones.
    foreach ([
        route('tokens-api.index'),
        route('tokens-api.create'),
        route('tokens-api.show', $sistema),
        route('tokens-api.edit', $sistema),
    ] as $ruta) {
        $this->actingAs($usuario)->get($ruta)->assertOk();
    }
});

test('la ficha muestra el token recién emitido una sola vez', function () {
    $sistema = sistemaDePrueba();
    $usuario = asSuperAdmin();

    $plano = $this->actingAs($usuario)
        ->post(route('tokens-api.tokens.emitir', $sistema), [
            'password' => 'password',
        ])
        ->getSession()
        ->get('token_emitido');

    $this->actingAs($usuario)
        ->get(route('tokens-api.show', $sistema))
        ->assertOk()
        ->assertSee($plano);

    // Recargar ya no lo muestra: vive en el flash porque la base guarda solo su
    // hash y esta era la única oportunidad de leerlo.
    $this->actingAs($usuario)
        ->get(route('tokens-api.show', $sistema))
        ->assertOk()
        ->assertDontSee($plano);
});

test('sin permiso no se entra al listado', function () {
    $this->actingAs(usuarioCon([]))
        ->get(route('tokens-api.index'))
        ->assertForbidden();
});

test('el alta registra el sistema', function () {
    $this->actingAs(asSuperAdmin())
        ->post(route('tokens-api.store'), [
            'slug' => 'sedag',
            'nombre' => 'SEDAG',
            'activo' => '1',
        ])
        ->assertRedirect();

    $sistema = SistemaExterno::query()->where('slug', 'sedag')->firstOrFail();

    expect($sistema->nombre)->toBe('SEDAG')
        ->and($sistema->activo)->toBeTrue();
});

test('el nombre corto no admite mayúsculas ni espacios', function () {
    $this->actingAs(asSuperAdmin())
        ->post(route('tokens-api.store'), ['slug' => 'Sistema Nuevo', 'nombre' => 'Sistema'])
        ->assertSessionHasErrors('slug');
});

test('no se puede repetir el nombre corto de un sistema en pie', function () {
    sistemaDePrueba();

    $this->actingAs(asSuperAdmin())
        ->post(route('tokens-api.store'), ['slug' => 'mamore', 'nombre' => 'Otro'])
        ->assertSessionHasErrors('slug');
});

test('dar de alta un nombre corto dado de baja lo reactiva', function () {
    $sistema = sistemaDePrueba();
    $sistema->delete();

    // El índice único de la tabla no distingue `deleted_at`, así que insertar
    // encima reventaba con «Duplicate entry». Y rechazarlo sería peor: los dados
    // de baja no se listan, así que ese nombre corto quedaría quemado sin
    // ninguna pantalla desde donde recuperarlo.
    $this->actingAs(asSuperAdmin())
        ->post(route('tokens-api.store'), [
            'slug' => 'mamore',
            'nombre' => 'Mamoré otra vez',
            'activo' => '1',
        ])
        ->assertRedirect(route('tokens-api.show', $sistema))
        ->assertSessionHasNoErrors();

    $reactivado = SistemaExterno::query()->where('slug', 'mamore')->firstOrFail();

    expect($reactivado->id)->toBe($sistema->id)
        ->and($reactivado->nombre)->toBe('Mamoré otra vez')
        ->and($reactivado->trashed())->toBeFalse();

    // Y no se duplicó la fila.
    expect(SistemaExterno::withTrashed()->where('slug', 'mamore')->count())->toBe(1);
});

test('reactivar revoca el token que tenía antes de la baja', function () {
    $sistema = sistemaDePrueba();
    $viejo = $sistema->createToken('servicio-mamore', ['turnos:read'])->plainTextToken;
    $sistema->delete();

    $this->actingAs(asSuperAdmin())
        ->post(route('tokens-api.store'), ['slug' => 'mamore', 'nombre' => 'Otro equipo', 'activo' => '1']);

    // Quien da de alta puede ser otro equipo que eligió el mismo nombre corto:
    // heredar la credencial de un consumidor ajeno sería darle acceso sin que
    // nadie lo decidiera.
    expect($sistema->fresh()->tokens()->count())->toBe(0);

    $this->withHeaders(['Authorization' => 'Bearer '.$viejo])
        ->getJson('/api/v1/turnos/sugeridos')
        ->assertUnauthorized();
});

test('la edición no cambia el nombre corto', function () {
    $sistema = sistemaDePrueba();

    // El token entregado quedó bautizado con el slug: cambiarlo lo dejaría
    // apuntando a un nombre que ya no existe.
    $this->actingAs(asSuperAdmin())
        ->put(route('tokens-api.update', $sistema), [
            'slug' => 'otro-nombre',
            'nombre' => 'Mamoré renombrado',
        ])
        ->assertRedirect();

    expect($sistema->fresh()->slug)->toBe('mamore')
        ->and($sistema->fresh()->nombre)->toBe('Mamoré renombrado');
});

test('el interruptor apaga y prende el sistema', function () {
    $sistema = sistemaDePrueba();

    $this->actingAs(asSuperAdmin())->post(route('tokens-api.toggle', $sistema));
    expect($sistema->fresh()->activo)->toBeFalse();

    $this->actingAs(asSuperAdmin())->post(route('tokens-api.toggle', $sistema));
    expect($sistema->fresh()->activo)->toBeTrue();
});

test('la baja no le borra el token', function () {
    $sistema = sistemaDePrueba();
    $sistema->createToken('servicio-mamore', ['turnos:read']);

    $this->actingAs(asSuperAdmin())->delete(route('tokens-api.destroy', $sistema));

    $this->assertSoftDeleted($sistema);

    // La regla de vigencia ya lo rechaza en el próximo pedido; conservar el
    // token deja que reactivarlo lo devuelva a andar sin coordinar una
    // credencial nueva.
    expect(SistemaExterno::query()->find($sistema->id))->toBeNull()
        ->and(SistemaExterno::withTrashed()->findOrFail($sistema->id)->tokens()->count())->toBe(1);
});

test('emitir un token lo muestra una sola vez y lo anota en la bitácora', function () {
    $sistema = sistemaDePrueba();
    $usuario = asSuperAdmin();

    $respuesta = $this->actingAs($usuario)
        ->post(route('tokens-api.tokens.emitir', $sistema), [
            'password' => 'password',
        ]);

    $respuesta->assertRedirect(route('tokens-api.show', $sistema))
        ->assertSessionHas('token_emitido');

    expect($sistema->tokens()->count())->toBe(1);

    $anotado = SistemaExternoAuditoria::query()->where('sistema_externo_id', $sistema->id)->firstOrFail();

    // Los alcances quedan escritos en la fila aunque hoy sean siempre todos: si
    // mañana se agrega uno, la bitácora tiene que seguir diciendo qué se entregó
    // ese día y no lo que la constante diga después.
    expect($anotado->accion)->toBe(SistemaExternoAuditoria::ACCION_EMITIR)
        ->and($anotado->user_id)->toBe($usuario->id)
        ->and($anotado->alcances)->toBe(implode(', ', array_keys(SistemaExterno::ALCANCES)));
});

test('el token emitido entra a toda la API', function () {
    $sistema = sistemaDePrueba();

    $plano = $this->actingAs(asSuperAdmin())
        ->post(route('tokens-api.tokens.emitir', $sistema), [
            'password' => 'password',
        ])
        ->getSession()
        ->get('token_emitido');

    // La prueba de verdad: lo que sale de la pantalla entra por la API.
    $this->withHeaders(['Authorization' => 'Bearer '.$plano])
        ->getJson('/api/v1/turnos/sugeridos')
        ->assertOk();

    // Y llega a las otras áreas, que es lo que cambia respecto de elegir
    // alcances de a uno: la pantalla emite con todo. Un token acotado a
    // `turnos:read` habría cortado acá con 403.
    $this->withHeaders(['Authorization' => 'Bearer '.$plano])
        ->getJson('/api/v1/funcionarios/7633685/marcaciones')
        ->assertOk();
});

test('el token emitido trae todos los alcances', function () {
    $sistema = sistemaDePrueba();

    $this->actingAs(asSuperAdmin())
        ->post(route('tokens-api.tokens.emitir', $sistema), ['password' => 'password']);

    expect($sistema->tokens()->firstOrFail()->abilities)
        ->toBe(array_keys(SistemaExterno::ALCANCES));
});

test('emitir revoca el token anterior', function () {
    $sistema = sistemaDePrueba();
    $viejo = $sistema->createToken('servicio-mamore', ['turnos:read'])->plainTextToken;

    $this->actingAs(asSuperAdmin())
        ->post(route('tokens-api.tokens.emitir', $sistema), [
            'password' => 'password',
        ]);

    expect($sistema->tokens()->count())->toBe(1);

    $this->withHeaders(['Authorization' => 'Bearer '.$viejo])
        ->getJson('/api/v1/turnos/sugeridos')
        ->assertUnauthorized();
});

test('la contraseña equivocada no emite nada', function () {
    $sistema = sistemaDePrueba();

    $this->actingAs(asSuperAdmin())
        ->post(route('tokens-api.tokens.emitir', $sistema), [
            'password' => 'la-que-no-es',
        ])
        ->assertSessionHasErrors('password');

    expect($sistema->tokens()->count())->toBe(0);
});

test('los alcances que llegan por el formulario se ignoran', function () {
    $sistema = sistemaDePrueba();

    // La pantalla ya no los pide, así que un `alcances[]` agregado a mano no
    // puede recortar —ni ampliar— lo que sale: los decide el controlador.
    $this->actingAs(asSuperAdmin())
        ->post(route('tokens-api.tokens.emitir', $sistema), [
            'alcances' => ['turnos:read'],
            'password' => 'password',
        ]);

    expect($sistema->tokens()->firstOrFail()->abilities)
        ->toBe(array_keys(SistemaExterno::ALCANCES));
});

test('un sistema inactivo no recibe token', function () {
    $sistema = sistemaDePrueba(['activo' => false]);

    $this->actingAs(asSuperAdmin())
        ->post(route('tokens-api.tokens.emitir', $sistema), [
            'password' => 'password',
        ]);

    expect($sistema->tokens()->count())->toBe(0);
});

test('poder editar la ficha no habilita a emitir tokens', function () {
    $sistema = sistemaDePrueba();

    // Es la razón de que `Token:SistemaExterno` exista aparte: administrar el
    // registro y entregar la credencial son dos decisiones distintas.
    $this->actingAs(usuarioCon(['ViewAny:SistemaExterno', 'View:SistemaExterno', 'Update:SistemaExterno']))
        ->post(route('tokens-api.tokens.emitir', $sistema), [
            'password' => 'password',
        ])
        ->assertForbidden();

    expect($sistema->tokens()->count())->toBe(0);
});

test('revocar corta el acceso y queda anotado', function () {
    $sistema = sistemaDePrueba();
    $token = $sistema->createToken('servicio-mamore', ['turnos:read']);

    $this->actingAs(asSuperAdmin())
        ->delete(route('tokens-api.tokens.revocar', [$sistema, $token->accessToken->id]));

    expect($sistema->tokens()->count())->toBe(0);

    $anotado = SistemaExternoAuditoria::query()->where('sistema_externo_id', $sistema->id)->firstOrFail();

    expect($anotado->accion)->toBe(SistemaExternoAuditoria::ACCION_REVOCAR);
});

test('no se puede revocar el token de otro sistema desde esta ficha', function () {
    $sistema = sistemaDePrueba();
    $otro = sistemaDePrueba(['slug' => 'sedag', 'nombre' => 'SEDAG']);
    $ajeno = $otro->createToken('servicio-sedag', ['turnos:read']);

    // Sin acotar la búsqueda a los tokens del sistema, el id corrido dejaría
    // cortarle el acceso a cualquier otro consumidor desde acá.
    $this->actingAs(asSuperAdmin())
        ->delete(route('tokens-api.tokens.revocar', [$sistema, $ajeno->accessToken->id]));

    expect($otro->tokens()->count())->toBe(1);
});
