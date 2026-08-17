<?php

declare(strict_types=1);

namespace App\Policies;

use App\Http\Requests\RevisarLicenciaRequest;
use App\Models\Licencia;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Autorización de las licencias/permisos de personal (MySQL, tabla `licencias`).
 * Usa la convención de permisos del sistema (ViewAny:Licencia, etc.).
 */
class LicenciaPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Licencia');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Licencia');
    }

    /**
     * Ficha de la solicitud: el rango completo con todos sus días, el motivo y
     * el respaldo. Es el paso previo obligado a resolverla —no se aprueba algo
     * que no se miró—, así que existe aunque la licencia no se edite.
     */
    public function view(AuthUser $authUser, Licencia $licencia): bool
    {
        return $authUser->can('View:Licencia');
    }

    /**
     * Resolver una solicitud (aprobarla o rechazarla).
     *
     * ---
     * **Acá va solo el permiso, no el estado de la licencia.**
     *
     * `AppServiceProvider` registra un `Gate::before` que le concede todo al rol
     * super_admin, y ese atajo devuelve `true` sin llegar a ejecutar este
     * método. Cualquier regla de negocio escrita acá —«solo lo Pendiente»— se
     * saltearía justamente para el usuario que más usa la pantalla.
     *
     * Por eso la condición de estado vive en {@see RevisarLicenciaRequest},
     * que corre siempre.
     * ---
     */
    public function approve(AuthUser $authUser, Licencia $licencia): bool
    {
        return $authUser->can('Approve:Licencia');
    }

    // Sin `update`: una licencia no se edita, se anota o se elimina.
    public function delete(AuthUser $authUser, Licencia $licencia): bool
    {
        return $authUser->can('Delete:Licencia');
    }
}
