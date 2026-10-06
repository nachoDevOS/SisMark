<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Horario;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Autorización de los horarios locales (MySQL, tabla `horarios`). Usa los mismos
 * permisos que la policy legada (ViewAny:Horario, etc.), así los roles no
 * cambian al pasar la vista de la conexión `sia` a la base local.
 */
class HorarioPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Horario');
    }

    public function view(AuthUser $authUser, Horario $horario): bool
    {
        return $authUser->can('View:Horario');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Horario');
    }

    public function update(AuthUser $authUser, Horario $horario): bool
    {
        return $authUser->can('Update:Horario');
    }

    public function delete(AuthUser $authUser, Horario $horario): bool
    {
        return $authUser->can('Delete:Horario');
    }
}
