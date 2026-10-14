<?php

use App\Models\User;
use App\Models\UserHistorial;

test('el alta registra la foto completa, con autor y sin contraseña', function (): void {
    $admin = superAdmin();
    $rol = rol();

    $this->actingAs($admin)->post(route('usuarios.store'), [
        'name' => 'Ana Pérez',
        'email' => 'ana@beni.gob.bo',
        'password' => 'temporal123',
        'rol' => $rol->id,
    ]);

    $evento = User::where('email', 'ana@beni.gob.bo')->first()->historial()->sole();

    expect($evento->accion)->toBe(UserHistorial::ACCION_CREADO)
        ->and($evento->registerUser_id)->toBe($admin->id)
        ->and($evento->antes)->toBeNull()
        ->and($evento->despues)->toMatchArray([
            'Nombre' => 'Ana Pérez',
            'Email' => 'ana@beni.gob.bo',
            'Roles' => 'operador',
            'Debe cambiar contraseña' => 'Sí',
            'Registrado por' => $admin->name,
        ])
        ->and(json_encode($evento->getAttributes()))->not->toContain('temporal123');
});

test('la edición guarda solo si algo cambió, incluido el rol', function (): void {
    $otro = User::factory()->create(['name' => 'Juan']);
    $otro->assignRole(rol('consulta'));

    $this->actingAs(superAdmin())
        ->put(route('usuarios.update', $otro), ['name' => 'Juan', 'email' => $otro->email, 'rol' => rol('consulta')->id]);

    expect($otro->historial()->count())->toBe(0);

    $this->put(route('usuarios.update', $otro), ['name' => 'Juan Vaca', 'email' => $otro->email, 'rol' => rol()->id]);

    $evento = $otro->historial()->sole();

    expect($evento->accion)->toBe(UserHistorial::ACCION_ACTUALIZADO)
        ->and($evento->cambios())->toBe([
            'Nombre' => ['antes' => 'Juan', 'despues' => 'Juan Vaca'],
            'Roles' => ['antes' => 'consulta', 'despues' => 'operador'],
        ]);
});

test('el reseteo de contraseña queda anotado sin la clave', function (): void {
    $otro = User::factory()->create();

    $this->actingAs(superAdmin())
        ->put(route('usuarios.update', $otro), ['name' => $otro->name, 'email' => $otro->email, 'rol' => rol()->id, 'password' => 'reseteada1']);

    $cambios = $otro->historial()->sole()->cambios();

    expect($cambios['Contraseña'])->toBe(['antes' => UserHistorial::PASSWORD_ANTES, 'despues' => UserHistorial::PASSWORD_DESPUES])
        ->and($cambios['Debe cambiar contraseña'])->toBe(['antes' => 'No', 'despues' => 'Sí'])
        ->and(json_encode($cambios))->not->toContain('reseteada1');
});

test('el cambio de contraseña propio queda en el historial', function (): void {
    $usuario = User::factory()->create();
    $usuario->forceFill(['cambiar_password' => true])->save();

    $this->actingAs($usuario)->put(route('password.cambiar.update'), [
        'password_actual' => 'password',
        'password' => 'nuevaSegura1',
        'password_confirmation' => 'nuevaSegura1',
    ]);

    $evento = $usuario->historial()->sole();

    expect($evento->accion)->toBe(UserHistorial::ACCION_CAMBIO_PASSWORD)
        ->and($evento->registerUser_id)->toBe($usuario->id)
        ->and($evento->cambios()['Debe cambiar contraseña'])->toBe(['antes' => 'Sí', 'despues' => 'No']);
});

test('la baja registra la foto y el motivo', function (): void {
    $otro = User::factory()->create();

    $this->actingAs(superAdmin())
        ->delete(route('usuarios.destroy', $otro), ['deleteObservacion' => 'Dejó la institución']);

    $evento = $otro->historial()->sole();

    expect($evento->accion)->toBe(UserHistorial::ACCION_ELIMINADO)
        ->and($evento->antes['Nombre'])->toBe($otro->name)
        ->and($evento->despues)->toBe(['Motivo de la baja' => 'Dejó la institución']);
});

test('la pantalla muestra la línea de tiempo, también de un usuario dado de baja', function (): void {
    $otro = User::factory()->create(['name' => 'Juan']);

    $this->actingAs(superAdmin())
        ->put(route('usuarios.update', $otro), ['name' => 'Juan Vaca', 'email' => $otro->email, 'rol' => rol()->id]);
    $this->delete(route('usuarios.destroy', $otro), ['deleteObservacion' => 'Baja de prueba']);

    $this->get(route('usuarios.historial', $otro))
        ->assertOk()
        ->assertSeeInOrder(['Usuario eliminado', 'Baja de prueba', 'Datos actualizados', 'Juan Vaca'])
        ->assertSee('Eliminado');
});

test('sin permiso para ver usuarios no se abre el historial', function (): void {
    $this->actingAs(usuarioCon())
        ->get(route('usuarios.historial', User::factory()->create()))
        ->assertForbidden();
});

test('sin foto subida se muestra la silueta por defecto', function (): void {
    $usuario = superAdmin();

    expect($usuario->avatar_url)->toBe(asset('image/user-default.png'));

    $this->actingAs($usuario)->get(route('dashboard'))->assertSee('image/user-default.png', false);

    $usuario->avatar_path = 'avatars/foto.jpg';

    expect($usuario->avatar_url)->toBe(asset('storage/avatars/foto.jpg'));
});
