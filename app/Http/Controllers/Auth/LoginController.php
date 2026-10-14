<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ExpulsarUsuarioInhabilitado;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Login/logout propios del sitio Blade/MVC, guardia 'web' estándar contra
 * App\Models\User (config/auth.php).
 */
class LoginController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()
                ->withErrors(['email' => 'Las credenciales no coinciden con ningún registro.'])
                ->onlyInput('email');
        }

        // La contraseña era correcta, pero la cuenta está inhabilitada: se
        // cierra la sesión recién abierta y se dice por qué no entra.
        if (! Auth::user()->activo) {
            Auth::logout();

            return back()
                ->withErrors(['email' => ExpulsarUsuarioInhabilitado::AVISO])
                ->onlyInput('email');
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
