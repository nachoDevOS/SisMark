<?php

namespace App\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Actividad de usuarios: solo se consulta. Sin `update` ni `delete`: nadie
 * la edita ni la borra.
 */
class ActividadPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Actividad');
    }
}
