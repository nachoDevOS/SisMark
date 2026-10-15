<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Reglas de validación para dar de alta un día excepcional. Una fila por
 * fecha: `fecha` es única (índice natural de la tabla `dias_excepcionales`).
 */
class StoreDiaExcepcionalRequest extends FormRequest
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
            // Las filas vacías del calendario del SIA no cuentan: el alta
            // completa esa fila. Las eliminadas tampoco.
            'fecha' => [
                'required',
                'date',
                Rule::unique('dias_excepcionales', 'fecha')
                    ->whereNotNull('motivoInasistencia')
                    ->withoutTrashed(),
            ],
            'motivoInasistencia' => ['required', 'string', 'max:255'],
            // Respaldo del día: decreto, resolución o memorándum. Opcional —hay
            // tolerancias que se cargan por instrucción verbal y el documento
            // llega después— y, cuando viene, acotado a imagen o PDF.
            'respaldo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'observacion' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * La fecha se normaliza a `Y-m-d` (la columna es date) para que la unicidad
     * compare por día y coincida entre validación y almacenamiento.
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('fecha')) {
            $this->merge([
                'fecha' => Carbon::parse($this->input('fecha'))->toDateString(),
            ]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fecha.unique' => 'Ya existe un día excepcional registrado para esa fecha. Si está dado de baja, activalo desde el listado.',
            'respaldo.mimes' => 'El respaldo debe ser una imagen (JPG o PNG) o un PDF.',
            'respaldo.max' => 'El respaldo no puede pesar más de 5 MB.',
        ];
    }
}
