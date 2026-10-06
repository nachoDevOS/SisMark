<?php

namespace App\Http\Requests;

use App\Models\AsignacionTurno;
use App\Models\Turno;
use App\Services\AsignadorTurnos;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Reglas para asignarle un turno a un funcionario, con fecha de inicio y de fin.
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
            'desde' => 'fecha de inicio',
            'hasta' => 'fecha de fin',
            'observacion' => 'observación',
        ];
    }
}
