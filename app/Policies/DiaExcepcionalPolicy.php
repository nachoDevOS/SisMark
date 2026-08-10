<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DiaExcepcional;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Autorización de los días excepcionales (MySQL, tabla `dias_excepcionales`).
 * Usa la convención de permisos del sistema (ViewAny:DiaExcepcional, etc.).
 */
class DiaExcepcionalPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:DiaExcepcional');
    }

    // Sin `view`: el día excepcional no tiene ficha propia, se ve y se edita
    // desde el listado.
    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:DiaExcepcional');
    }

    public function update(AuthUser $authUser, DiaExcepcional $diaExcepcional): bool
    {
        return $authUser->can('Update:DiaExcepcional');
    }

    public function delete(AuthUser $authUser, DiaExcepcional $diaExcepcional): bool
    {
        return $authUser->can('Delete:DiaExcepcional');
    }
}
