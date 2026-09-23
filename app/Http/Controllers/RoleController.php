<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRoleRequest;
use App\Http\Requests\UpdateRoleRequest;
use App\Models\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * CRUD clásico (MVC) de roles y su matriz de permisos. Usa el modelo Spatie
 * (roles/permissions) y la autorización vía RolePolicy.
 */
class RoleController extends Controller
{
    /**
     * Listado de roles con su cantidad de permisos.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Role::class);

        $busqueda = trim((string) $request->query('q', ''));
        $porPagina = $this->porPagina($request);

        $roles = Role::withCount('permissions')
            ->when($busqueda !== '', fn (Builder $query) => $query->where('name', 'like', "%{$busqueda}%"))
            ->orderBy('name')
            ->paginate($porPagina)
            ->withQueryString();

        return view('roles.index', compact('roles', 'busqueda', 'porPagina'));
    }

    /**
     * Formulario de alta, con la matriz de permisos vacía.
     */
    public function create(): View
    {
        $this->authorize('create', Role::class);

        return view('roles.create');
    }

    /**
     * Guarda un rol nuevo y le sincroniza los permisos elegidos.
     */
    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $this->authorize('create', Role::class);

        $role = Role::create(['name' => $request->validated('name'), 'guard_name' => 'web']);
        $role->syncPermissions($request->validated('permisos') ?? []);

        return redirect()
            ->route('roles.index')
            ->with('estado', 'Rol creado correctamente.');
    }

    /**
     * Formulario de edición, con la matriz marcada según los permisos actuales.
     */
    public function edit(Role $role): View
    {
        $this->authorize('update', $role);

        return view('roles.edit', [
            'role' => $role,
            'permisosActuales' => $role->permissions->pluck('name')->all(),
        ]);
    }

    /**
     * Actualiza el nombre y vuelve a sincronizar los permisos del rol.
     */
    public function update(UpdateRoleRequest $request, Role $role): RedirectResponse
    {
        $this->authorize('update', $role);

        $role->update(['name' => $request->validated('name')]);
        $role->syncPermissions($request->validated('permisos') ?? []);

        return redirect()
            ->route('roles.index')
            ->with('estado', 'Rol actualizado correctamente.');
    }

    /**
     * Elimina un rol. Nunca se puede borrar super_admin: perdería el acceso
     * al sistema quien lo intente.
     */
    public function destroy(Role $role): RedirectResponse
    {
        $this->authorize('delete', $role);

        if ($role->name === 'super_admin') {
            return back()->with('error', 'El rol super_admin no se puede eliminar.');
        }

        $role->delete();

        // Spatie solo limpia su caché de permisos cuando el borrado es físico;
        // con la eliminación lógica el rol seguiría en la caché hasta que
        // venza. Se limpia a mano para que el cambio rija en el próximo pedido.
        $role->forgetCachedPermissions();

        return redirect()
            ->route('roles.index')
            ->with('estado', 'Rol eliminado.');
    }
}
