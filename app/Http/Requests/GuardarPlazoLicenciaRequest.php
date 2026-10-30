<?php

namespace App\Http\Requests;

use App\Models\ConfiguracionPlazoLicencia;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Carga un plazo para registrar licencias ({@see ConfiguracionPlazoLicencia}) desde la
 * pantalla de Configuración: los dos límites, si se cuentan en días hábiles,
 * **desde qué día rige** y **el motivo**.
 *
 * Mismo permiso que el resto de la pantalla, `Update:Configuracion`.
 */
class GuardarPlazoLicenciaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('Update:Configuracion');
    }

    /**
     * La casilla desmarcada no viaja: se normaliza a boolean.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['dias_habiles' => $this->boolean('dias_habiles')]);
    }

    /**
     * El día y el motivo llevan prefijo: la misma pantalla tiene otros
     * formularios con `vigente_desde` y `motivo`, y compartirían los errores y
     * lo cargado al rebotar.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'dias_atraso' => ['required', 'integer', 'min:0', 'max:'.ConfiguracionPlazoLicencia::MAX_DIAS],
            'dias_anticipacion' => ['required', 'integer', 'min:0', 'max:'.ConfiguracionPlazoLicencia::MAX_DIAS],
            'dias_habiles' => ['required', 'boolean'],
            // Hoy o un día futuro: lo que ya rigió no se reescribe.
            'plazo_desde' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'plazo_motivo' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * Un día que ya empezó no se reescribe: si hoy ya tiene un plazo cargado, el
     * cambio va desde mañana. Uno futuro sí, porque todavía no rigió.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty() || ! $this->desde()->isToday()) {
                    return;
                }

                if (ConfiguracionPlazoLicencia::query()->where('vigente_desde', $this->desde()->toDateString())->exists()) {
                    $validator->errors()->add('plazo_desde', 'Ya hay un plazo que rige desde hoy. Elegí desde mañana.');
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
            'plazo_desde.after_or_equal' => 'Solo se puede cargar desde hoy en adelante: lo que ya rigió no se cambia.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'dias_atraso' => 'días hacia atrás',
            'dias_anticipacion' => 'días de anticipación',
            'plazo_desde' => 'día desde el que rige',
            'plazo_motivo' => 'motivo del cambio',
        ];
    }

    /**
     * Día desde el que rige.
     */
    public function desde(): Carbon
    {
        return Carbon::createFromFormat('!Y-m-d', (string) $this->input('plazo_desde'));
    }

    /**
     * Los valores validados, listos para {@see ConfiguracionPlazoLicencia::guardar()}.
     *
     * @return array{dias_anticipacion: int, dias_atraso: int, dias_habiles: bool}
     */
    public function valores(): array
    {
        return [
            'dias_anticipacion' => (int) $this->validated('dias_anticipacion'),
            'dias_atraso' => (int) $this->validated('dias_atraso'),
            'dias_habiles' => (bool) $this->validated('dias_habiles'),
        ];
    }
}
