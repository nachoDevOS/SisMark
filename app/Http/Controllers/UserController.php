<?php

namespace App\Http\Controllers;

use App\Http\Requests\InhabilitarUserRequest;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\UserHistorial;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * CRUD clásico (MVC) de los usuarios del sistema.
 *
 * La contraseña se hashea sola por el cast 'hashed' del modelo User; el
 * rol se asigna con spatie/permission (uno por usuario).
 */
class UserController extends Controller
{
    /**
     * Listado de usuarios con sus roles, con búsqueda por nombre o correo.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', User::class);

        $busqueda = trim((string) $request->query('q', ''));
        $porPagina = $this->porPagina($request);

        $usuarios = User::with('roles')
            ->when($busqueda !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('name', 'like', "%{$busqueda}%")
                ->orWhere('email', 'like', "%{$busqueda}%")))
            ->latest()
            ->paginate($porPagina)
            ->withQueryString();

        return view('usuarios.index', compact('usuarios', 'busqueda', 'porPagina'));
    }

    /**
     * Formulario de alta.
     */
    public function create(): View
    {
        $this->authorize('create', User::class);

        return view('usuarios.create', ['roles' => Role::orderBy('name')->get()]);
    }

    /**
     * Guarda un usuario nuevo con su rol.
     */
    public function store(StoreUserRequest $request): RedirectResponse
    {
        $this->authorize('create', User::class);

        $datos = $request->safe()->except('rol');

        DB::transaction(function () use ($datos, $request): void {
            // La contraseña la eligió quien lo dio de alta: la cambia al entrar.
            $usuario = User::create($datos);
            $usuario->forceFill(['cambiar_password' => true])->save();
            $usuario->syncRoles(Role::findOrFail($request->integer('rol')));

            UserHistorial::registrar($usuario, UserHistorial::ACCION_CREADO, null, UserHistorial::foto($usuario));
        });

        return redirect()
            ->route('usuarios.index')
            ->with('estado', 'Usuario creado correctamente.');
    }

    /**
     * Formulario de edición.
     */
    public function edit(User $usuario): View
    {
        $this->authorize('update', $usuario);

        return view('usuarios.edit', [
            'usuario' => $usuario->load('roles'),
            'roles' => Role::orderBy('name')->get(),
        ]);
    }

    /**
     * Actualiza un usuario. La contraseña vacía se ignora (se conserva).
     */
    public function update(UpdateUserRequest $request, User $usuario): RedirectResponse
    {
        $this->authorize('update', $usuario);

        // La foto de «antes» se saca primero, antes de tocar ningún atributo.
        $antes = UserHistorial::foto($usuario);
        $datos = $request->safe()->except(['rol', 'password']);

        // Solo cambia la contraseña si el formulario trajo una. Si la puso otro
        // (un reseteo), el dueño de la cuenta la cambia al entrar.
        if ($request->filled('password')) {
            $datos['password'] = $request->input('password');
            $usuario->cambiar_password = $usuario->isNot($request->user());
        }

        DB::transaction(function () use ($usuario, $datos, $request, $antes): void {
            $usuario->fill($datos)->save();
            $usuario->syncRoles(Role::findOrFail($request->integer('rol')));

            // La contraseña no se compara por su valor: se anota que cambió,
            // sin guardar la clave.
            $despues = UserHistorial::foto($usuario);

            if ($request->filled('password')) {
                $antes['Contraseña'] = UserHistorial::PASSWORD_ANTES;
                $despues['Contraseña'] = UserHistorial::PASSWORD_DESPUES;
            }

            UserHistorial::registrar($usuario, UserHistorial::ACCION_ACTUALIZADO, $antes, $despues);
        });

        return redirect()
            ->route('usuarios.index')
            ->with('estado', 'Usuario actualizado correctamente.');
    }

    /**
     * Elimina un usuario (eliminación lógica). La foto de perfil se conserva
     * para poder restaurarlo con su avatar intacto.
     */
    public function destroy(User $usuario): RedirectResponse
    {
        $this->authorize('delete', $usuario);

        DB::transaction(function () use ($usuario): void {
            $antes = UserHistorial::foto($usuario);

            $usuario->delete();

            UserHistorial::registrar($usuario, UserHistorial::ACCION_ELIMINADO, $antes, [
                'Motivo de la baja' => $usuario->deleteObservacion ?: 'Sin motivo',
            ]);
        });

        return redirect()
            ->route('usuarios.index')
            ->with('estado', 'Usuario eliminado.');
    }

    /**
     * Le quita el acceso al sistema sin borrarlo: sigue en el listado como
     * «Inactivo» y se revierte con habilitar. El motivo queda en el historial.
     * Nadie se inhabilita a sí mismo, para no quedar afuera por un clic.
     */
    public function inhabilitar(InhabilitarUserRequest $request, User $usuario): RedirectResponse
    {
        $this->authorize('update', $usuario);

        if ($usuario->is($request->user())) {
            return back()->with('error', 'No podés inhabilitar tu propio usuario.');
        }

        $this->cambiarEstado($usuario, false, $request->validated('motivo'));

        return back()->with('estado', "Usuario «{$usuario->name}» inhabilitado: ya no puede entrar al sistema.");
    }

    /**
     * Le devuelve el acceso a un usuario inhabilitado.
     */
    public function habilitar(User $usuario): RedirectResponse
    {
        $this->authorize('update', $usuario);

        $this->cambiarEstado($usuario, true);

        return back()->with('estado', "Usuario «{$usuario->name}» habilitado.");
    }

    /**
     * Guarda el estado y su evento en el historial. Si ya estaba así, no hace
     * nada (un doble clic no deja dos eventos).
     */
    private function cambiarEstado(User $usuario, bool $activo, ?string $motivo = null): void
    {
        if ($usuario->activo === $activo) {
            return;
        }

        DB::transaction(function () use ($usuario, $activo, $motivo): void {
            $antes = UserHistorial::foto($usuario);

            $usuario->forceFill(['activo' => $activo])->save();

            $despues = UserHistorial::foto($usuario);

            if ($motivo !== null) {
                $despues['Motivo'] = $motivo;
            }

            UserHistorial::registrar(
                $usuario,
                $activo ? UserHistorial::ACCION_HABILITADO : UserHistorial::ACCION_INHABILITADO,
                $antes,
                $despues,
            );
        });
    }

    /**
     * Línea de tiempo de los cambios del usuario. Abre también la de un
     * usuario dado de baja: es justo cuando más se la necesita.
     */
    public function historial(User $usuario): View
    {
        $this->authorize('viewAny', User::class);

        $historial = $usuario->historial()->with('autor')->paginate(8)->withQueryString();

        return view('usuarios.historial', compact('usuario', 'historial'));
    }
}
