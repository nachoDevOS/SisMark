<?php

namespace Database\Seeders;

use App\Models\Role as RolDelSistema;
use App\Policies\RolePolicy;
use Illuminate\Database\Seeder;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reproduce en cualquier entorno (dev, staging, CI) los permisos y el rol
 * Administrador. Sin este seeder, un `migrate:fresh --seed` deja el sistema sin
 * accesos configurados. No corre solo: se ejecuta a mano (ver README).
 *
 * La lista de permisos vive en RolePolicy::nombresDePermiso() (misma fuente
 * que usa RoleController para la matriz de checkboxes).
 */
class RolesAndPermissionsSeeder extends Seeder
{
    /** Nombre que tuvo el rol Administrador hasta el 2026-10-14. */
    private const NOMBRE_ANTERIOR = 'super_admin';

    public function run(): void
    {
        foreach (RolePolicy::nombresDePermiso() as $nombre) {
            Permission::firstOrCreate(['name' => $nombre, 'guard_name' => 'web']);
        }

        $this->renombrarRolAnterior();

        $administrador = Role::firstOrCreate([
            'name' => RolDelSistema::ADMINISTRADOR,
            'guard_name' => 'web',
        ]);

        $administrador->syncPermissions(Permission::all());
    }

    /**
     * `super_admin` pasa a llamarse `Administrador` en la **misma fila**: mismo
     * id, así que sus usuarios lo conservan y no se crea un rol nuevo. Va antes
     * del `firstOrCreate`, que si no crearía un Administrador vacío.
     *
     * Si ya existe otro rol con el nombre nuevo no se puede renombrar (el
     * nombre es único) y se corta sin tocar nada: hay que resolver a mano cuál
     * de los dos queda.
     */
    private function renombrarRolAnterior(): void
    {
        $anterior = Role::where('name', self::NOMBRE_ANTERIOR)->where('guard_name', 'web')->first();

        if ($anterior === null) {
            return;
        }

        if (Role::where('name', RolDelSistema::ADMINISTRADOR)->where('guard_name', 'web')->exists()) {
            throw new RuntimeException(
                'No se puede renombrar el rol «'.self::NOMBRE_ANTERIOR.'» a «'.RolDelSistema::ADMINISTRADOR.'»: '
                .'ya existe otro rol con ese nombre. Renombrar o eliminar ese otro rol y volver a desplegar.'
            );
        }

        $anterior->update(['name' => RolDelSistema::ADMINISTRADOR]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info('Rol «'.self::NOMBRE_ANTERIOR.'» renombrado a «'.RolDelSistema::ADMINISTRADOR.'».');
    }
}
