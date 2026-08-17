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
     * Además del permiso, se exige que la licencia siga «Pendiente».
     *
     * Esa condición no puede vivir en la policy: el `Gate::before` de
     * `AppServiceProvider` le concede todo al rol super_admin sin llegar a
     * ejecutarla, y super_admin es justamente quien resuelve las solicitudes.
     *
     * Lo ya resuelto no se revierte desde la ficha: una licencia aprobada
     * descuenta la ausencia en el cálculo de asistencia, y darla vuelta después
     * cambiaría hacia atrás reportes que Recursos Humanos ya firmó. Si hubo un
     * error, la baja lógica es el camino.
     */
    public function authorize(): bool
    {
        $licencia = $this->route('licencia');

        return $licencia instanceof Licencia
            && $licencia->esPendiente
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
