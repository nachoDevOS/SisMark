<?php

namespace App\Models;

use App\Traits\RegistersUserEvents;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'avatar_path'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, RegistersUserEvents, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    /**
     * Los mismos defaults de la base, para que un usuario recién creado no los
     * vea en `null` hasta recargarlo.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'cambiar_password' => false,
        'activo' => true,
    ];

    /**
     * Foto de perfil, o la silueta por defecto si no subió ninguna.
     *
     * @return Attribute<string, never>
     */
    protected function avatarUrl(): Attribute
    {
        return Attribute::get(fn (): string => $this->avatar_path
            ? asset('storage/'.$this->avatar_path)
            : asset('image/user-default.png'));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registerUser_id')->withTrashed();
    }

    /**
     * Eventos del historial, del más nuevo al más viejo.
     *
     * @return HasMany<UserHistorial, $this>
     */
    public function historial(): HasMany
    {
        return $this->hasMany(UserHistorial::class)->latest()->latest('id');
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'cambiar_password' => 'boolean',
            'activo' => 'boolean',
        ];
    }
}
