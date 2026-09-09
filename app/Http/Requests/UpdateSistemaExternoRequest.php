<?php

namespace App\Http\Requests;

/**
 * Edición de un sistema consumidor.
 *
 * Mismas reglas que el alta salvo el slug, que acá no se valida a propósito: es
 * el nombre con el que el sistema se conoce en la consola y con el que quedó
 * bautizado el token ya entregado, y cambiarlo dejaría la credencial viva
 * apuntando a un nombre que ya no existe. Para renombrar se da de baja y se
 * registra de nuevo.
 *
 * Al no tener regla, el slug tampoco sale por `validated()`, así que un `slug`
 * agregado a mano al formulario no llega al `update()`.
 */
class UpdateSistemaExternoRequest extends StoreSistemaExternoRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_diff_key(parent::rules(), ['slug' => null]);
    }
}
