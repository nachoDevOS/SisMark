<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

test('el seeder renombra super_admin a Administrador en la misma fila, sin crear otro rol', function (): void {
    $anterior = Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
    $admin = User::factory()->create();
    $admin->assignRole($anterior);

    $this->seed(RolesAndPermissionsSeeder::class);

    $rol = Role::sole();

    expect($rol->id)->toBe($anterior->id)
        ->and($rol->name)->toBe(Role::ADMINISTRADOR)
        ->and($admin->fresh()->hasRole(Role::ADMINISTRADOR))->toBeTrue();

    // Con el nombre nuevo sigue pudiendo todo (Gate::before).
    $this->actingAs($admin->fresh())->get(route('usuarios.index'))->assertOk();
});

test('correrlo de nuevo no cambia nada ni duplica el rol', function (): void {
    Role::create(['name' => 'super_admin', 'guard_name' => 'web']);

    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(Role::pluck('name')->all())->toBe([Role::ADMINISTRADOR]);
});

test('en una base nueva crea el Administrador', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(Role::pluck('name')->all())->toBe([Role::ADMINISTRADOR]);
});

test('si ya existe otro Administrador, corta sin tocar nada', function (): void {
    Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
    Role::create(['name' => Role::ADMINISTRADOR, 'guard_name' => 'web']);

    expect(fn () => $this->seed(RolesAndPermissionsSeeder::class))
        ->toThrow(RuntimeException::class, 'ya existe otro rol con ese nombre');

    expect(Role::orderBy('id')->pluck('name')->all())->toBe(['super_admin', Role::ADMINISTRADOR]);
});

test('el rol super_admin ya no tiene acceso total: solo el Administrador', function (): void {
    $viejo = User::factory()->create();
    $viejo->assignRole(Role::create(['name' => 'super_admin', 'guard_name' => 'web']));

    $this->actingAs($viejo)->get(route('usuarios.index'))->assertForbidden();
});

test('el Administrador no se puede eliminar', function (): void {
    $admin = superAdmin();
    $rol = Role::findByName(Role::ADMINISTRADOR);

    $this->actingAs($admin)
        ->delete(route('roles.destroy', $rol), ['deleteObservacion' => 'Prueba de baja'])
        ->assertSessionHas('error');

    expect($rol->fresh()->trashed())->toBeFalse();

    $this->get(route('roles.index'))->assertOk()->assertSee(Role::ADMINISTRADOR);
});
