<?php

namespace App\Http\Requests;

use App\Exceptions\MamoreException;
use App\Models\AsignacionTurno;
use App\Models\Turno;
use App\Services\AsignadorTurnos;
use App\Services\ContratosFuncionario;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Reglas para asignarle un turno a un funcionario, con fecha de inicio y de fin.
 *
 * Con Mamoré configurado, el turno se ata a un **contrato firmado vigente** de la
 * persona y sus fechas tienen que caber en él: así las adendas y anulaciones de
 * Mamoré lo mueven solas, y nunca queda un turno corriendo más allá del contrato
 * que choque con el de la renovación. Sin Mamoré se asigna sin contrato.
 */
class StoreAsignacionTurnoRequest extends FormRequest
{
    /**
     * Se autoriza acá y no solo en el controlador: la validación corre antes,
     * así que un usuario sin permiso recibiría errores de campos en vez del 403.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', AsignacionTurno::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ci' => ['required', 'string', 'max:12'],
            'turno_id' => ['required', 'integer', Rule::exists('turnos', 'id')->whereNull('deleted_at')],
            'contrato_id' => ['nullable', 'integer', 'min:1'],
            'desde' => ['required', 'date'],
            'hasta' => ['required', 'date', 'after_or_equal:desde'],
            'observacion' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Que no se pise con otro turno de la persona ni con lo heredado, y que no
     * choque con la única del detalle ({@see AsignadorTurnos::conflicto()}).
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $this->validarContrato($validator);

                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $conflicto = app(AsignadorTurnos::class)->conflicto(
                    (string) $this->input('ci'),
                    Turno::query()->with('horarios')->findOrFail($this->integer('turno_id')),
                    $this->date('desde'),
                    $this->date('hasta'),
                );

                if ($conflicto !== null) {
                    $validator->errors()->add('turno_id', $conflicto);
                }
            },
        ];
    }

    /**
     * El contrato tiene que ser un firmado vigente de esa persona, sin turno
     * todavía, y las fechas tienen que caber en él.
     */
    private function validarContrato(Validator $validator): void
    {
        try {
            $asignables = app(ContratosFuncionario::class)->asignables((string) $this->input('ci'));
        } catch (MamoreException $e) {
            $validator->errors()->add('contrato_id', 'No se pudo verificar el contrato en Mamoré: '.$e->getMessage());

            return;
        }

        // Mamoré sin configurar: se asigna sin contrato, como antes.
        if ($asignables === null) {
            return;
        }

        if (! $this->filled('contrato_id')) {
            $validator->errors()->add('contrato_id', $asignables === []
                ? 'El funcionario no tiene contrato firmado vigente en Mamoré: no se le puede asignar turno.'
                : 'Elegí el contrato al que corresponde el turno.');

            return;
        }

        $contrato = collect($asignables)->firstWhere('id', $this->integer('contrato_id'));

        if ($contrato === null) {
            $validator->errors()->add('contrato_id', 'Ese contrato no es un contrato firmado vigente de este funcionario.');

            return;
        }

        $asignado = AsignacionTurno::query()->with('turno')->delContrato($contrato['id'])->first();

        if ($asignado !== null) {
            $validator->errors()->add('contrato_id', 'Ese contrato ya tiene el turno «'.$asignado->turno?->nombre.'» del '
                .$asignado->desde->format('d/m/Y').' al '.$asignado->hasta->format('d/m/Y').'.');

            return;
        }

        if ($this->date('desde')->lessThan($contrato['desde'])) {
            $validator->errors()->add('desde', 'La fecha de inicio no puede ser anterior al inicio del contrato ('.$contrato['desde']->format('d/m/Y').').');
        }

        if ($contrato['hasta'] !== null && $this->date('hasta')->greaterThan($contrato['hasta'])) {
            $validator->errors()->add('hasta', 'La fecha de fin no puede pasar el fin del contrato ('.$contrato['hasta']->format('d/m/Y').').');
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'hasta.after_or_equal' => 'La fecha de fin no puede ser anterior a la de inicio.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'ci' => 'funcionario',
            'turno_id' => 'turno',
            'contrato_id' => 'contrato',
            'desde' => 'fecha de inicio',
            'hasta' => 'fecha de fin',
            'observacion' => 'observación',
        ];
    }
}
