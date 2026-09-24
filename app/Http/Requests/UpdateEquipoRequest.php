<?php

namespace App\Http\Requests;

use App\Models\Equipo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Reglas de validación para editar un equipo biométrico existente.
 *
 * Igual que el alta, pero la regla `unique` ignora al propio equipo para que
 * pueda guardarse sin cambiar su IP/puerto.
 */
class UpdateEquipoRequest extends FormRequest
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
        /** @var Equipo $equipo */
        $equipo = $this->route('equipo');

        return [
            'nombre' => ['required', 'string', 'max:255'],
            'ip' => [
                'required',
                'ip',
                Rule::unique('equipos')
                    ->where(fn ($query) => $query->where('puerto', $this->input('puerto')))
                    ->ignore($equipo),
            ],
            'puerto' => ['required', 'integer', 'min:1', 'max:65535'],
            'comm_key' => ['required', 'integer', 'min:0'],
            'ubicacion' => ['nullable', 'string', 'max:255'],
            'activo' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'activo' => $this->boolean('activo'),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ip.unique' => 'Ya existe un equipo registrado con esa IP y puerto.',
            'ip.ip' => 'La dirección IP no es válida.',
        ];
    }
}
