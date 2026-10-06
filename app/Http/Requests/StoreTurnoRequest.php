<?php

namespace App\Http\Requests;

use App\Models\Horario;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Alta de un turno: un nombre y los horarios de la semana que lo forman.
 *
 * Dos horarios del mismo día pueden convivir (mañana y tarde), pero no pisarse:
 * el procesador no sabría a cuál imputar cada marca.
 */
class StoreTurnoRequest extends FormRequest
{
    /**
     * Minutos de una semana: para comparar horarios que cruzan de sábado a domingo.
     */
    private const MINUTOS_SEMANA = 7 * 24 * 60;

    /**
     * La autorización la resuelve la policy en el controlador.
     */
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
            'nombre' => ['required', 'string', 'max:60', Rule::unique('turnos', 'nombre')->whereNull('deleted_at')],
            'sugerido' => ['nullable', 'boolean'],
            'observacion' => ['nullable', 'string', 'max:255'],
            'horarioIds' => ['required', 'array', 'min:1'],
            'horarioIds.*' => ['integer', 'distinct', Rule::exists('horarios', 'id')->whereNull('deleted_at')],
        ];
    }

    /**
     * El checkbox llega solo cuando está marcado.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'sugerido' => $this->boolean('sugerido'),
        ]);
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $horarios = Horario::query()->whereIn('id', $this->input('horarioIds'))->get();
                $choque = $this->primerChoque($horarios);

                if ($choque !== null) {
                    [$uno, $otro] = $choque;
                    $validator->errors()->add('horarioIds', 'Los horarios «'.trim($uno->nombreHorario).'» y «'.trim($otro->nombreHorario).'» se pisan: un turno no puede tener dos horarios a la misma hora.');
                }
            },
        ];
    }

    /**
     * El primer par de horarios que se superpone en la semana, o `null`.
     *
     * Cada horario se lleva a minutos desde el domingo 00:00; el nocturno suma
     * un día a la salida. Se compara también corrido una semana, para que el
     * nocturno del sábado choque con el domingo a la mañana.
     *
     * @param  Collection<int, Horario>  $horarios
     * @return array{0: Horario, 1: Horario}|null
     */
    private function primerChoque(Collection $horarios): ?array
    {
        $tramos = $horarios->map(function (Horario $horario): array {
            $inicio = ((int) $horario->dia - 1) * 1440 + $this->minutos($horario->hEntrada?->format('H:i'));
            $fin = ((int) $horario->dia - 1) * 1440 + $this->minutos($horario->hSalida?->format('H:i'));

            if ($horario->siguienteDia || $fin <= $inicio) {
                $fin += 1440;
            }

            return [$horario, $inicio, $fin];
        })->values();

        foreach ($tramos as $i => [$uno, $inicioUno, $finUno]) {
            foreach ($tramos->slice($i + 1) as [$otro, $inicioOtro, $finOtro]) {
                foreach ([0, self::MINUTOS_SEMANA, -self::MINUTOS_SEMANA] as $corrimiento) {
                    if ($inicioUno < $finOtro + $corrimiento && $inicioOtro + $corrimiento < $finUno) {
                        return [$uno, $otro];
                    }
                }
            }
        }

        return null;
    }

    private function minutos(?string $hora): int
    {
        [$horas, $minutos] = array_map('intval', explode(':', $hora ?? '00:00'));

        return $horas * 60 + $minutos;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'horarioIds.required' => 'Agregá al menos un horario al turno.',
            'horarioIds.min' => 'Agregá al menos un horario al turno.',
            'horarioIds.*.distinct' => 'Un mismo horario está agregado dos veces.',
            'horarioIds.*.exists' => 'Uno de los horarios ya no existe.',
            'nombre.unique' => 'Ya hay un turno con ese nombre.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nombre' => 'nombre del turno',
            'sugerido' => 'turno sugerido',
            'observacion' => 'observación',
            'horarioIds' => 'horarios',
        ];
    }
}
