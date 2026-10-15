<?php

namespace App\Http\Requests;

use App\Models\Equipo;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Importación de un CSV de marcaciones, desde Biométricos o desde Marcaciones.
 *
 * Queda en la bitácora de equipos: se escribe por qué se sube y se declara
 * que las marcaciones son auténticas. No se elige equipo: el CSV no dice de
 * qué reloj salió, y pedirlo sería inventar el dato. El motivo pide un mínimo de 15
 * caracteres para que sea una explicación y no un «ok».
 */
class ImportarMarcacionesEquipoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('import', Equipo::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Solo el CSV del sistema y el `.dat` que baja el reloj por USB. Se mira la
            // extensión: los dos son texto plano y el tipo MIME no los distingue.
            'archivo' => ['required', 'file', 'extensions:csv,dat', 'max:10240'],
            'motivo' => ['required', 'string', 'min:15', 'max:500'],
            'consentimiento' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'archivo' => 'archivo',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motivo.required' => 'Escribí por qué subís estas marcaciones: queda en la bitácora.',
            'motivo.min' => 'El motivo tiene que tener al menos 15 caracteres.',
            'archivo.extensions' => 'El archivo tiene que ser .csv o el .dat que baja el reloj por USB.',
            'consentimiento.accepted' => 'Tenés que marcar la declaración para importar.',
        ];
    }
}
