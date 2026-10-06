<?php

use App\Models\Horario;
use App\Models\Turno;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Base de las pruebas
|--------------------------------------------------------------------------
|
| Todas corren sobre SQLite en memoria (`phpunit.xml`) y la rehacen en cada
| prueba. **Nunca contra la base de desarrollo**: `Tests\TestCase` corta la
| corrida antes de tocar ninguna base si la conexión no es esa.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        // Ningún test sale a la red. Mamoré queda sin configurar, así el
        // sistema usa la base local, que es lo que hace cuando Mamoré no está.
        config(['services.mamore.url' => null, 'services.mamore.token' => null]);
        Http::preventStrayRequests();
    })
    ->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Ayudas
|--------------------------------------------------------------------------
*/

/**
 * Un usuario super_admin: pasa todas las policies (`Gate::before`).
 */
function superAdmin(): User
{
    $rol = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $usuario = User::factory()->create();
    $usuario->assignRole($rol);

    return $usuario;
}

/**
 * Un usuario con solo los permisos dados (`Habilidad:Modulo`).
 */
function usuarioCon(string ...$permisos): User
{
    foreach ($permisos as $permiso) {
        Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']);
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $usuario = User::factory()->create();
    $usuario->givePermissionTo($permisos);

    return $usuario;
}

/**
 * Un horario de un día (1 = domingo … 7 = sábado), con las ventanas de marca
 * una hora antes y dos después de la entrada, y hasta dos después de la salida.
 */
function horario(int $dia, string $entrada = '08:00', string $salida = '16:00', bool $siguienteDia = false): Horario
{
    $hora = fn (string $hm): Carbon => Carbon::createFromFormat('Y-m-d H:i', "1899-12-30 {$hm}");

    return Horario::factory()->create([
        'dia' => (string) $dia,
        'hEntrada' => $hora($entrada),
        'hTolerancia' => $hora($entrada)->addMinutes(10),
        'eMinima' => $hora($entrada)->subHour(),
        'eMaxima' => $hora($entrada)->addHours(2),
        'hSalida' => $hora($salida),
        'sTolerancia' => $hora($salida),
        'sMinima' => $hora($salida),
        'sMaxima' => $hora($salida)->addHours(2),
        'siguienteDia' => $siguienteDia,
    ]);
}

/**
 * Turno de lunes a viernes con el mismo horario cada día.
 */
function turnoLunesAViernes(string $entrada = '08:00', string $salida = '16:00', bool $sugerido = false): Turno
{
    $turno = Turno::factory()->create(['nombre' => "L-V {$entrada} - {$salida}", 'sugerido' => $sugerido]);
    $turno->horarios()->attach(collect([2, 3, 4, 5, 6])->map(fn (int $dia): int => horario($dia, $entrada, $salida)->id));

    return $turno->load('horarios');
}
