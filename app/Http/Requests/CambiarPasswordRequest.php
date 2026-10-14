<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cambio de contraseña del propio usuario: pide la actual (la que le dieron)
 * y una nueva distinta, escrita dos veces.
 */
class CambiarPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'password_actual' => ['required', 'current_password'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed', 'different:password_actual'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password_actual.current_password' => 'La contraseña actual no es correcta.',
            'password.different' => 'La contraseña nueva tiene que ser distinta de la actual.',
            'password.confirmed' => 'Las dos contraseñas nuevas no coinciden.',
            'password.min' => 'La contraseña nueva debe tener al menos 8 caracteres.',
        ];
    }
}
