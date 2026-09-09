<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta de un sistema que consume la API de asistencia.
 *
 * La autorización la resuelve la policy desde el controlador, como en el resto
 * del sistema.
 */
class StoreSistemaExternoRequest extends FormRequest
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
            // Minúsculas, números y guiones: el slug se escribe en la consola
            // (`php artisan sismark:token mamore`) y se usa para nombrar el
            // token, así que no puede traer espacios ni acentos.
            'slug' => [
                'required', 'string', 'max:50', 'regex:/^[a-z0-9-]+$/',
                // Solo choca contra los que siguen en pie. Un slug de un sistema
                // dado de baja **no** se rechaza: el controlador lo reactiva, y
                // rechazarlo dejaría ese nombre corto quemado para siempre sin
                // ninguna pantalla desde donde recuperarlo.
                //
                // El índice de la tabla no distingue `deleted_at`, así que la
                // reactivación no es una comodidad: sin ella, insertar encima
                // revienta con clave duplicada.
                Rule::unique('sistemas_externos', 'slug')->withoutTrashed(),
            ],
            'nombre' => ['required', 'string', 'max:100'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
            'activo' => ['nullable', 'boolean'],
        ];
    }

    /**
     * La casilla llega solo cuando está marcada; se normaliza para el campo
     * booleano NOT NULL de la tabla. Y el slug se normaliza antes de validar,
     * para que «Mamoré » no falle por una mayúscula o un espacio de más.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'slug' => mb_strtolower(trim((string) $this->input('slug'))),
            'activo' => $this->boolean('activo'),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'El nombre corto solo admite minúsculas, números y guiones.',
            'slug.unique' => 'Ya hay un sistema registrado con ese nombre corto.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'slug' => 'nombre corto',
            'nombre' => 'nombre',
            'observaciones' => 'observaciones',
            'activo' => 'activo',
        ];
    }
}
