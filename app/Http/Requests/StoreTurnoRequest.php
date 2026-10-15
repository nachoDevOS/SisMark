<?php

namespace App\Http\Requests;

use App\Models\Horario;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Alta de un turno: un nombre y los horarios de la semana que lo forman, que se
 * cargan acá mismo y nacen siendo del turno.
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
     * Campos hora de cada fila. Llegan «HH:MM» y se guardan como datetime sobre
     * la fecha base 1899-12-30, como el SIA.
     *
     * @var list<string>
     */
    public const CAMPOS_HORA = [
        'hEntrada', 'hTolerancia', 'eMinima', 'eMaxima',
        'hSalida', 'sTolerancia', 'sMinima', 'sMaxima',
    ];

    /**
     * Abreviatura del día para armar el nombre del horario («LUN: 08:00 - 16:00»).
     *
     * @var array<int, string>
     */
    private const ABREVIATURAS = [1 => 'DOM', 2 => 'LUN', 3 => 'MAR', 4 => 'MIE', 5 => 'JUE', 6 => 'VIE', 7 => 'SAB'];

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
        $horas = collect(self::CAMPOS_HORA)
            ->mapWithKeys(fn (string $campo): array => ["horarios.*.{$campo}" => ['required', 'date_format:H:i']])
            ->all();

        return [
            'nombre' => ['required', 'string', 'max:60', Rule::unique('turnos', 'nombre')->whereNull('deleted_at')],
            'sugerido' => ['nullable', 'boolean'],
            'observacion' => ['nullable', 'string', 'max:255'],
            'horarios' => ['required', 'array', 'min:1'],
            'horarios.*' => ['array:'.implode(',', [...self::CAMPOS_HORA, 'dia', 'hTrabajadas', 'siguienteDia'])],
            'horarios.*.dia' => ['required', Rule::in(array_map('strval', array_keys(Horario::DIAS)))],
            ...$horas,
            'horarios.*.hTrabajadas' => ['required', 'numeric', 'min:0', 'max:24'],
            'horarios.*.siguienteDia' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Los checkboxes llegan solo cuando están marcados.
     */
    protected function prepareForValidation(): void
    {
        $horarios = $this->input('horarios');

        $this->merge([
            'sugerido' => $this->boolean('sugerido'),
            'horarios' => is_array($horarios)
                ? array_map(fn ($fila) => is_array($fila) ? [...$fila, 'siguienteDia' => (bool) ($fila['siguienteDia'] ?? false)] : $fila, $horarios)
                : $horarios,
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

                $choque = $this->primerChoque(array_values($this->input('horarios')));

                if ($choque !== null) {
                    [$uno, $otro] = $choque;
                    $validator->errors()->add('horarios', "Los horarios #{$uno} y #{$otro} se pisan: un turno no puede tener dos horarios a la misma hora.");
                }
            },
        ];
    }

    /**
     * Los horarios listos para crear, sin el código: ese lo pone el controlador.
     *
     * @return list<array<string, mixed>>
     */
    public function horarios(): array
    {
        return array_map(function (array $fila): array {
            $horario = [
                'dia' => (string) $fila['dia'],
                'nombreHorario' => self::ABREVIATURAS[(int) $fila['dia']].': '.$fila['hEntrada'].' - '.$fila['hSalida'],
                'hTrabajadas' => $fila['hTrabajadas'],
                'siguienteDia' => (bool) $fila['siguienteDia'],
            ];

            foreach (self::CAMPOS_HORA as $campo) {
                $horario[$campo] = Carbon::createFromFormat('Y-m-d H:i', '1899-12-30 '.$fila[$campo]);
            }

            return $horario;
        }, array_values($this->validated('horarios')));
    }

    /**
     * Las posiciones (desde 1) del primer par de horarios que se superpone en la
     * semana, o `null`.
     *
     * Cada horario se lleva a minutos desde el domingo 00:00; el nocturno suma
     * un día a la salida. Se compara también corrido una semana, para que el
     * nocturno del sábado choque con el domingo a la mañana.
     *
     * @param  list<array<string, mixed>>  $filas
     * @return array{0: int, 1: int}|null
     */
    private function primerChoque(array $filas): ?array
    {
        $tramos = array_map(function (array $fila): array {
            $inicio = ((int) $fila['dia'] - 1) * 1440 + $this->minutos($fila['hEntrada']);
            $fin = ((int) $fila['dia'] - 1) * 1440 + $this->minutos($fila['hSalida']);

            if ($fila['siguienteDia'] || $fin <= $inicio) {
                $fin += 1440;
            }

            return [$inicio, $fin];
        }, $filas);

        foreach ($tramos as $i => [$inicioUno, $finUno]) {
            foreach (array_slice($tramos, $i + 1, preserve_keys: true) as $j => [$inicioOtro, $finOtro]) {
                foreach ([0, self::MINUTOS_SEMANA, -self::MINUTOS_SEMANA] as $corrimiento) {
                    if ($inicioUno < $finOtro + $corrimiento && $inicioOtro + $corrimiento < $finUno) {
                        return [$i + 1, $j + 1];
                    }
                }
            }
        }

        return null;
    }

    private function minutos(string $hora): int
    {
        [$horas, $minutos] = array_map('intval', explode(':', $hora));

        return $horas * 60 + $minutos;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'horarios.required' => 'Agregá al menos un horario al turno.',
            'horarios.min' => 'Agregá al menos un horario al turno.',
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
            'horarios' => 'horarios',
            'horarios.*.dia' => 'día del horario #:position',
            'horarios.*.hEntrada' => 'hora de entrada del horario #:position',
            'horarios.*.hTolerancia' => 'tolerancia de entrada del horario #:position',
            'horarios.*.eMinima' => 'marca de entrada desde del horario #:position',
            'horarios.*.eMaxima' => 'marca de entrada hasta del horario #:position',
            'horarios.*.hSalida' => 'hora de salida del horario #:position',
            'horarios.*.sTolerancia' => 'tolerancia de salida del horario #:position',
            'horarios.*.sMinima' => 'marca de salida desde del horario #:position',
            'horarios.*.sMaxima' => 'marca de salida hasta del horario #:position',
            'horarios.*.hTrabajadas' => 'horas trabajadas del horario #:position',
        ];
    }
}
