<?php

use App\Policies\RolePolicy;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
| La migración que borra los permisos que ya nada chequea (Horario y la
| descarga de la actividad) no le quita a ningún rol ni a ningún usuario lo
| que sí funciona.
*/

const PERMISOS_SIN_USO = ['ViewAny:Horario', 'View:Horario', 'Create:Horario', 'Update:Horario', 'Delete:Horario', 'Export:Actividad'];

function migracionPermisosSinUso(): object
{
    return require database_path('migrations/2026_10_27_085610_eliminar_permisos_sin_uso.php');
}

test('ninguno de los permisos que se borran está en la matriz de Roles', function (): void {
    expect(array_intersect(PERMISOS_SIN_USO, RolePolicy::nombresDePermiso()))->toBeEmpty();
});

test('borra los sobrantes y cada rol y usuario conserva lo demás', function (): void {
    foreach ([...RolePolicy::nombresDePermiso(), ...PERMISOS_SIN_USO] as $nombre) {
        Permission::findOrCreate($nombre, 'web');
    }

    $rrhh = Role::create(['name' => 'ADM - RRHH', 'guard_name' => 'web']);
    $rrhh->givePermissionTo(['ViewAny:Persona', 'Create:Licencia', 'ViewAny:Horario', 'Create:Horario']);
    $usuario = usuarioCon();
    $usuario->assignRole($rrhh);

    migracionPermisosSinUso()->up();

    expect(Permission::whereIn('name', PERMISOS_SIN_USO)->exists())->toBeFalse()
        ->and(Permission::pluck('name')->sort()->values()->all())->toBe(collect(RolePolicy::nombresDePermiso())->sort()->values()->all())
        ->and($rrhh->fresh()->permissions->pluck('name')->sort()->values()->all())->toBe(['Create:Licencia', 'ViewAny:Persona'])
        ->and($usuario->fresh()->hasRole('ADM - RRHH'))->toBeTrue()
        ->and($usuario->fresh()->can('Create:Licencia'))->toBeTrue();
});

test('volver atrás los crea de nuevo, sin rol', function (): void {
    $migracion = migracionPermisosSinUso();

    $migracion->up();
    $migracion->down();

    expect(Permission::whereIn('name', PERMISOS_SIN_USO)->count())->toBe(6)
        ->and(Permission::whereIn('name', PERMISOS_SIN_USO)->whereHas('roles')->exists())->toBeFalse();
});
