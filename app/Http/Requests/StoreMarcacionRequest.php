<?php

namespace App\Http\Requests;

use App\Models\Asistencia;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación de una marcación manual (tipo M) creada desde la pantalla de
 * Marcaciones. El CI debe pertenecer a un funcionario local.
 *
 * La observación es obligatoria: una marcación manual es la única que no viene
 * del reloj, así que tiene que quedar escrito por qué se cargó a mano —papeleta,
 * olvido del funcionario, equipo caído—. Sin eso, en el informe procesado
 * aparece indistinguible de una marcación real y no hay a quién preguntarle.
 */
class StoreMarcacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Asistencia::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ci' => ['required', 'string', Rule::exists('personas', 'ci')],
            'fecha' => ['required', 'date'],
            'hora' => ['required', 'date_format:H:i,H:i:s'],
            'observacion' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ci.exists' => 'No existe un funcionario con ese CI.',
            'observacion.required' => 'Indique el motivo de la marcación manual.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'observacion' => 'observación',
        ];
    }
}
