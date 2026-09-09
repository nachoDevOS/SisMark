<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Emisión de la credencial de un sistema consumidor.
 *
 * Pide la contraseña de quien la emite. No es una formalidad: un token abre la
 * asistencia de los ~4.600 funcionarios y no caduca, así que una sesión dejada
 * abierta en un escritorio no alcanza para entregar uno.
 *
 * Se valida con `current_password` en el propio formulario y no con el
 * middleware `password.confirm`, que redirige a una pantalla aparte.
 *
 * **No pide alcances.** La pantalla emite siempre con todos: son cinco áreas de
 * la misma API y elegirlas de a una obligaba a saber de antemano qué endpoints
 * va a usar el consumidor, que es justo lo que no se sabe al darlo de alta. Un
 * token acotado se emite por consola, con `sismark:token --alcance=…`.
 */
class EmitirTokenRequest extends FormRequest
{
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
            'password' => ['required', 'current_password'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.required' => 'Confirmá tu contraseña para emitir el token.',
            'password.current_password' => 'La contraseña no es correcta.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'password' => 'contraseña',
        ];
    }
}
