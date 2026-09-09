<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SistemaExterno;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Autorización de los sistemas que consumen la API de asistencia.
 *
 * `token()` no sale de `update()`. Editar la ficha de un consumidor —el nombre,
 * las observaciones, el interruptor— es administración; emitirle un token es
 * entregar acceso a la asistencia de los ~4.600 funcionarios con una credencial
 * que no caduca. Son dos permisos porque son dos decisiones distintas.
 */
class SistemaExternoPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:SistemaExterno');
    }

    public function view(AuthUser $authUser, SistemaExterno $sistema): bool
    {
        return $authUser->can('View:SistemaExterno');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:SistemaExterno');
    }

    public function update(AuthUser $authUser, SistemaExterno $sistema): bool
    {
        return $authUser->can('Update:SistemaExterno');
    }

    public function delete(AuthUser $authUser, SistemaExterno $sistema): bool
    {
        return $authUser->can('Delete:SistemaExterno');
    }

    /**
     * Emitir o revocar la credencial del sistema.
     *
     * Revocar comparte permiso con emitir y no con eliminar: es el camino de
     * emergencia cuando un token se filtra, y quien puede entregarlo tiene que
     * poder matarlo sin depender de que además le hayan dado la baja del
     * sistema.
     */
    public function token(AuthUser $authUser, SistemaExterno $sistema): bool
    {
        return $authUser->can('Token:SistemaExterno');
    }
}
