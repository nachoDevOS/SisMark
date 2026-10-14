<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Inhabilitar un usuario pide el motivo: queda en su historial.
 */
class InhabilitarUserRequest extends FormRequest
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
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motivo.required' => 'Escribí el motivo de la inhabilitación.',
            'motivo.min' => 'El motivo debe tener al menos 5 caracteres.',
        ];
    }
}
