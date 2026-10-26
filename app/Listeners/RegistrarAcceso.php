<?php

namespace App\Listeners;

use App\Models\Actividad;
use App\Models\User;
use App\Services\RegistroActividad;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

/**
 * Entradas y salidas del sistema en la actividad de usuarios: quién entró,
 * quién intentó y con qué correo, quién quedó bloqueado y quién salió.
 *
 * Laravel lo descubre solo: cada método `handle…` atiende el evento de su tipo.
 * La contraseña nunca se guarda, ni la equivocada.
 */
class RegistrarAcceso
{
    /**
     * Marca del pedido en el que se rechazó una cuenta inhabilitada: su `Logout`
     * no es una salida, es el mismo rechazo.
     */
    private const RECHAZADO = 'actividad.ingreso_rechazado';

    public function __construct(private RegistroActividad $registro) {}

    public function handleLogin(Login $evento): void
    {
        $usuario = $evento->user;

        // La contraseña era correcta pero la cuenta está inhabilitada:
        // LoginController la saca en el acto. No entró.
        if ($usuario instanceof User && ! $usuario->activo) {
            request()->attributes->set(self::RECHAZADO, true);
            $this->registro->registrarEvento(
                Actividad::INGRESO_RECHAZADO, 'Acceso', 'Intentó entrar con la cuenta inhabilitada',
                usuario: $usuario, email: $usuario->email,
            );

            return;
        }

        $this->registro->registrarEvento(
            Actividad::INGRESO, 'Acceso', 'Entró al sistema',
            usuario: $usuario instanceof User ? $usuario : null,
            datos: ['recordarme' => $evento->remember],
        );
    }

    public function handleFailed(Failed $evento): void
    {
        $email = (string) ($evento->credentials['email'] ?? '');
        $motivo = $evento->user === null ? 'el correo no existe' : 'contraseña incorrecta';

        $this->registro->registrarEvento(
            Actividad::INGRESO_FALLIDO, 'Acceso', "Intento de ingreso fallido con {$email}: {$motivo}",
            usuario: $evento->user instanceof User ? $evento->user : null,
            email: $email,
        );
    }

    public function handleLockout(Lockout $evento): void
    {
        $email = (string) $evento->request->input('email', '');

        $this->registro->registrarEvento(
            Actividad::BLOQUEO, 'Acceso', "Bloqueado por demasiados intentos fallidos con {$email}",
            email: $email,
        );
    }

    public function handleLogout(Logout $evento): void
    {
        if (request()->attributes->get(self::RECHAZADO)) {
            return;
        }

        $this->registro->registrarEvento(
            Actividad::SALIDA, 'Acceso', 'Cerró sesión',
            usuario: $evento->user instanceof User ? $evento->user : null,
        );
    }
}
