<?php

namespace App\Http\Requests;

use App\Models\Turno;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Reglas de validación para la sincronización automática de un equipo: si
 * corre, en qué días y a qué horas.
 *
 * Va aparte de la edición del equipo porque la pide otro permiso
 * (`Sync:Equipo`): quién corrige la IP de un reloj no tiene por qué ser quién
 * decide cuándo se bajan sus marcaciones.
 */
class ActualizarSincronizacionEquipoRequest extends FormRequest
{
    /**
     * La autorización la resuelve el controlador con la policy del equipo.
     */
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
            'sync_automatica' => ['boolean'],
            // Con la sincronización automática encendida hace falta al menos
            // una hora: si no, la tarea programada no tendría cuándo correr y
            // el equipo quedaría marcado como automático sin serlo.
            'sync_horarios' => ['array', 'max:24', 'required_if:sync_automatica,true'],
            'sync_horarios.*' => ['required', 'date_format:H:i'],
            // Días de la semana en los que corre, con la numeración de
            // `Turno::DIAS`. A diferencia de las horas **no** es obligatorio:
            // sin días elegidos el equipo trabaja todos, que es la conducta que
            // ya tenían los equipos configurados antes de que existiera el
            // campo.
            'sync_dias' => ['array', 'max:7'],
            'sync_dias.*' => [Rule::in(array_keys(Turno::DIAS))],
        ];
    }

    /**
     * El checkbox ausente no llega en la petición: se normaliza a booleano
     * antes de validar. Las horas vacías del repetidor se descartan acá para
     * que no cuenten como una hora inválida, y los días se pasan a enteros para
     * que la comparación con `Turno::DIAS` no dependa de que el formulario
     * mande «3» o 3.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
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
            'sync_horarios.required_if' => 'Indique al menos una hora de sincronización.',
            'sync_horarios.*.date_format' => 'Cada hora va en formato HH:MM.',
            'sync_dias.*.in' => 'Alguno de los días marcados no es un día de la semana válido.',
        ];
    }
}
