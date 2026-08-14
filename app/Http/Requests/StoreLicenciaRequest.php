<?php

namespace App\Http\Requests;

use App\Models\Licencia;
use App\Services\RegistroLicencia;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Reglas para anotar licencia(s). Un envío cubre un rango de fechas y uno de
 * los tres alcances: un funcionario, varios elegidos a mano, o todos los que
 * tengan turno dentro del rango (feriados y tolerancias generales). El servicio
 * expande eso a una fila de `licencias` por funcionario, día y turno.
 */
class StoreLicenciaRequest extends FormRequest
{
    /**
     * Alcances posibles del alta.
     *
     * @var list<string>
     */
    public const MODOS = ['uno', 'varios', 'todos'];

    public function authorize(): bool
    {
        return $this->user()?->can('create', Licencia::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'modo' => ['required', Rule::in(self::MODOS)],
            // Los carnets vienen del directorio de Mamoré, no de la base local:
            // no se valida contra `personas`. Que el CI tenga turnos asignados
            // (lo único que hace licenciable a alguien) lo comprueba el controlador.
            'ci' => ['required_if:modo,uno', 'nullable', 'string', 'max:12'],
            'cis' => ['required_if:modo,varios', 'nullable', 'array'],
            'cis.*' => ['string', 'max:12'],
            // Opcional y solo en modo «uno»: sin turnos elegidos se licencian
            // todos los que el funcionario tenga asignados dentro del rango.
            'asignaciones' => ['nullable', 'array'],
            'asignaciones.*' => ['integer', 'exists:asignacion_turnos,id'],
            'desde' => ['required', 'date'],
            'hasta' => ['required', 'date', 'after_or_equal:desde'],
            'tCompleto' => ['required', 'boolean'],
            'goceHaberes' => ['required', 'boolean'],
            'lEntra' => ['nullable', 'required_if:tCompleto,false', 'date_format:H:i'],
            'lSale' => ['nullable', 'required_if:tCompleto,false', 'date_format:H:i', 'after:lEntra'],
            'motivo' => ['required', 'string', 'max:255'],
            // Respaldo que justifica la licencia: certificado médico,
            // memorándum o nota. Opcional: el documento llega a destiempo (el
            // certificado se presenta al volver) y el alta masiva de un feriado
            // no tiene uno por funcionario, así que exigirlo frenaba el
            // registro en vez de mejorarlo. Cuando viene, se acota a imagen o
            // PDF, que es lo que sale de un escáner o de la cámara del teléfono.
            'respaldo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ];
    }

    /**
     * Los checkboxes no viajan cuando están desmarcados: se normalizan a boolean
     * para que `required_if` y el casteo del modelo trabajen con valores reales.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'modo' => in_array($this->input('modo'), self::MODOS, true) ? $this->input('modo') : 'uno',
            'tCompleto' => $this->boolean('tCompleto'),
            'goceHaberes' => $this->boolean('goceHaberes'),
        ]);
    }

    /**
     * Un rango desmedido generaría miles de filas por un error de tipeo.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->hasAny(['desde', 'hasta'])) {
                return;
            }

            $dias = Carbon::parse($this->input('desde'))->diffInDays(Carbon::parse($this->input('hasta'))) + 1;

            if ($dias > RegistroLicencia::MAX_DIAS) {
                $validator->errors()->add('hasta', 'El rango no puede superar '.RegistroLicencia::MAX_DIAS.' días.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ci.required_if' => 'Elegí el funcionario que requiere la licencia.',
            'cis.required_if' => 'Agregá al menos un funcionario a la lista.',
            'hasta.after_or_equal' => 'La fecha «Hasta» no puede ser anterior a «Desde».',
            'lEntra.required_if' => 'Indicá la hora de entrada o marcá «Turno completo».',
            'lSale.required_if' => 'Indicá la hora de salida o marcá «Turno completo».',
            'lSale.after' => 'La hora de salida debe ser posterior a la de entrada.',
            'respaldo.mimes' => 'El respaldo debe ser una imagen (JPG o PNG) o un PDF.',
            'respaldo.max' => 'El respaldo no puede pesar más de 5 MB.',
        ];
    }
}
