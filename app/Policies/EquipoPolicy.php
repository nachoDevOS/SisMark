<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Equipo;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class EquipoPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Equipo');
    }

    public function view(AuthUser $authUser, Equipo $equipo): bool
    {
        return $authUser->can('View:Equipo');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Equipo');
    }

    public function update(AuthUser $authUser, Equipo $equipo): bool
    {
        return $authUser->can('Update:Equipo');
    }

    public function delete(AuthUser $authUser, Equipo $equipo): bool
    {
        return $authUser->can('Delete:Equipo');
    }

    /**
     * Leer el reloj y volcar sus marcaciones a la base local.
     *
     * Va aparte de `update`: probar la conexión solo toca la fila del equipo,
     * mientras que sincronizar inserta marcaciones, que es de lo que después
     * salen los reportes de asistencia.
     */
    public function sync(AuthUser $authUser, Equipo $equipo): bool
    {
        return $authUser->can('Sync:Equipo');
    }

    /**
     * Vaciar el buffer de marcaciones del reloj.
     *
     * Va aparte de `delete` porque no es lo mismo dar de baja un equipo del
     * sistema —que es reversible, la fila queda con eliminación lógica— que
     * borrarle el historial entero al aparato, que no tiene vuelta atrás: el
     * protocolo ZK no permite borrar por rango ni recuperar lo borrado.
     */
    public function clear(AuthUser $authUser, Equipo $equipo): bool
    {
        return $authUser->can('Clear:Equipo');
    }
}
