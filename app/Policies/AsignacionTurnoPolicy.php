<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AsignacionTurno;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Autorización de los turnos asignados (tabla `asignacion_turnos`).
 *
 * Tiene permisos propios (`*:AsignacionTurno`). Antes reutilizaba los de los
 * turnos (`*:DiaTurno`), y eso ataba las dos pantallas: no había forma de dar
 * el catálogo de turnos sin dar también a qué funcionario está asignado cada
 * uno, que es un dato de personal y no de configuración.
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
