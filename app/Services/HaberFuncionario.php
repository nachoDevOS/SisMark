<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * Cuánto vale un día de la remuneración de un funcionario, **en cada fecha del
 * mes**.
 *
 * Las escalas del RIP se expresan en «días de la remuneración mensual»
 * (Arts. 45 a 47). Para convertir eso en bolivianos hace falta el haber, y el
 * haber lo tiene Mamoré en el contrato firmado: SisMark no guarda sueldos ni
 * quiere guardarlos.
 *
 * **Un mes puede tener más de un contrato.** Se le vence uno el 10 y se le
 * firma otro el 20, con otro sueldo, y entre medio puede no haber ninguno. Por
 * eso acá no hay «el haber del mes» sino una lista de **tramos**: cada uno con
 * sus fechas y su valor de día. Un atraso del 5 se cobra al sueldo del contrato
 * viejo y uno del 25 al del nuevo.
 *
 * **El divisor es siempre 30**, tenga el mes 28, 29 o 31 días, y cubra el
 * contrato el mes entero o nueve días. Así se liquida el haber mensual en el
 * sector público: un día de la remuneración de un contrato de 5.000 Bs vale
 * 166,67 Bs siempre. Dividir por los días cubiertos haría que **cuanto más
 * corto el contrato, más caro el día de sanción**, que es exactamente al revés.
 *
 * **Sin contrato, sin sueldo cargado o con Mamoré caído no se inventa un
 * monto.** La pantalla muestra los días igual y aclara que no pudo pasarlos a
 * bolivianos. Nunca cae a cero: un descuento de 0 Bs sobre un dato faltante
 * parece correcto y no lo es.
 *
 * @phpstan-type Tramo array{desde: Carbon, hasta: Carbon, dias: int, sueldo: ?float, bono: ?float, base: ?float, valorDia: ?float}
 * @phpstan-type Haber array{tramos: list<Tramo>, conocido: bool, conHaber: bool, baseEtiqueta: string, divisor: int, diasDelMes: int}
 */
class HaberFuncionario
{
    public function __construct(
        private ResolutorNombres $resolutor,
        private MamoreClient $mamore,
    ) {}

    /**
     * Los tramos de contrato que tocan el mes, con el valor del día de cada uno.
     *
     * @return Haber
     */
    public function delMes(string $ci, int $gestion, int $mes): array
    {
        $ficha = $this->resolutor->fichaPorCi(trim($ci));

        $inicio = Carbon::create($gestion, $mes, 1)->startOfDay();
        $fin = $inicio->copy()->endOfMonth()->startOfDay();

        // Los contratos se piden **en vivo**, no salen de la ficha: esa está
        // cacheada por un día y de acá sale la plata que se le descuenta a una
        // persona. La ficha se sigue usando para la identidad, que no cambia.
        $contratos = $this->contratosVigentes(trim($ci), $inicio, $fin);
        $conBono = config('rip.haber.incluirBono') === true;
        $divisor = $this->divisor();

        $tramos = [];

        foreach ($contratos as $contrato) {
            $desde = $this->fecha($contrato['desde']);
            $hasta = $this->fecha($contrato['hasta']);

            // Sin fecha de inicio no se le puede imputar un día a este
            // contrato; sin fecha de término sigue abierto.
            if ($desde === null) {
                continue;
            }

            $recorteDesde = $desde->greaterThan($inicio) ? $desde : $inicio;
            $recorteHasta = $hasta !== null && $hasta->lessThan($fin) ? $hasta : $fin;

            // El contrato no toca este mes.
            if ($recorteDesde->greaterThan($recorteHasta)) {
                continue;
            }

            $sueldo = is_numeric($contrato['sueldo'] ?? null) && (float) $contrato['sueldo'] > 0
                ? (float) $contrato['sueldo']
                : null;
            $bono = is_numeric($contrato['bono'] ?? null) ? (float) $contrato['bono'] : null;
            $base = $sueldo === null ? null : ($conBono ? $sueldo + ($bono ?? 0) : $sueldo);

            $tramos[] = [
                'desde' => $recorteDesde,
                'hasta' => $recorteHasta,
                'dias' => (int) $recorteDesde->diffInDays($recorteHasta) + 1,
                'sueldo' => $sueldo,
                'bono' => $bono,
                'base' => $base,
                'valorDia' => $base === null ? null : $base / $divisor,
            ];
        }

        usort($tramos, fn (array $a, array $b): int => $a['desde']->getTimestamp() <=> $b['desde']->getTimestamp());

        return [
            'tramos' => $tramos,
            // **Distinguir «no tiene contrato» de «no sabemos».** Si Mamoré no
            // respondió, la ficha viene sin contratos y no hay que concluir que
            // la persona no es funcionaria: se la califica igual, como antes,
            // y lo único que falta es el monto.
            'conocido' => $contratos !== [],
            'conHaber' => $tramos !== [] && array_filter($tramos, fn (array $t): bool => $t['valorDia'] !== null) !== [],
            'baseEtiqueta' => $conBono ? 'haber básico más bono' : 'haber básico',
            'divisor' => $divisor,
            'diasDelMes' => (int) $inicio->daysInMonth,
        ];
    }

