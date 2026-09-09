<?php

namespace App\Http\Requests;

use App\Models\Turno;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Reglas de validación para dar de alta un horario (turno) en la tabla
 * DiaTurnos del SIA. Respeta los campos NOT NULL y tamaños de la tabla legada.
 * Las horas llegan del formulario como "HH:MM" (input type=time); el
 * controlador las convierte a datetime sobre la fecha base 1899-12-30.
 */
class StoreDiaTurnoRequest extends FormRequest
{
    /**
     * La autorización la resuelve la policy en el controlador.
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
            'Dia' => ['required', Rule::in(array_map('strval', array_keys(Turno::DIAS)))],
            'NombreTurno' => ['required', 'string', 'max:25'],
            'HEntrada' => ['required', 'date_format:H:i'],
            'HTolerancia' => ['required', 'date_format:H:i'],
            'EMinima' => ['required', 'date_format:H:i'],
            'EMaxima' => ['required', 'date_format:H:i'],
            'HSalida' => ['required', 'date_format:H:i'],
            'STolerancia' => ['required', 'date_format:H:i'],
            'SMinima' => ['required', 'date_format:H:i'],
            'SMaxima' => ['required', 'date_format:H:i'],
            'HTrabajadas' => ['required', 'numeric', 'min:0', 'max:24'],
            'SiguienteDia' => ['nullable', 'boolean'],
            'Sugerido' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Los checkbox llegan solo cuando están marcados; se normalizan a booleano
     * para los campos bit NOT NULL de la tabla.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'SiguienteDia' => $this->boolean('SiguienteDia'),
            'Sugerido' => $this->boolean('Sugerido'),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'Dia' => 'día',
            'NombreTurno' => 'nombre del horario',
            'HEntrada' => 'hora de entrada',
            'HTolerancia' => 'tolerancia de entrada',
            'EMinima' => 'mínima hora de entrada',
            'EMaxima' => 'máxima hora de entrada',
            'HSalida' => 'hora de salida',
            'STolerancia' => 'tolerancia de salida',
            'SMinima' => 'mínima hora de salida',
            'SMaxima' => 'máxima hora de salida',
            'HTrabajadas' => 'horas trabajadas',
            'SiguienteDia' => 'salida al día siguiente',
            'Sugerido' => 'horario sugerido',
        ];
    }
}
