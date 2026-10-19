<?php

namespace App\Http\Requests;

use App\Models\Licencia;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Reglas para resolver varias solicitudes pendientes de una vez, desde el
 * listado de licencias: las marcadas en la página, o todas las que coinciden
 * con el filtro.
 *
 * Igual que {@see RevisarLicenciaRequest}, la decisión la da la ruta
 * (`lote.aprobar` / `lote.rechazar`) y no el cuerpo. El estado no se valida
 * acá: la resolución solo toca lo que sigue «Pendiente», así que marcar algo
 * ya resuelto no tiene efecto.
 */
class ResolverLoteLicenciasRequest extends FormRequest
{
    /**
     * El mismo permiso que resolver de a una. Va contra la habilidad y no
     * contra la policy porque no hay una licencia puntual que pasarle.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('Approve:Licencia') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'todas' => ['boolean'],
            // Las filas marcadas: el id de la fila que abre cada solicitud. Sin
            // «todas», al menos una; con «todas» se ignoran y manda el filtro.
            'solicitudes' => [Rule::excludeIf(fn (): bool => $this->boolean('todas')), 'required', 'array', 'min:1', 'max:1000'],
            'solicitudes.*' => ['integer'],
            // El filtro con el que se armó la tabla: con «todas» define el
            // alcance, y en los dos casos es a dónde se vuelve.
            'q' => ['nullable', 'string', 'max:100'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
            'tipo' => ['nullable', Rule::in(array_keys(Licencia::TIPOS))],
            'estado' => ['nullable', Rule::in(Licencia::ESTADOS)],
            // Obligatoria al rechazar: cada funcionario la lee en su perfil.
            'observacion' => [$this->rechaza() ? 'required' : 'nullable', 'string', 'max:500'],
        ];
    }

    /**
     * ¿El envío es un rechazo? Sale de la ruta, no del cuerpo.
     */
    public function rechaza(): bool
    {
        return $this->routeIs('licencias.lote.rechazar');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'solicitudes.required' => 'Marcá al menos una solicitud.',
            'observacion.required' => 'Escribí el motivo del rechazo: es lo que va a leer cada funcionario.',
            'observacion.max' => 'El motivo no puede superar los 500 caracteres.',
        ];
    }
}
