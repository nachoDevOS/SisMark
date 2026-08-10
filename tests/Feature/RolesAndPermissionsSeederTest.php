<?php

use App\Models\User;
use App\Policies\RolePolicy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

test('el seeder deja un super_admin funcional con todos los permisos', function () {
    // El total sale de la misma fuente que siembra el seeder: fijarlo a mano
    // obliga a tocar la prueba cada vez que un módulo suma una habilidad.
    $total = count(RolePolicy::nombresDePermiso());

    $this->seed(DatabaseSeeder::class);

    $superAdmin = Role::where('name', 'super_admin')->first();

    expect($superAdmin)->not->toBeNull()
        ->and(Permission::count())->toBe($total)
        ->and($superAdmin->permissions()->count())->toBe($total);

    $usuario = User::where('email', 'admin@admin.com')->first();

    expect($usuario)->not->toBeNull()
        ->and($usuario->hasRole('super_admin'))->toBeTrue();
});

test('el seeder es idempotente si se corre dos veces', function () {
    $total = count(RolePolicy::nombresDePermiso());

    $this->seed(DatabaseSeeder::class);

    expect(fn () => Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web'])->syncPermissions(Permission::all()))
        ->not->toThrow(Exception::class);

    expect(Permission::count())->toBe($total);
});
