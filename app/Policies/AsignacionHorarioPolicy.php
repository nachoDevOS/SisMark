<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AsignacionHorario;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Autorización de los horarios asignados (tabla `asignacion_horarios`).
 *
 * Tiene permisos propios (`*:AsignacionHorario`). Antes reutilizaba los de los
 * horarios (`*:Horario`), y eso ataba las dos pantallas: no había forma de dar
 * el catálogo de horarios sin dar también a qué funcionario está asignado cada
 * uno, que es un dato de personal y no de configuración.
 *
 * Ya no hay alta: se asigna un turno. Y las filas que son detalle de un turno
 * no se concluyen ni se eliminan sueltas —quedarían desparejas con su
 * cabecera—: eso se hace desde el turno asignado. Como el super_admin pasa por
 * encima de las policies (`Gate::before`), el controlador y las vistas lo
 * vuelven a comprobar.
 */
class AsignacionHorarioPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:AsignacionHorario');
    }

    /**
     * Concluir la asignación: ponerle fecha de fin sin borrar la historia.
     */
    public function update(AuthUser $authUser, AsignacionHorario $asignacion): bool
    {
        return ! $asignacion->es_de_turno && $authUser->can('Update:AsignacionHorario');
    }

    public function delete(AuthUser $authUser, AsignacionHorario $asignacion): bool
    {
        return ! $asignacion->es_de_turno && $authUser->can('Delete:AsignacionHorario');
    }
}
