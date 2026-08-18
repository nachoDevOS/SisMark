<?php

namespace App\Http\Requests;

use App\Models\Turno;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Reglas de validación para dar de alta un equipo biométrico, consistentes
 * con la estructura de la tabla `equipos`.
 */
class StoreEquipoRequest extends FormRequest
{
    /**
     * La autorización la resuelve el middleware `auth` de la ruta; aquí solo
     * dejamos pasar la petición ya autenticada.
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
            'nombre' => ['required', 'string', 'max:255'],
            'ip' => [
                'required',
                'ip',
                // Un mismo equipo (ip + puerto) no puede registrarse dos veces.
                Rule::unique('equipos')->where(fn ($query) => $query->where('puerto', $this->input('puerto'))),
            ],
            'puerto' => ['required', 'integer', 'min:1', 'max:65535'],
            'comm_key' => ['required', 'integer', 'min:0'],
            'ubicacion' => ['nullable', 'string', 'max:255'],
            'activo' => ['boolean'],
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
     * Los checkboxes ausentes en el formulario no llegan en la petición: los
     * normalizamos a booleanos antes de validar. Las horas vacías del repetidor
     * se descartan acá para que no cuenten como una hora inválida, y los días
     * se pasan a enteros para que la comparación con `Turno::DIAS` no dependa
     * de que el formulario mande «3» o 3.
     */
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
