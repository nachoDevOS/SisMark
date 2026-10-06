<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Turno;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Autorización de los turnos. `update` no edita el turno —que no se edita—:
 * solo cubre marcar o desmarcar el sugerido.
 */
class TurnoPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Turno');
    }

    public function view(AuthUser $authUser, Turno $turno): bool
    {
        return $authUser->can('View:Turno');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Turno');
    }

    public function update(AuthUser $authUser, Turno $turno): bool
    {
        return $authUser->can('Update:Turno');
    }

    public function delete(AuthUser $authUser, Turno $turno): bool
    {
        return $authUser->can('Delete:Turno');
    }
}
