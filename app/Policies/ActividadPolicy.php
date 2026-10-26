<?php

namespace App\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Actividad de usuarios: se consulta y se baja en Excel. Sin `update` ni
 * `delete`: nadie la edita ni la borra.
 */
class ActividadPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Actividad');
    }

    public function export(AuthUser $authUser): bool
    {
        return $authUser->can('Export:Actividad');
    }
}
