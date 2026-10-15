<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('el usuario dado de alta queda obligado a cambiar la contraseña', function (): void {
    $this->actingAs(superAdmin())
        ->post(route('usuarios.store'), [
            'name' => 'Ana Pérez',
            'email' => 'ana@beni.gob.bo',
            'password' => 'temporal123',
            'rol' => rol()->id,
        ])
        ->assertRedirect(route('usuarios.index'));

    expect(User::where('email', 'ana@beni.gob.bo')->first()->cambiar_password)->toBeTrue();
});

test('resetear la contraseña de otro lo obliga a cambiarla; la propia no', function (): void {
    $admin = superAdmin();
    $otro = User::factory()->create();

    $this->actingAs($admin)
        ->put(route('usuarios.update', $otro), ['name' => $otro->name, 'email' => $otro->email, 'rol' => rol()->id, 'password' => 'reseteada1'])
        ->assertRedirect(route('usuarios.index'));

    $this->put(route('usuarios.update', $admin), ['name' => $admin->name, 'email' => $admin->email, 'rol' => rol(Role::ADMINISTRADOR)->id, 'password' => 'mipropia123'])
        ->assertRedirect(route('usuarios.index'));

    expect($otro->fresh()->cambiar_password)->toBeTrue()
        ->and($admin->fresh()->cambiar_password)->toBeFalse();
});

test('editar sin tocar la contraseña no obliga a cambiarla', function (): void {
    $otro = User::factory()->create();

    $this->actingAs(superAdmin())
        ->put(route('usuarios.update', $otro), ['name' => 'Otro nombre', 'email' => $otro->email, 'rol' => rol()->id]);

    expect($otro->fresh()->cambiar_password)->toBeFalse();
});

test('con la contraseña dada, cualquier pantalla lleva al cambio', function (): void {
    $usuario = superAdmin();
    $usuario->forceFill(['cambiar_password' => true])->save();

    $this->actingAs($usuario)->get(route('dashboard'))->assertRedirect(route('password.cambiar'));
    $this->get(route('usuarios.index'))->assertRedirect(route('password.cambiar'));
    $this->get(route('password.cambiar'))->assertOk()->assertSee('antes de seguir');
    $this->post(route('logout'))->assertRedirect(route('login'));
});

test('al cambiarla queda libre y entra con la nueva', function (): void {
    $usuario = superAdmin();
    $usuario->forceFill(['cambiar_password' => true])->save();

    $this->actingAs($usuario)
        ->put(route('password.cambiar.update'), [
            'password_actual' => 'password',
            'password' => 'nuevaSegura1',
            'password_confirmation' => 'nuevaSegura1',
        ])
        ->assertRedirect(route('dashboard'));

    $usuario->refresh();

    expect($usuario->cambiar_password)->toBeFalse()
        ->and(Hash::check('nuevaSegura1', $usuario->password))->toBeTrue();

    $this->get(route('dashboard'))->assertOk();
});

test('rechaza actual incorrecta, nueva igual a la actual o sin confirmar', function (array $datos, string $campo): void {
    $usuario = User::factory()->create();
    $usuario->forceFill(['cambiar_password' => true])->save();

    $this->actingAs($usuario)
        ->from(route('password.cambiar'))
        ->put(route('password.cambiar.update'), $datos)
        ->assertSessionHasErrors($campo);

    expect($usuario->fresh()->cambiar_password)->toBeTrue();
})->with([
    'actual incorrecta' => [['password_actual' => 'otra', 'password' => 'nuevaSegura1', 'password_confirmation' => 'nuevaSegura1'], 'password_actual'],
    'nueva igual a la actual' => [['password_actual' => 'password', 'password' => 'password', 'password_confirmation' => 'password'], 'password'],
    'sin confirmar' => [['password_actual' => 'password', 'password' => 'nuevaSegura1', 'password_confirmation' => 'distinta1'], 'password'],
]);

test('los usuarios existentes no quedan obligados', function (): void {
    $this->actingAs(superAdmin())->get(route('dashboard'))->assertOk();
});

test('el ingreso y el cambio de contraseña tienen el ojo para ver la clave', function (): void {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('aria-controls="password"', false);

    $this->actingAs(User::factory()->create())
        ->get(route('password.cambiar'))
        ->assertOk()
        ->assertSee('aria-controls="password_actual"', false)
        ->assertSee('aria-controls="password"', false)
        ->assertSee('aria-controls="password_confirmation"', false);
});

test('el alta y la edición exigen un rol', function (): void {
    $this->actingAs(superAdmin())
        ->post(route('usuarios.store'), ['name' => 'Ana', 'email' => 'ana@beni.gob.bo', 'password' => 'temporal123'])
        ->assertSessionHasErrors('rol');

    $this->put(route('usuarios.update', User::factory()->create()), ['name' => 'Ana', 'email' => 'otra@beni.gob.bo'])
        ->assertSessionHasErrors('rol');
});

test('el formulario de usuario trae un solo rol, el ojo en la contraseña y no pide foto', function (): void {
    $this->actingAs(superAdmin())
        ->get(route('usuarios.create'))
        ->assertOk()
        ->assertSee('name="rol"', false)
        ->assertDontSee('multiple', false)
        ->assertSee('aria-controls="password"', false)
        ->assertDontSee('name="avatar"', false);
});

test('el ingreso dice que las cuentas las habilita la Dirección de Sistemas y Telecomunicaciones', function (): void {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Sistemas y Telecomunicaciones del Gobierno Departamental')
        ->assertDontSee('Recursos Humanos');
});
