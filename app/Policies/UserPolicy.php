<?php

namespace App\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class UserPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:User');
    }

    // Sin `view`: el usuario no tiene ficha propia, se administra desde el
    // listado y su formulario de edición.
    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:User');
    }

    public function update(AuthUser $authUser): bool
    {
        return $authUser->can('Update:User');
    }

    public function delete(AuthUser $authUser): bool
    {
        return $authUser->can('Delete:User');
    }
}
