<?php

namespace App\Http\Requests;

use App\Models\AsignacionHorario;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Reglas para concluir una asignación de horario: se le cambia la fecha `hasta`,
 * con lo que deja de estar vigente. No borra nada, así el historial sigue
 * explicando qué horario tenía el funcionario en cada período.
 */
class ConcluirAsignacionHorarioRequest extends FormRequest
{
    /**
     * Se autoriza acá y no solo en el controlador: la validación corre antes,
     * así que un usuario sin permiso recibiría errores de campos en vez del 403.
     */
    public function authorize(): bool
    {
        $asignacion = $this->route('asignacion');

        return $asignacion instanceof AsignacionHorario
            && ($this->user()?->can('update', $asignacion) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $asignacion = $this->route('asignacion');

        return [
            // No puede terminar antes de haber empezado.
            'hasta' => ['required', 'date', 'after_or_equal:'.$asignacion->desde->toDateString()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'hasta.after_or_equal' => 'La fecha de fin no puede ser anterior al inicio de la asignación.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'hasta' => 'fecha de fin',
        ];
    }
}
