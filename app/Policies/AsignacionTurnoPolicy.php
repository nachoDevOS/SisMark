<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AsignacionTurno;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Autorización de los turnos asignados (tabla `asignacion_turnos`).
 */
class AsignacionTurnoPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:AsignacionTurno');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:AsignacionTurno');
    }

    /**
     * Concluir la asignación: ponerle fecha de fin sin borrar la historia.
     */
    public function update(AuthUser $authUser, AsignacionTurno $asignacion): bool
    {
        return $authUser->can('Update:AsignacionTurno');
    }

    public function delete(AuthUser $authUser, AsignacionTurno $asignacion): bool
    {
        return $authUser->can('Delete:AsignacionTurno');
    }
}
