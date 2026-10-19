<?php

namespace App\Http\Requests;

use App\Models\Licencia;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Reglas para resolver una solicitud de licencia: aprobarla o rechazarla.
 *
 * La decisión no viaja en el cuerpo, la define la ruta (`aprobar` / `rechazar`):
 * así un formulario manipulado no puede convertir un rechazo en una aprobación.
 * Lo único que se recibe es la nota de la revisión.
 */
class RevisarLicenciaRequest extends FormRequest
{
    /**
     * Además del permiso, se exige que la licencia esté en un estado que se
     * pueda resolver: aprobar, solo lo «Pendiente»; rechazar, lo «Pendiente» y
     * también lo «Aprobado».
     *
     * Esa condición no puede vivir en la policy: el `Gate::before` de
     * `AppServiceProvider` le concede todo al rol Administrador sin llegar a
     * ejecutarla, y el Administrador es justamente quien resuelve las solicitudes.
     *
     * Rechazar una aprobada cambia hacia atrás el cálculo de asistencia: esos
     * días dejan de estar justificados. Por eso exige motivo, como cualquier
     * rechazo. Lo rechazado no vuelve atrás: ni se aprueba ni se elimina.
     */
    public function authorize(): bool
    {
        $licencia = $this->route('licencia');

        return $licencia instanceof Licencia
            && ($licencia->esPendiente || ($this->rechaza() && $licencia->esAprobada))
            && ($this->user()?->can('approve', $licencia) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Obligatoria al rechazar: el funcionario ve esta nota en su perfil
            // y, sin ella, se entera de que le negaron el permiso pero no de por
            // qué. Al aprobar es opcional —no hay nada que explicar—.
            'observacion' => [
                $this->rechaza() ? 'required' : 'nullable',
                'string',
                'max:500',
            ],
        ];
    }

    /**
     * ¿El envío es un rechazo? Sale de la ruta, no del cuerpo.
     */
    public function rechaza(): bool
    {
        return $this->routeIs('licencias.rechazar');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'observacion.required' => 'Escribí el motivo del rechazo: es lo que va a leer el funcionario.',
            'observacion.max' => 'El motivo no puede superar los 500 caracteres.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['observacion' => 'motivo'];
    }
}
