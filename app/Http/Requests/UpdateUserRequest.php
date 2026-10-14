<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Reglas de validación para editar un usuario del panel.
 *
 * Igual que el alta, pero la contraseña es opcional (vacía = se conserva la
 * actual) y el correo único ignora al propio usuario.
 */
class UpdateUserRequest extends FormRequest
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
        /** @var User $user */
        $user = $this->route('usuario');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user)],
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
            'rol' => ['required', 'integer', 'exists:roles,id'],
        ];
    }
}
