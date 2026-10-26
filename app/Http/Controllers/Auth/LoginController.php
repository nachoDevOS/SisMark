<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ExpulsarUsuarioInhabilitado;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Login/logout propios del sitio Blade/MVC, guardia 'web' estándar contra
 * App\Models\User (config/auth.php).
 */
class LoginController extends Controller
{
    /** Intentos fallidos seguidos antes de bloquear. */
    private const INTENTOS = 5;

    /** Cuánto dura el bloqueo: 15 minutos. */
    private const BLOQUEO_SEGUNDOS = 900;

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

        // Sin límite se podían probar contraseñas sin freno. Va por correo + IP
        // y no solo por IP: en la Gobernación muchos salen a internet por la
        // misma, y un tope por IP dejaría afuera a todos por los errores de uno.
        $clave = Str::transliterate(Str::lower($credentials['email'])).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($clave, self::INTENTOS)) {
            event(new Lockout($request));

            $minutos = (int) ceil(RateLimiter::availableIn($clave) / 60);

            return back()
                ->withErrors(['email' => "Demasiados intentos fallidos. Probá de nuevo en {$minutos} minuto(s)."])
                ->onlyInput('email');
        }

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($clave, self::BLOQUEO_SEGUNDOS);

            return back()
                ->withErrors(['email' => 'Las credenciales no coinciden con ningún registro.'])
                ->onlyInput('email');
        }

        RateLimiter::clear($clave);

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
