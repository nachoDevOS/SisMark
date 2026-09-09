<?php

namespace App\Traits;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

/**
 * Auditoría de alta y baja en el propio modelo: quién registró la fila y, al
 * eliminarla, quién la dio de baja y por qué.
 *
 * El motivo llega como `deleteObservacion` desde el modal global de
 * eliminación. Ningún controlador toca estas columnas.
 */
trait RegistersUserEvents
{
    protected static function bootRegistersUserEvents(): void
    {
        static::creating(function (Model $model): void {
            $model->registerUser_id = self::autorId();
        });

        static::deleting(function (Model $model): void {
            if (self::autorId() === null) {
                return;
            }

            // Las columnas de auditoría de baja acompañan a la eliminación
            // lógica: viven en las tablas cuyo modelo usa SoftDeletes. En las
            // que no —`asistencias`, donde no se dan de baja marcaciones—, el
            // borrado es físico y no hay dónde guardar quién ni por qué;
            // escribirlas igual reventaría con «Unknown column».
            if (! in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
                return;
            }

            $model->deleteUser_id = self::autorId();
            $model->deleteObservacion = request()->input('deleteObservacion');

            $model->save();
        });
    }

    /**
     * Id de la **persona** que está haciendo el cambio, o `null` si no la hay.
     *
     * Estas columnas son claves foráneas a `users`, así que solo se llenan
     * cuando quien está autenticado es un usuario del sistema. En la API v1 el
     * autenticado es un `SistemaExterno` —un token de máquina, no una persona—:
     * ahí `Auth::id()` ni siquiera existe, porque el modelo no implementa
     * `Authenticatable`, y llamarlo reventaba con «Call to undefined method
     * getAuthIdentifier()» al crear cualquier fila desde la API.
     *
     * Sin autor, la fila queda con `registerUser_id` nulo, que es exactamente lo
     * que corresponde: no la creó nadie de adentro.
     */
    private static function autorId(): ?int
    {
        $autenticado = Auth::user();

        return $autenticado instanceof User ? $autenticado->getKey() : null;
    }
}
