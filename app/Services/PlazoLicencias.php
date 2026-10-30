<?php

namespace App\Services;

use App\Models\ConfiguracionPlazoLicencia;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Controla que una licencia se registre dentro del plazo vigente
 * ({@see ConfiguracionPlazoLicencia}): ni de hace más días de los permitidos, ni con más
 * anticipación de la permitida.
 *
 * Lo usan las dos puertas de alta —la API por la que pide el funcionario desde
 * Mamoré y la pantalla de Recursos Humanos—, así que una sola regla vale para
 * los dos sistemas.
 */
class PlazoLicencias
{
    /**
     * Por qué el rango queda fuera del plazo que rige el día `$hoy` (hoy si no
     * se da), o `null` si entra —o si no hay plazo cargado—.
     */
    public function motivoFueraDePlazo(CarbonInterface $desde, CarbonInterface $hasta, ?CarbonInterface $hoy = null): ?string
    {
        $hoy = Carbon::instance($hoy ?? today())->startOfDay();
        $plazo = ConfiguracionPlazoLicencia::vigente($hoy);

        if ($plazo === null) {
            return null;
        }

        $primera = $plazo->primeraFecha($hoy);

        if ($primera !== null && Carbon::instance($desde)->startOfDay()->lessThan($primera)) {
            return 'No se puede registrar una licencia de hace más de '.$plazo->legible($plazo->dias_atraso)
                .': hoy se puede pedir desde el '.self::fecha($primera).'.';
        }

        $ultima = $plazo->ultimaFecha($hoy);

        if ($ultima !== null && Carbon::instance($hasta)->startOfDay()->greaterThan($ultima)) {
            return 'No se puede registrar una licencia con más de '.$plazo->legible($plazo->dias_anticipacion)
                .' de anticipación: hoy se puede pedir hasta el '.self::fecha($ultima).'.';
        }

        return null;
    }

    /**
     * El plazo de hoy en una frase, para mostrar junto a las fechas del
     * formulario: «Se puede registrar del 23/10/2026 al 04/11/2026». `null` si
     * no hay plazo o no limita nada.
     */
    public function resumen(?CarbonInterface $hoy = null): ?string
    {
        $hoy = Carbon::instance($hoy ?? today())->startOfDay();
        $plazo = ConfiguracionPlazoLicencia::vigente($hoy);

        if ($plazo === null || ! $plazo->limita()) {
            return null;
        }

        $primera = $plazo->primeraFecha($hoy);
        $ultima = $plazo->ultimaFecha($hoy);

        return match (true) {
            $primera !== null && $ultima !== null => 'Se puede registrar del '.self::fecha($primera).' al '.self::fecha($ultima).'.',
            $primera !== null => 'Se puede registrar desde el '.self::fecha($primera).' en adelante.',
            default => 'Se puede registrar hasta el '.self::fecha($ultima).'.',
        };
    }

    /**
     * El plazo de hoy para un consumidor de la API: las fechas límite —para
     * que Mamoré bloquee los días de afuera en el calendario— y la frase para
     * mostrar. `null` si no hay plazo o no limita nada. Cada límite en `null`
     * si ese lado no tiene.
     *
     * @return array{desde: ?string, hasta: ?string, diasHabiles: bool, mensaje: string}|null
     */
    public function paraApi(?CarbonInterface $hoy = null): ?array
    {
        $hoy = Carbon::instance($hoy ?? today())->startOfDay();
        $plazo = ConfiguracionPlazoLicencia::vigente($hoy);

        if ($plazo === null || ! $plazo->limita()) {
            return null;
        }

        return [
            'desde' => $plazo->primeraFecha($hoy)?->toDateString(),
            'hasta' => $plazo->ultimaFecha($hoy)?->toDateString(),
            'diasHabiles' => $plazo->dias_habiles,
            'mensaje' => (string) $this->resumen($hoy),
        ];
    }

    /**
     * Qué permitiría un plazo si se pidiera hoy, para mostrar como ejemplo
     * mientras se carga: «Ejemplo con la fecha de hoy (miércoles 28/10/2026):
     * se puede registrar del viernes 23/10/2026 al miércoles 04/11/2026.».
     *
     * La pantalla de Configuración lo recalcula en el navegador con la misma
     * cuenta mientras se escriben los números; esto es lo que muestra al abrir.
     */
    public function ejemplo(ConfiguracionPlazoLicencia $plazo, ?CarbonInterface $hoy = null): string
    {
        $hoy = Carbon::instance($hoy ?? today())->startOfDay();
        $primera = $plazo->primeraFecha($hoy);
        $ultima = $plazo->ultimaFecha($hoy);

        $rango = match (true) {
            $primera !== null && $ultima !== null => 'se puede registrar del '.self::fecha($primera).' al '.self::fecha($ultima),
            $primera !== null => 'se puede registrar desde el '.self::fecha($primera).', sin límite de anticipación',
            $ultima !== null => 'se puede registrar hasta el '.self::fecha($ultima).', sin límite hacia atrás',
            default => 'se puede registrar cualquier fecha, sin límite',
        };

        return 'Ejemplo con la fecha de hoy ('.self::fecha($hoy).'): '.$rango.'.';
    }

    /**
     * «jueves 23/10/2026».
     */
    private static function fecha(Carbon $fecha): string
    {
        return $fecha->translatedFormat('l').' '.$fecha->format('d/m/Y');
    }
}
