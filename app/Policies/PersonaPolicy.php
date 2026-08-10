<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Persona;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Autorización de los funcionarios locales (MySQL, tabla `personas`). Usa los
 * mismos permisos que la policy legada (ViewAny:Persona, etc.), así los roles
 * no cambian al pasar la vista de la conexión `sia` a la base local.
 */
class PersonaPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Persona');
    }

    public function view(AuthUser $authUser, Persona $persona): bool
    {
        return $authUser->can('View:Persona');
    }

    // Sin `create`, `update` ni `delete`: los funcionarios son de solo lectura,
    // el alta y la baja viven en los sistemas de origen (Mamoré y SIAT).
}
