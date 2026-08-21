<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Carbon;

/**
 * Columnas `time` que guardan **solo una hora del día**: la entrada de un turno,
 * la hora de una marcación, el tramo de una licencia por horas.
 *
 * No alcanza con el cast `datetime` de Eloquent. Al leer anda —Carbon parsea
 * «07:00:00» sin problema—, pero **al escribir serializa a `Y-m-d H:i:s`**, así
 * que manda «2026-07-09 07:00:00» a una columna `time`. SQLite lo acepta
 * callado, MySQL en modo estricto lo rechaza, y el error aparecería recién en
 * producción.
 *
 * Este par lee a Carbon —para que todo el sistema siga usando `->format('H:i')`
 * y las cuentas en segundos desde medianoche— y escribe siempre `H:i:s`.
 *
 * Tolera que le llegue un datetime entero: es lo que hay guardado en lo migrado
 * del SIA, que colgaba las horas de la fecha base 1899-12-30. De ahí se queda
 * solo con la hora, que es lo único que significaba algo.
 */
trait ManejaHorasDelDia
{
    /**
     * Columnas `date` que guardan **solo un día**.
     *
     * Mismo motivo que las horas: el cast `date` de Eloquent lee bien, pero al
     * escribir serializa a `Y-m-d H:i:s` y deja «2026-07-09 00:00:00» donde
     * debería haber «2026-07-09». MySQL lo trunca callado, pero en SQLite queda
     * guardado entero y cualquier comparación por igualdad contra un `Y-m-d`
     * deja de cruzar.
     */
    protected static function soloFecha(): Attribute
    {
        return Attribute::make(
            get: static function (?string $valor): ?Carbon {
                if ($valor === null || trim($valor) === '') {
                    return null;
                }

                try {
                    return Carbon::parse($valor)->startOfDay();
                } catch (\Throwable) {
                    return null;
                }
            },
            set: static function (mixed $valor): ?string {
                if ($valor === null || (is_string($valor) && trim($valor) === '')) {
                    return null;
                }

                if ($valor instanceof \DateTimeInterface) {
                    return $valor->format('Y-m-d');
                }

                try {
                    return Carbon::parse((string) $valor)->format('Y-m-d');
                } catch (\Throwable) {
                    return null;
                }
            },
        );
    }

    protected static function horaDelDia(): Attribute
    {
        return Attribute::make(
            get: static function (?string $valor): ?Carbon {
                if ($valor === null || trim($valor) === '') {
                    return null;
                }

                try {
                    return Carbon::parse($valor);
                } catch (\Throwable) {
                    // Un valor ilegible no puede tumbar la ficha entera: se lo
                    // trata como ausente y la pantalla muestra su «—».
                    return null;
                }
            },
            set: static function (mixed $valor): ?string {
                if ($valor === null || (is_string($valor) && trim($valor) === '')) {
                    return null;
                }

                if ($valor instanceof \DateTimeInterface) {
                    return $valor->format('H:i:s');
                }

                try {
                    return Carbon::parse((string) $valor)->format('H:i:s');
                } catch (\Throwable) {
                    return null;
                }
            },
        );
    }
}