    /**
     * ¿La persona tenía contrato vigente en esa fecha?
     *
     * Cuando **no se conocen** sus contratos —Mamoré caído o sin configurar—
     * devuelve `true` para todas: es preferible calificar de más y que se
     * revise, a dejar el mes en blanco sin que nadie se entere de por qué.
     *
     * @param  Haber  $haber
     */
    public function cubierto(array $haber, Carbon $fecha): bool
    {
        if (! $haber['conocido']) {
            return true;
        }

        return $this->tramoDe($haber, $fecha) !== null;
    }

    /**
     * En qué tramo cae una fecha, o `null` si ninguno la cubre.
     *
     * @param  Haber  $haber
     * @return ?array{0: int, 1: Tramo}
     */
    public function tramoDe(array $haber, Carbon $fecha): ?array
    {
        foreach ($haber['tramos'] as $indice => $tramo) {
            if ($fecha->betweenIncluded($tramo['desde'], $tramo['hasta'])) {
                return [$indice, $tramo];
            }
        }

        return null;
    }

    /**
     * Cuánto valía un día de remuneración en esa fecha, o `null` si no hay
     * contrato o no trae sueldo cargado.
     *
     * @param  Haber  $haber
     */
    public function valorDiaEn(array $haber, Carbon $fecha): ?float
    {
        return $this->tramoDe($haber, $fecha)[1]['valorDia'] ?? null;
    }

    /**
     * Reparte los días de una sanción entre los contratos del mes y los cobra
     * a cada uno con su propio sueldo.
     *
     * `$pesos` es cuánto aportó cada tramo a esa sanción: minutos para los
     * atrasos, jornadas para las inasistencias, cantidad para las omisiones.
     * Se prorratea porque **las escalas del RIP son mensuales y acumulativas**:
     * los 2 días de 65 minutos salen de los 65 juntos, no de un tramo ni del
     * otro, así que la única forma de imputarlos es en proporción a lo que cada
     * contrato aportó.
     *
     * Devuelve `null` si algún pedazo cae en un tramo sin sueldo: un monto al
     * que le falta una parte parece completo y no lo es.
     *
     * @param  Haber  $haber
     * @param  array<int, float>  $pesos  índice de tramo => magnitud aportada
     */
    public function montoRepartido(?float $dias, array $haber, array $pesos): ?float
    {
        if ($dias === null || $pesos === []) {
            return null;
        }

        $total = array_sum($pesos);

        if ($total <= 0) {
            return null;
        }

        $monto = 0.0;

        foreach ($pesos as $indice => $peso) {
            $valorDia = $haber['tramos'][$indice]['valorDia'] ?? null;

            if ($valorDia === null) {
                return null;
            }

            $monto += $dias * ($peso / $total) * $valorDia;
        }

        return round($monto, 2);
    }

    /**
     * Los contratos que tocan el mes, preguntados a Mamoré en el momento.
     *
     * Sin la API configurada no hay de dónde sacarlos y se devuelve la lista
     * vacía: la sanción se muestra en días y la pantalla aclara que no se
     * pudo pasar a bolivianos. Si la API está configurada pero falla, la
     * excepción sube: un monto calculado a medias es peor que no dar monto.
     *
     * @return list<array{sueldo: mixed, bono: mixed, desde: mixed, hasta: mixed}>
     */
    private function contratosVigentes(string $ci, Carbon $inicio, Carbon $fin): array
    {
        if (! $this->mamore->configurado()) {
            return [];
        }

        return array_map(fn (array $contrato): array => [
            'sueldo' => $contrato['salary'] ?? null,
            'bono' => $contrato['bonus'] ?? null,
            'desde' => $contrato['start'] ?? null,
            'hasta' => $contrato['finish'] ?? null,
        ], $this->mamore->contractsByCi($ci, $inicio->toDateString(), $fin->toDateString()));
    }

    /**
     * Por cuántos días se divide la remuneración mensual: **siempre 30**.
     *
     * `rip.haber.divisorFijo` permite a Recursos Humanos imponer otro, por si
     * alguna vez define que se liquide por los días reales del mes.
     */
    private function divisor(): int
    {
        $fijo = config('rip.haber.divisorFijo');

        return is_numeric($fijo) && (int) $fijo > 0 ? (int) $fijo : 30;
    }

    /**
     * Una fecha de la ficha como Carbon, o `null` si no vino o no se puede leer.
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

    /**
     * Un importe escrito como se escribe en Bolivia: «1.234,56 Bs».
     */
    public static function bolivianos(?float $monto): string
    {
        return $monto === null ? '—' : number_format($monto, 2, ',', '.').' Bs';
    }
}
