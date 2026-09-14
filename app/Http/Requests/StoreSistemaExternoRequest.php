<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
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
            // token, así que no puede traer espacios ni acentos. Sale de
            // {@see slugDelNombre()}, que ya garantiza la forma; las reglas
            // quedan porque son las que convierten un nombre sin una sola letra
            // ni número —que no produce slug— en un error de pantalla en vez de
            // un `required` violado en la base.
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
     * booleano NOT NULL de la tabla. Y el slug **no se lee del formulario**: se
     * arma acá a partir del nombre.
     *
     * Escribirlo a mano era pedir dos veces lo mismo y dejaba que se separaran
     * —«Recursos Humanos» con nombre corto `sedag`—, y lo que se ve en la
     * consola y en el nombre del token es el corto, así que esa deriva se paga
     * al mirar un token y no saber de quién es. El campo de la pantalla queda
     * de solo lectura y lo que llegue ahí se ignora: esto es lo que manda.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'slug' => $this->slugDelNombre(),
            'activo' => $this->boolean('activo'),
        ]);
    }

    /**
     * El nombre corto que le corresponde al nombre: minúsculas sin acentos, y
     * guiones donde había espacios o signos.
     *
     * El nombre admite 100 caracteres y la columna del slug 50, así que se
     * recorta sin dejar el guión colgando.
     */
    private function slugDelNombre(): string
    {
        return rtrim(mb_substr(Str::slug((string) $this->input('nombre')), 0, 50), '-');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        // Hablan del nombre y no del nombre corto: el corto no se escribe, así
        // que lo único que el que da de alta puede corregir es el nombre.
        return [
            'slug.required' => 'El nombre no da ningún nombre corto: tiene que tener al menos una letra o un número.',
            'slug.regex' => 'El nombre no da ningún nombre corto: tiene que tener al menos una letra o un número.',
            'slug.unique' => 'Ya hay un sistema registrado con ese nombre corto: cambiá el nombre.',
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
