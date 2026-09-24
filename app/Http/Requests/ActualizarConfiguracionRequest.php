<?php

namespace App\Http\Requests;

use App\Models\Configuracion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Guarda una sección de la pantalla de Configuración.
 *
 * Las reglas salen de {@see Configuracion::PARAMETROS}, según el tipo de cada
 * parámetro, y solo de los de la sección: un campo de otra sección que llegue
 * en el envío se ignora.
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
        $reglas = [];

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
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $atributos = [];

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
     * @return array<string, array{grupo: string, etiqueta: string, ayuda: string, tipo: string, maximo?: int, opciones?: array<string, string>, defecto?: string}>
     */
    public function parametros(): array
    {
        return Configuracion::porGrupo()[(string) $this->route('grupo')] ?? [];
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
