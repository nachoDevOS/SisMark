<?php

namespace App\Models;

use App\Http\Controllers\SistemaExternoController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una entrada de la bitácora de tokens de la API.
 *
 * La escribe sola {@see SistemaExternoController} cada vez
 * que alguien emite o revoca una credencial. No se edita ni se borra: es el
 * registro de quién entregó acceso a la asistencia de todo el personal y
 * cuándo.
 */
class SistemaExternoAuditoria extends Model
{
    protected $table = 'sistema_externo_auditorias';

    /**
     * Una fila que no se actualiza nunca no necesita `updated_at`; la migración
     * declara solo `created_at`.
     */
    public const UPDATED_AT = null;

    /** Se generó una credencial nueva. */
    public const ACCION_EMITIR = 'emitir';

    /**
     * Se mató una credencial viva.
     *
     * Emitir revoca lo anterior, así que la mayoría de las revocaciones vienen
     * en par con una emisión. Las que van solas son las importantes: alguien
     * cortó el acceso y no entregó nada a cambio.
     */
    public const ACCION_REVOCAR = 'revocar';

    /**
     * @var array<string, string>
     */
    public const ACCIONES = [
        self::ACCION_EMITIR => 'Token emitido',
        self::ACCION_REVOCAR => 'Token revocado',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'sistema_externo_id',
        'token_id',
        'accion',
        'alcances',
        'user_id',
        'ip',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function sistema(): BelongsTo
    {
        return $this->belongsTo(SistemaExterno::class, 'sistema_externo_id');
    }

    /**
     * Quién lo hizo. Queda `null` si a esa persona se la dio de baja después:
     * la bitácora no se toca, así que el nombre puede faltar y la fila sigue
     * valiendo.
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function getAccionEtiquetaAttribute(): string
    {
        return self::ACCIONES[$this->accion] ?? $this->accion;
    }
}
