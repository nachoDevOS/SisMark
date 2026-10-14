<?php

use App\Http\Middleware\ExpulsarUsuarioInhabilitado;
use App\Models\User;
use App\Models\UserHistorial;

test('inhabilitar le quita el acceso, guarda el motivo y lo deja en el listado como inactivo', function (): void {
    $otro = User::factory()->create(['name' => 'Juan Vaca']);

    $this->actingAs(superAdmin())
        ->from(route('usuarios.index'))
        ->post(route('usuarios.inhabilitar', $otro), ['motivo' => 'Dejó la institución'])
        ->assertRedirect(route('usuarios.index'));

    $evento = $otro->historial()->sole();

    expect($otro->fresh()->activo)->toBeFalse()
        ->and($otro->fresh()->trashed())->toBeFalse()
        ->and($evento->accion)->toBe(UserHistorial::ACCION_INHABILITADO)
        ->and($evento->cambios())->toBe([
            'Estado' => ['antes' => 'Activo', 'despues' => 'Inactivo'],
            'Motivo' => ['antes' => null, 'despues' => 'Dejó la institución'],
        ]);

    $this->get(route('usuarios.index'))->assertSeeInOrder(['Juan Vaca', 'Inactivo', 'Habilitar']);
});

test('inhabilitar exige el motivo', function (): void {
    $otro = User::factory()->create();

    $this->actingAs(superAdmin())
        ->post(route('usuarios.inhabilitar', $otro), ['motivo' => ''])
        ->assertSessionHasErrors('motivo');

    expect($otro->fresh()->activo)->toBeTrue();
});

test('nadie se inhabilita a sí mismo', function (): void {
    $admin = superAdmin();

    $this->actingAs($admin)
        ->post(route('usuarios.inhabilitar', $admin), ['motivo' => 'Prueba propia'])
        ->assertSessionHas('error');

    expect($admin->fresh()->activo)->toBeTrue();
});

test('un usuario inhabilitado no puede entrar aunque la contraseña sea correcta', function (): void {
    $usuario = User::factory()->create(['email' => 'juan@beni.gob.bo']);
    $usuario->forceFill(['activo' => false])->save();

    $this->post(route('login'), ['email' => 'juan@beni.gob.bo', 'password' => 'password'])
        ->assertSessionHasErrors(['email' => ExpulsarUsuarioInhabilitado::AVISO]);

    $this->assertGuest();
});

test('con la sesión abierta queda afuera en la próxima pantalla', function (): void {
    $usuario = superAdmin();

    $this->actingAs($usuario)->get(route('dashboard'))->assertOk();

    $usuario->forceFill(['activo' => false])->save();

    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();

    $this->getJson(route('dashboard'))->assertUnauthorized();
});

test('habilitar le devuelve el acceso y queda en el historial', function (): void {
    $otro = User::factory()->create(['email' => 'juan@beni.gob.bo']);
    $otro->forceFill(['activo' => false])->save();

    $this->actingAs(superAdmin())
        ->from(route('usuarios.index'))
        ->post(route('usuarios.habilitar', $otro))
        ->assertRedirect(route('usuarios.index'));

    expect($otro->fresh()->activo)->toBeTrue()
        ->and($otro->historial()->sole()->accion)->toBe(UserHistorial::ACCION_HABILITADO);

    $this->post(route('logout'));
    $this->post(route('login'), ['email' => 'juan@beni.gob.bo', 'password' => 'password']);
    $this->assertAuthenticatedAs($otro);
});

test('sin permiso de editar usuarios no se puede inhabilitar ni habilitar', function (): void {
    $otro = User::factory()->create();

    $this->actingAs(usuarioCon('ViewAny:User'))
        ->post(route('usuarios.inhabilitar', $otro), ['motivo' => 'Sin permiso'])
        ->assertForbidden();

    $this->post(route('usuarios.habilitar', $otro))->assertForbidden();
});
