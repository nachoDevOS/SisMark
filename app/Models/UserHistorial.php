<?php

namespace App\Models;

use App\Traits\RegistersUserEvents;
use Database\Factories\UserHistorialFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un evento del historial de un usuario: qué se hizo, quién lo hizo
 * (`registerUser_id`, lo llena el trait) y la foto legible del usuario antes y
 * después. Los valores se guardan ya traducidos —nombres de rol, «Sí»/«No»—
 * para que el historial siga diciendo lo que era aunque después cambie o se
 * borre lo referenciado. La contraseña nunca entra.
 */
#[Fillable(['user_id', 'accion', 'antes', 'despues'])]
class UserHistorial extends Model
{
    /** @use HasFactory<UserHistorialFactory> */
    use HasFactory, RegistersUserEvents;

    public const ACCION_CREADO = 'creado';

    public const ACCION_ACTUALIZADO = 'actualizado';

    /** El propio usuario cambió su contraseña desde su pantalla. */
    public const ACCION_CAMBIO_PASSWORD = 'cambio_password';

    public const ACCION_INHABILITADO = 'inhabilitado';

    public const ACCION_HABILITADO = 'habilitado';

    public const ACCION_ELIMINADO = 'eliminado';

    /**
     * Título de cada acción en la línea de tiempo.
     *
     * @var array<string, string>
     */
    public const ETIQUETAS = [
        self::ACCION_CREADO => 'Usuario creado',
        self::ACCION_ACTUALIZADO => 'Datos actualizados',
        self::ACCION_CAMBIO_PASSWORD => 'Cambió su contraseña',
        self::ACCION_INHABILITADO => 'Usuario inhabilitado',
        self::ACCION_HABILITADO => 'Usuario habilitado',
        self::ACCION_ELIMINADO => 'Usuario eliminado',
    ];

    /**
     * Lo que se muestra en lugar de la contraseña cuando cambia.
     */
    public const PASSWORD_ANTES = '••••••••';

    public const PASSWORD_DESPUES = 'Actualizada';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'antes' => 'array',
            'despues' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registerUser_id')->withTrashed();
    }

    /**
     * Foto legible del usuario tal como está ahora en la base.
     *
     * @return array<string, string>
     */
    public static function foto(User $usuario): array
    {
        $usuario->load('roles', 'registradoPor');

        return [
            'Nombre' => $usuario->name,
            'Email' => $usuario->email,
            'Roles' => $usuario->roles->pluck('name')->sort()->implode(', ') ?: 'Sin roles',
            'Foto' => $usuario->avatar_path ? 'Sí' : 'Sin foto',
            'Debe cambiar contraseña' => $usuario->cambiar_password ? 'Sí' : 'No',
            'Estado' => $usuario->activo ? 'Activo' : 'Inactivo',
            'Registrado por' => $usuario->registradoPor?->name ?? 'Sistema',
        ];
    }

    /**
     * Guarda un evento. Una edición que no cambió nada no se registra: solo
     * ensuciaría la línea de tiempo.
     *
     * @param  array<string, string>|null  $antes
     * @param  array<string, string>|null  $despues
     */
    public static function registrar(User $usuario, string $accion, ?array $antes, ?array $despues): ?self
    {
        if ($antes !== null && $antes === $despues) {
            return null;
        }

        return self::create([
            'user_id' => $usuario->id,
            'accion' => $accion,
            'antes' => $antes,
            'despues' => $despues,
        ]);
    }

    /**
     * Solo los campos que difieren entre antes y después.
     *
     * @return array<string, array{antes: ?string, despues: ?string}>
     */
    public function cambios(): array
    {
        $antes = $this->antes ?? [];
        $despues = $this->despues ?? [];
        $cambios = [];

        foreach (array_unique([...array_keys($antes), ...array_keys($despues)]) as $campo) {
            if (($antes[$campo] ?? null) !== ($despues[$campo] ?? null)) {
                $cambios[$campo] = ['antes' => $antes[$campo] ?? null, 'despues' => $despues[$campo] ?? null];
            }
        }

        return $cambios;
    }
}
