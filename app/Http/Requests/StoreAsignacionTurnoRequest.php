<?php

namespace App\Http\Requests;

use App\Exceptions\MamoreException;
use App\Models\AsignacionTurno;
use App\Models\Turno;
use App\Services\AsignadorTurnos;
use App\Services\ContratosFuncionario;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Reglas para asignarle un turno a un funcionario, con fecha de inicio y de fin.
 *
 * Con Mamoré configurado, el turno se ata a un **contrato firmado o concluido**
 * de la persona y sus fechas tienen que caber en él: así las adendas y
 * anulaciones de Mamoré lo mueven solas, y nunca queda un turno corriendo más
 * allá del contrato que choque con el de la renovación. Sin Mamoré se asigna sin
 * contrato.
 *
 * Un contrato que ya terminó se elige para **regularizar**: la persona pasó ese
 * contrato sin turno y se le carga después, para que esos días se controlen.
 */
class StoreAsignacionTurnoRequest extends FormRequest
{
    /**
     * El contrato elegido, tal como lo devolvió {@see ContratosFuncionario::asignables()}.
     *
     * @var array<string, mixed>|null
     */
    private ?array $contrato = null;

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
                ? 'El funcionario no tiene contrato firmado ni concluido en Mamoré: no se le puede asignar turno.'
                : 'Elegí el contrato al que corresponde el turno.');

            return;
        }

        $contrato = collect($asignables)->firstWhere('id', $this->integer('contrato_id'));

        if ($contrato === null) {
            $validator->errors()->add('contrato_id', 'Ese contrato no es un contrato firmado o concluido de este funcionario.');

            return;
        }

        $this->contrato = $contrato;

        if ($contrato['regularizar']) {
            $this->validarRegularizacion($validator, $contrato);

            return;
        }

        $turnosDelContrato = AsignacionTurno::query()->with('turno')->delContrato($contrato['id'])->get();

        if ($turnosDelContrato->isNotEmpty()) {
            $this->validarTurnoSiguiente($validator, $contrato, $turnosDelContrato);

            return;
        }

        if ($this->date('desde')->lessThan($contrato['desde'])) {
            $validator->errors()->add('desde', 'La fecha de inicio no puede ser anterior al inicio del contrato ('.$contrato['desde']->format('d/m/Y').').');
        }

        // El turno termina con el contrato: más allá chocaría con el de la
        // renovación, y antes dejaría los últimos días sin control. Solo un
        // contrato abierto deja elegir el fin.
        if ($contrato['hasta'] !== null && ! $this->date('hasta')->equalTo($contrato['hasta'])) {
            $validator->errors()->add('hasta', 'La fecha de fin es la del contrato ('.$contrato['hasta']->format('d/m/Y').').');
        }
    }

    /**
     * Un contrato que ya terminó se regulariza de a tramos: el turno cubre el
     * tramo sin control más antiguo, justo de punta a punta, sin pisar lo que
     * ya tenía asignado ({@see AsignadorTurnos::cobertura()}). Por eso acá no
     * importa que el contrato ya tenga un turno: puede ser el de un tramo
     * anterior.
     *
     * @param  array<string, mixed>  $contrato
     */
    private function validarRegularizacion(Validator $validator, array $contrato): void
    {
        $asignador = app(AsignadorTurnos::class);
        $cobertura = $asignador->cobertura(
            $asignador->periodosAsignados((string) $this->input('ci')),
            $contrato['desde'],
            $contrato['hasta'],
        );

        if ($cobertura['estado'] === 'completa') {
            $validator->errors()->add('contrato_id', (string) $asignador->motivoCobertura($cobertura));

            return;
        }

        $explicacion = $asignador->motivoCobertura($cobertura);

        if ($this->date('desde')->lessThan($cobertura['libreDesde'])) {
            $validator->errors()->add('desde', $explicacion
                ?? 'La fecha de inicio no puede ser anterior al inicio del contrato ('.$contrato['desde']->format('d/m/Y').').');
        }

        if ($cobertura['libreHasta'] !== null && ! $this->date('hasta')->equalTo($cobertura['libreHasta'])) {
            $validator->errors()->add('hasta', $explicacion
                ?? 'La fecha de fin es la del contrato ('.$contrato['hasta']->format('d/m/Y').').');
        }
    }

    /**
     * El contrato vigente ya tiene turno: el nuevo cubre justo el hueco sin
     * turno más antiguo desde su primer turno —después del último, cuando el
     * funcionario cambia de jornada, o en el medio, si se concluyó uno antes de
     * que empiece el siguiente— ({@see AsignadorTurnos::coberturaDelVigente()}).
     * Sin huecos, no hay lugar para otro.
     *
     * @param  array<string, mixed>  $contrato
     * @param  Collection<int, AsignacionTurno>  $turnos
     */
    private function validarTurnoSiguiente(Validator $validator, array $contrato, Collection $turnos): void
    {
        $asignador = app(AsignadorTurnos::class);
        $cobertura = $asignador->coberturaDelVigente(
            $asignador->periodosAsignados((string) $this->input('ci')),
            $turnos,
            $contrato['hasta'],
        );
        $ultimo = $turnos->sortByDesc(fn (AsignacionTurno $asignacion): int => $asignacion->hasta->getTimestamp())->first();

        if ($cobertura['estado'] === 'completa') {
            $validator->errors()->add('contrato_id', 'Ese contrato ya tiene el turno «'.$ultimo->turno?->nombre.'» del '
                .$ultimo->desde->format('d/m/Y').' al '.$ultimo->hasta->format('d/m/Y').'.');

            return;
        }

        if (! $this->date('desde')->equalTo($cobertura['libreDesde'])) {
            // El turno que termina justo antes del hueco.
            $anterior = $turnos
                ->filter(fn (AsignacionTurno $asignacion): bool => $asignacion->hasta->lessThan($cobertura['libreDesde']))
                ->sortByDesc(fn (AsignacionTurno $asignacion): int => $asignacion->hasta->getTimestamp())
                ->first() ?? $ultimo;

            $validator->errors()->add('desde', 'El turno nuevo empieza el '.$cobertura['libreDesde']->format('d/m/Y').': el día siguiente al '
                .($anterior->is($ultimo) ? 'último turno del contrato' : 'turno anterior')
                .' («'.$anterior->turno?->nombre.'», hasta el '.$anterior->hasta->format('d/m/Y').').');
        }

        if ($cobertura['libreHasta'] !== null && ! $this->date('hasta')->equalTo($cobertura['libreHasta'])) {
            $validator->errors()->add('hasta', $cobertura['libreHasta']->equalTo($contrato['hasta'])
                ? 'La fecha de fin es la del contrato ('.$contrato['hasta']->format('d/m/Y').').'
                : 'La fecha de fin es el '.$cobertura['libreHasta']->format('d/m/Y').': el día antes de los horarios que ya tiene asignados.');
        }
    }

    /**
     * ¿El turno se asigna a un contrato que ya terminó? Vale después de validar.
     */
    public function esRegularizacion(): bool
    {
        return (bool) ($this->contrato['regularizar'] ?? false);
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
