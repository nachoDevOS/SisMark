<?php

use App\Models\User;
use App\Policies\RolePolicy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
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

test('con clave fija el administrador puede iniciar sesión', function () {
    config(['auth.seed_admin.password' => 'clave-de-prueba']);

    $this->seed(DatabaseSeeder::class);

    expect(Auth::attempt([
        'email' => config('auth.seed_admin.email'),
        'password' => 'clave-de-prueba',
    ]))->toBeTrue();
});

test('la clave fija se reescribe si el usuario ya existía con otra', function () {
    // Un administrador de una corrida anterior, con la clave aleatoria que en
    // su momento se imprimió y se perdió.
    User::create([
        'name' => 'Admin',
        'email' => config('auth.seed_admin.email'),
        'password' => Hash::make('la-vieja-que-nadie-anotó'),
    ]);

    config(['auth.seed_admin.password' => 'clave-nueva']);

    $this->seed(DatabaseSeeder::class);

    expect(Auth::attempt([
        'email' => config('auth.seed_admin.email'),
        'password' => 'clave-nueva',
    ]))->toBeTrue()
        ->and(User::where('email', config('auth.seed_admin.email'))->count())->toBe(1);
});

test('en producción sin clave configurada la del administrador es aleatoria', function () {
    config(['auth.seed_admin.password' => '']);
    app()->detectEnvironment(fn (): string => 'production');

    // Con `--force`, que es como corre en un despliegue: sin eso `db:seed`
    // frena a preguntar por consola antes de tocar una base de producción.
    $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);

    // Que no quede «password» accesible por haber olvidado la variable.
    expect(Auth::attempt([
        'email' => config('auth.seed_admin.email'),
        'password' => 'password',
    ]))->toBeFalse();
});
