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
 * @phpstan-type Tramo array{desde: Carbon, hasta: ?Carbon, direccion: ?string, cargo: ?string}
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

        return $this->tramosDeContratos($this->mamore->contractsByCi(
            trim($ci),
            $desde->toDateString(),
            $hasta->toDateString(),
        ));
    }

    /**
     * Lo mismo para **varias** personas, en una sola petición.
     *
     * Existe por el reporte por dirección: preguntar de a uno por una plantilla
     * de cientos de personas son cientos de viajes en serie, y con la cuota de
     * 60 pedidos por minuto de la API el reporte ni siquiera terminaría.
     *
     * `null` sigue siendo «no se sabe» y vale para todas: si Mamoré no está
     * configurado no hay contratos que consultar para ninguna cédula.
     *
     * Una cédula que Mamoré no conoce vuelve con lista vacía, no ausente: para
     * quien procesa, «no tuvo contrato» excluye sus días y «no vino en la
     * respuesta» sería un dato sin verificar.
     *
     * @param  list<string>  $cis
     * @return array<string, list<Tramo>>|null
     *
     * @throws MamoreException si la API está configurada pero no responde
     */
    public function tramosDeVarios(array $cis, Carbon $desde, Carbon $hasta): ?array
    {
        if (! $this->mamore->configurado()) {
            return null;
        }

        $porCi = $this->mamore->contractsByCis(
            $cis,
            $desde->toDateString(),
            $hasta->toDateString(),
        );

        $tramos = [];

        foreach ($cis as $ci) {
            $ci = trim($ci);
            $tramos[$ci] = $this->tramosDeContratos($porCi[$ci] ?? []);
        }

        return $tramos;
    }

    /**
     * Los tramos que manda el consumidor en el pedido (`contratos`), para no
     * salir a preguntárselos a Mamoré.
     *
     * **La lista vacía no es lo mismo que no mandar nada.** Vacía significa «no
     * tuvo contrato en el rango»; no mandar `contratos` significa «no sé» y ahí
     * se le pregunta a Mamoré. La diferencia la resuelve quien llama con
     * `$request->has('contratos')`, no este método.
     *
     * @param  array<int, array{desde: string, hasta?: ?string}>  $contratos
     * @return list<array{desde: Carbon, hasta: ?Carbon}>
     */
    public static function delPedido(array $contratos): array
    {
        return collect($contratos)
            ->map(fn (array $tramo): array => [
                'desde' => Carbon::parse($tramo['desde'])->startOfDay(),
                'hasta' => blank($tramo['hasta'] ?? null) ? null : Carbon::parse($tramo['hasta'])->startOfDay(),
            ])
            ->sortBy(fn (array $tramo): int => $tramo['desde']->getTimestamp())
            ->values()
            ->all();
    }

    /**
     * Traduce los contratos que devuelve la API a tramos ordenados.
     *
     * Cada tramo lleva la dirección y el cargo **de ese contrato**, no los de
     * hoy: quien se movió de dirección a mitad de año tiene dos tramos y el
     * reporte tiene que poder decir cuál fue cuál.
     *
     * @param  list<array<string, mixed>>  $contratos
     * @return list<Tramo>
     */
    private function tramosDeContratos(array $contratos): array
    {
        $tramos = [];

        foreach ($contratos as $contrato) {
            $inicio = $this->fecha($contrato['start'] ?? null);

            // Sin fecha de inicio no se sabe desde cuándo rige, así que no puede
            // habilitar ningún día.
            if ($inicio === null) {
                continue;
            }

            $direccion = is_array($contrato['direccion_administrativa'] ?? null)
                ? $contrato['direccion_administrativa']
                : null;

            $tramos[] = [
                'desde' => $inicio,
                // Sin fecha de término el contrato sigue abierto.
                'hasta' => $this->fecha($contrato['finish'] ?? null),
                'direccion' => $direccion === null ? null : (trim((string) (
                    $direccion['sigla'] ?? $direccion['nombre'] ?? ''
                )) ?: null),
                'cargo' => trim((string) ($contrato['job']['name'] ?? '')) ?: null,
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
