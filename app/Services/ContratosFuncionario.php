<?php

namespace App\Services;

use App\Exceptions\MamoreException;
use Illuminate\Support\Carbon;

/**
 * Entre qué fechas una persona fue funcionaria, según los contratos de Mamoré.
 *
 * Es la **primera puerta** del control de asistencia: un día que ningún contrato
 * cubre no se procesa, aunque la persona tenga turno asignado y aunque haya
 * marcado. Sin contrato no hay jornada que cumplir, así que no puede haber falta
 * ni atraso ni horas computadas.
 *
 * **Nunca se cachea.** La ficha de identidad de Mamoré sí se guarda un día
 * —nombre, cargo y foto no cambian—, pero los contratos se preguntan siempre:
 * procesar sobre una copia vieja significaría marcar faltas en días que ya
 * estaban cubiertos por una renovación recién cargada.
 *
 * Se toman los `firmado` y los `concluido`. Los concluidos son casi todo el
 * historial —14.534 contra 910 firmados—, así que sin ellos un reporte de meses
 * anteriores no sabría que la persona estaba contratada. Los borradores
 * (`elaborado`, `enviado`) quedan afuera: no habilitan nada.
 *
 * @phpstan-type Tramo array{desde: Carbon, hasta: ?Carbon}
 */
class ContratosFuncionario
{
    public function __construct(private MamoreClient $mamore) {}

    /**
     * Los tramos en que la persona estuvo contratada dentro del rango.
     *
     * `null` significa **«no se sabe»**, que es distinto de «no tuvo contrato»:
     * pasa cuando Mamoré no está configurado. Quien reciba `null` decide qué
     * hacer; lo que no puede es confundirlo con una lista vacía, que sí afirma
     * que la persona no estuvo contratada ni un día.
     *
     * @return list<Tramo>|null
     *
     * @throws MamoreException si la API está configurada pero no responde
     */
    public function tramos(string $ci, Carbon $desde, Carbon $hasta): ?array
    {
        if (! $this->mamore->configurado()) {
            return null;
        }

        $contratos = $this->mamore->contractsByCi(
            trim($ci),
            $desde->toDateString(),
            $hasta->toDateString(),
        );

        $tramos = [];

        foreach ($contratos as $contrato) {
            $inicio = $this->fecha($contrato['start'] ?? null);

            // Sin fecha de inicio no se sabe desde cuándo rige, así que no puede
            // habilitar ningún día.
            if ($inicio === null) {
                continue;
            }

            $tramos[] = [
                'desde' => $inicio,
                // Sin fecha de término el contrato sigue abierto.
                'hasta' => $this->fecha($contrato['finish'] ?? null),
            ];
        }

        usort($tramos, fn (array $a, array $b): int => $a['desde']->getTimestamp() <=> $b['desde']->getTimestamp());

        return $tramos;
    }

    /**
     * ¿Algún contrato cubre ese día?
     *
     * Con `$tramos` en `null` —no se conocen los contratos— devuelve `true` para
     * todos: es preferible procesar de más y que se revise, a dejar el reporte
     * en blanco sin que nadie entienda por qué.
     *
     * @param  list<Tramo>|null  $tramos
     */
    public function cubierto(?array $tramos, Carbon $fecha): bool
    {
        if ($tramos === null) {
            return true;
        }

        foreach ($tramos as $tramo) {
            if ($fecha->lessThan($tramo['desde'])) {
                continue;
            }

            if ($tramo['hasta'] === null || $fecha->lessThanOrEqualTo($tramo['hasta'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Una fecha de la API como Carbon al inicio del día, o `null` si no vino o
     * no se puede leer.
     */
    private function fecha(mixed $valor): ?Carbon
    {
        if (blank($valor)) {
            return null;
        }

        try {
            return Carbon::parse((string) $valor)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
