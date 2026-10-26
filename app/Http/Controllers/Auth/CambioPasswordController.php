<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\CambiarPasswordRequest;
use App\Models\Actividad;
use App\Models\UserHistorial;
use App\Services\RegistroActividad;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Cambio de contraseña del propio usuario. Es la pantalla a la que
 * `ExigirCambioPassword` manda a quien todavía usa la que le dieron.
 */
class CambioPasswordController extends Controller
{
    public function edit(Request $request): View
    {
        return view('auth.cambiar-password', ['obligatorio' => $request->user()->cambiar_password]);
    }

    public function update(CambiarPasswordRequest $request, RegistroActividad $actividad): RedirectResponse
    {
        $usuario = $request->user();

        DB::transaction(function () use ($usuario, $request): void {
            $antes = UserHistorial::foto($usuario) + ['Contraseña' => UserHistorial::PASSWORD_ANTES];

            $usuario->forceFill([
                'password' => $request->validated('password'),
                'cambiar_password' => false,
            ])->save();

            UserHistorial::registrar($usuario, UserHistorial::ACCION_CAMBIO_PASSWORD, $antes,
                UserHistorial::foto($usuario) + ['Contraseña' => UserHistorial::PASSWORD_DESPUES]);
        });

        $request->session()->regenerate();

        $actividad->registrarEvento(Actividad::CAMBIO_PASSWORD, 'Acceso', 'Cambió su contraseña', usuario: $usuario);

        return redirect()
            ->route('dashboard')
            ->with('estado', 'Contraseña actualizada correctamente.');
    }
}
