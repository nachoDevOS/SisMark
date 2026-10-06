<?php

namespace App\Http\Requests;

use App\Models\Configuracion;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Guarda una sección de la pantalla de Configuración.
 *
 * Las reglas salen de {@see Configuracion::PARAMETROS}, según el tipo de cada
 * parámetro, y solo de los de la sección: un campo de otra sección que llegue
 * en el envío se ignora.
 *
 * Además de los valores, cada guardado lleva **desde qué mes rige** y **el
 * motivo**, los dos obligatorios: es lo que deja el registro de qué regía cada
 * mes y por qué cambió.
 */
class ActualizarConfiguracionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('Update:Configuracion');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $reglas = [
            // Mes en curso o futuro (se controla en `after()`): lo que ya rigió
            // no se reescribe.
            'vigente_desde' => ['required', 'date_format:Y-m'],
            'motivo' => ['required', 'string', 'max:500'],
        ];

        foreach ($this->parametros() as $clave => $parametro) {
            $campo = Configuracion::campo($clave);

            $reglas += match ($parametro['tipo']) {
                Configuracion::TIPO_DURACION => [
                    $campo => ['required', 'array'],
                    "{$campo}.horas" => ['required', 'integer', 'min:0', 'max:'.intdiv($parametro['maximo'], 60)],
                    "{$campo}.minutos" => ['required', 'integer', 'min:0', 'max:59'],
                ],
                Configuracion::TIPO_OPCIONES => [
                    $campo => ['required', 'string', Rule::in(array_keys($parametro['opciones']))],
                ],
            };
        }

        return $reglas;
    }

    /**
     * Un mes que ya empezó no se reescribe: si ya tiene una vigencia cargada, el
     * cambio va desde el mes siguiente. Uno futuro sí, porque todavía no rigió.
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

                if ($this->desde()->lessThan(today()->startOfMonth())) {
                    $validator->errors()->add('vigente_desde', 'Solo se puede cargar desde el mes en curso en adelante: lo que ya rigió no se cambia.');

                    return;
                }

                if (! $this->desde()->isSameMonth(today())) {
                    return;
                }

                $yaCargado = Configuracion::query()
                    ->whereIn('clave', array_keys($this->parametros()))
                    ->where('vigente_desde', $this->desde()->toDateString())
                    ->exists();

                if ($yaCargado) {
                    $validator->errors()->add(
                        'vigente_desde',
                        'Ya hay una configuración que rige desde '.$this->desde()->translatedFormat('F \d\e Y')
                            .' y ese mes ya empezó. Elegí el mes siguiente.',
                    );
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $atributos = [
            'vigente_desde' => 'mes desde el que rige',
            'motivo' => 'motivo del cambio',
        ];

        foreach ($this->parametros() as $clave => $parametro) {
            $campo = Configuracion::campo($clave);

            $atributos += match ($parametro['tipo']) {
                Configuracion::TIPO_DURACION => [
                    "{$campo}.horas" => 'horas',
                    "{$campo}.minutos" => 'minutos',
                ],
                Configuracion::TIPO_OPCIONES => [
                    $campo => 'opción',
                ],
            };
        }

        return $atributos;
    }

    /**
     * Los parámetros de la sección.
     *
     * @return array<string, array{grupo: string, etiqueta: string, corta: string, ayuda: string, tipo: string, maximo?: int, opciones?: array<string, string>, defecto?: string}>
     */
    public function parametros(): array
    {
        return Configuracion::porGrupo()[(string) $this->route('grupo')] ?? [];
    }

    /**
     * Día 1 del mes desde el que rige lo cargado.
     */
    public function desde(): Carbon
    {
        // `!` deja el día en 1: sin él toma el día de hoy, y un 31 «noviembre»
        // se iría a diciembre.
        return Carbon::createFromFormat('!Y-m', (string) $this->input('vigente_desde'));
    }

    /**
     * Lo validado, ya en el formato en que se guarda cada parámetro.
     *
     * @return array<string, string>
     */
    public function valores(): array
    {
        $valores = [];

        foreach ($this->parametros() as $clave => $parametro) {
            $dato = $this->validated(Configuracion::campo($clave));

            $valores[$clave] = match ($parametro['tipo']) {
                Configuracion::TIPO_DURACION => (string) ((int) $dato['horas'] * 60 + (int) $dato['minutos']),
                Configuracion::TIPO_OPCIONES => (string) $dato,
            };
        }

        return $valores;
    }
}
