<?php

namespace App\Models;

use App\Traits\RegistersUserEvents;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Rol del sistema (Spatie permission), extendido con eliminación lógica.
 *
 * Todo el borrado del sistema es lógico: destroy() solo marca `deleted_at` y
 * el rol desaparece de los listados y de las consultas de permisos sin salir
 * de la base. La conexión con Spatie se hace vía `config('permission.models.role')`.
 *
 * RegistersUserEvents deja además quién lo eliminó y el motivo que se escribió
 * en el modal de eliminación (`deleteUser_id` / `deleteObservacion`).
 */
class Role extends SpatieRole
{
    use RegistersUserEvents, SoftDeletes;

    /**
     * El rol que puede todo, sin permisos individuales (`Gate::before` en
     * AppServiceProvider). No se puede eliminar. Hasta el 2026-10-14 se llamó
     * `super_admin`: RolesAndPermissionsSeeder renombra la fila vieja.
     *
     * Para cambiarle el nombre no alcanza con esta constante: la base guarda el
     * nombre viejo. Hay que poner el nombre viejo en
     * `RolesAndPermissionsSeeder::NOMBRE_ANTERIOR`, o el seeder del arranque
     * crearía un rol nuevo vacío y el actual perdería el acceso total.
     */
    public const ADMINISTRADOR = 'Administrador del sistema';
}
