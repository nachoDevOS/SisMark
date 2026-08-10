<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Autorización de las marcaciones locales (MySQL). Usa los mismos permisos que
 * la policy legada (ViewAny:Asistencia, etc.), así los roles no cambian al pasar
 * la vista de la conexión `sia` a la base local.
 */
class AsistenciaPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Asistencia');
    }

    /**
     * Cubre las tres formas de que entre una marcación: el registro manual, la
     * importación del CSV y la sincronización del reloj.
     *
     * No hay `view`, `update` ni `delete`: una marcación no se abre en ficha
     * propia, no se edita y no se da de baja desde el sistema.
     */
    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Asistencia');
    }
}
