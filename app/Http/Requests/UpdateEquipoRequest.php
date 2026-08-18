<?php

namespace App\Http\Requests;

use App\Models\Equipo;
use App\Models\Turno;
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
            'sync_automatica' => ['boolean'],
            'sync_horarios' => ['array', 'max:24', 'required_if:sync_automatica,true'],
            'sync_horarios.*' => ['required', 'date_format:H:i'],
            // Sin días marcados el equipo trabaja todos: ver el comentario en
            // {@see StoreEquipoRequest}.
            'sync_dias' => ['array', 'max:7'],
            'sync_dias.*' => [Rule::in(array_keys(Turno::DIAS))],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'activo' => $this->boolean('activo'),
            'sync_automatica' => $this->boolean('sync_automatica'),
            'sync_horarios' => array_values(array_filter(
                (array) $this->input('sync_horarios', []),
                fn ($hora): bool => filled($hora),
            )),
            'sync_dias' => array_values(array_map(
                fn ($dia): int => (int) $dia,
                array_filter((array) $this->input('sync_dias', []), fn ($dia): bool => filled($dia)),
            )),
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
            'sync_horarios.required_if' => 'Indique al menos una hora de sincronización.',
            'sync_horarios.*.date_format' => 'Cada hora va en formato HH:MM.',
            'sync_dias.*.in' => 'Alguno de los días marcados no es un día de la semana válido.',
        ];
    }
}
