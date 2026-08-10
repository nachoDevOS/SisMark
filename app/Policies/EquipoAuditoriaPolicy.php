<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Autorización de la bitácora de los biométricos: quién exportó, quién
 * sincronizó, quién vació un reloj y quién dio de baja un equipo, con el motivo.
 *
 * Solo se consulta —las filas las escriben los propios controladores—, así que
 * la única habilidad es `ViewAny`. Va con permiso propio y no con el de los
 * equipos porque es un registro de auditoría: quién administra los relojes no
 * es necesariamente quién audita lo que se hizo con ellos.
 */
class EquipoAuditoriaPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:EquipoAuditoria');
    }
}
