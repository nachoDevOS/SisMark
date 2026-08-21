<?php

namespace App\Services;

/**
 * Traduce lo que un funcionario acumuló en un mes a las sanciones del régimen
 * disciplinario del Reglamento Interno de Personal (RIP 2025, Parte I).
 *
 * Es **función pura**: no toca la base ni el reloj. Entra un acumulado mensual,
 * salen las sanciones que le corresponden con su artículo. Eso permite probar
 * cada fila de las tres tablas del reglamento como un caso suelto, que es la
 * única forma de tener confianza en algo que descuenta sueldos.
 *
 * Las tres escalas viven en `config/rip.php`, no acá: dos artículos del propio
 * reglamento se contradicen y la interpretación la firma Recursos Humanos.
 *
 * **Esta clase no decide si una conducta ocurrió**, solo cuánto cuesta. Quién
 * llegó tarde y quién no marcó lo resuelve {@see CalificadorRip} a partir de
 * {@see ProcesadorAsistencia}.
 *
 * @phpstan-type Sancion array{tipo: string, tipoEtiqueta: string, gravedad: string, gravedadEtiqueta: string, articulo: string, detalle: string, dias: ?float, procesoInterno: bool, reincidencia: int, reincidenciaEtiqueta: string}
 * @phpstan-type Acumulado array{atrasoSegundos: int, atrasoReincidencia: int, inasistenciaJornadas: float, inasistenciaDias: int, inasistenciaContinuos: int, ausenciaJornadas: float, ausenciaDias: int, ausenciaContinuos: int, omisiones: int}
 */
class EscalaRip
{
    /**
     * Los minutos de atraso se miden desde la hora nominal de entrada.
     */
    public const DESDE_NOMINAL = 'nominal';

    /**
     * Los minutos de atraso se miden desde el fin de la tolerancia.
     */
    public const DESDE_TOLERANCIA = 'tolerancia';

    public const LEVE = 'leve';

    public const GRAVE = 'grave';

    public const GRAVISIMA = 'gravisima';

    /**
     * Cómo se escribe cada gravedad en pantalla.
     *
     * @var array<string, string>
     */
    public const GRAVEDADES = [
        self::LEVE => 'Falta leve',
        self::GRAVE => 'Falta grave',
        self::GRAVISIMA => 'Falta gravísima',
    ];

    public const ATRASO = 'atraso';

    public const INASISTENCIA = 'inasistencia';

    public const AUSENCIA = 'ausencia';

    public const OMISION = 'omision';

    /**
     * Cómo se rotula cada conducta.
     *
     * @var array<string, string>
     */
    public const TIPOS = [
        self::ATRASO => 'Atrasos acumulados en el mes',
        self::INASISTENCIA => 'Inasistencias',
        self::AUSENCIA => 'Ausencias en el puesto de trabajo',
        self::OMISION => 'Omisiones en el registro de asistencia',
    ];

    /**
     * Acumulado en cero, para arrancar a sumar.
     *
     * @return Acumulado
     */
    public static function vacio(): array
    {
        return [
            'atrasoSegundos' => 0,
            // Qué número de vez en la gestión que este funcionario pasa de los
            // 120 minutos. Lo aporta quien llama, porque exige mirar los meses
            // anteriores y esta clase no consulta nada.
            'atrasoReincidencia' => 1,
            'inasistenciaJornadas' => 0.0,
            'inasistenciaDias' => 0,
            'inasistenciaContinuos' => 0,
            'ausenciaJornadas' => 0.0,
            'ausenciaDias' => 0,
            'ausenciaContinuos' => 0,
            'omisiones' => 0,
        ];
    }

    /**
     * Todas las sanciones que corresponden a un mes, en orden de gravedad
     * decreciente. Vacío significa mes sin observaciones, que es el caso normal
     * y hay que mostrarlo como tal.
     *
     * @param  Acumulado  $acumulado
     * @return list<Sancion>
     */
    public function evaluar(array $acumulado): array
    {
        $sanciones = array_values(array_filter([
            $this->atraso($acumulado['atrasoSegundos'], $acumulado['atrasoReincidencia']),
            $this->ausentismo(
                self::INASISTENCIA,
                $acumulado['inasistenciaJornadas'],
                $acumulado['inasistenciaDias'],
                $acumulado['inasistenciaContinuos'],
            ),
            $this->ausentismo(
                self::AUSENCIA,
                $acumulado['ausenciaJornadas'],
                $acumulado['ausenciaDias'],
                $acumulado['ausenciaContinuos'],
            ),
            $this->omision($acumulado['omisiones']),
        ]));

        $orden = [self::GRAVISIMA => 0, self::GRAVE => 1, self::LEVE => 2];

        usort($sanciones, fn (array $a, array $b): int => $orden[$a['gravedad']] <=> $orden[$b['gravedad']]);

        return $sanciones;
    }

    /**
     * Cuántos días de la remuneración suman todas las sanciones del mes.
     *
     * `null` cuando alguna abrió proceso interno: ahí todavía no hay monto, y
     * devolver un número daría por resuelto algo que decide la Autoridad
     * Sumariante (Art. 47).
     *
     * @param  list<Sancion>  $sanciones
     */
    public function totalDias(array $sanciones): ?float
    {
        if ($sanciones === []) {
            return 0.0;
        }

        foreach ($sanciones as $sancion) {
            if ($sancion['procesoInterno']) {
                return null;
            }
        }

        return (float) array_sum(array_column($sanciones, 'dias'));
    }

    /**
     * Art. 45.I — escala por minutos de atraso acumulados en el mes.
     *
     * Pasado el último tramo manda la reincidencia en la gestión: la tercera
     * vez ya no se descuenta, se abre proceso interno.
     *
     * @return ?Sancion
     */
    private function atraso(int $segundos, int $reincidencia): ?array
    {
        // Se trunca a minutos enteros: la escala del reglamento habla de
        // minutos, y 30 min 40 seg no pasa a la franja siguiente.
        $minutos = intdiv(max(0, $segundos), 60);

        if ($minutos === 0) {
            return null;
        }

        foreach ((array) config('rip.escalas.atraso') as [$hasta, $dias]) {
            if ($minutos <= $hasta) {
                return $dias > 0
                    ? $this->sancion(self::ATRASO, self::LEVE, '45.I', $dias, "{$minutos} minutos de atraso acumulados en el mes.")
                    : null;
            }
        }

        $tramos = (array) config('rip.escalas.atrasoReincidencia');
        $tope = max(array_keys($tramos));
        $tramo = $tramos[min($reincidencia, $tope)];

        return $this->sancion(
            self::ATRASO,
            $tramo['gravedad'],
            $tramo['articulo'],
            $tramo['dias'],
            "{$minutos} minutos de atraso acumulados en el mes.",
            $reincidencia,
            $this->vezEnLaGestion($reincidencia),
        );
    }

    /**
     * Art. 45.II, 46.IV, 47.III y 48 — inasistencias y ausencias en el puesto.
     *
     * El descuento es la misma razón en los tres artículos —dos días de
     * remuneración por cada jornada— y lo que cambia con la acumulación es la
     * gravedad. Pasados los umbrales de continuidad ya no hay monto: es proceso
     * interno.
     *
     * @return ?Sancion
     */
    private function ausentismo(string $tipo, float $jornadas, int $dias, int $continuos): ?array
    {
        if ($jornadas <= 0) {
            return null;
        }

        $reglas = (array) config('rip.escalas.ausentismo');

        $esInasistencia = $tipo === self::INASISTENCIA;
        $unidad = $jornadas === 1.0 ? 'jornada' : 'jornadas';
        $detalle = rtrim(rtrim(number_format($jornadas, 2, ',', ''), '0'), ',')." {$unidad} en el mes.";

        // Art. 47.III para la ausencia en el puesto; Art. 48 para la
        // inasistencia, que a esta altura es abandono de funciones.
        if ($continuos >= $reglas['continuosProceso'] || $dias >= $reglas['discontinuosProceso']) {
            return $this->sancion(
                $tipo,
                self::GRAVISIMA,
                $esInasistencia ? '48' : '47.III',
                null,
                $detalle.($esInasistencia
                    ? ' Configura abandono de funciones.'
                    : ' Supera el umbral de continuidad del reglamento.'),
            );
        }

        $grave = $continuos >= $reglas['continuosGrave'] || $dias >= $reglas['discontinuosGrave'];

        return $this->sancion(
            $tipo,
            $grave ? self::GRAVE : self::LEVE,
            $grave ? '46.IV' : '45.II',
            $jornadas * $reglas['porJornada'],
            $detalle,
        );
    }

    /**
     * Art. 45.III — omisiones no regularizadas, contadas dentro del mes.
     *
     * @return ?Sancion
     */
    private function omision(int $veces): ?array
    {
        if ($veces <= 0) {
            return null;
        }

        $tramos = (array) config('rip.escalas.omision');
        $tope = max(array_keys($tramos));
        $tramo = $tramos[min($veces, $tope)];
        $plural = $veces === 1 ? 'omisión' : 'omisiones';

        return $this->sancion(
            self::OMISION,
            $tramo['gravedad'],
            $tramo['articulo'],
            $tramo['dias'],
            "{$veces} {$plural} sin regularizar en el mes.",
            $veces,
            $veces === 1 ? 'Primera vez en el mes' : "{$veces}.ª vez en el mes",
        );
    }

    /**
     * Arma la sanción con las etiquetas ya resueltas: quien la muestra no tiene
     * por qué conocer las claves internas.
     *
     * @return Sancion
     */
    private function sancion(
        string $tipo,
        string $gravedad,
        string $articulo,
        ?float $dias,
        string $detalle,
        int $reincidencia = 1,
        string $reincidenciaEtiqueta = '',
    ): array {
        return [
            'tipo' => $tipo,
            'tipoEtiqueta' => self::TIPOS[$tipo],
            'gravedad' => $gravedad,
            'gravedadEtiqueta' => self::GRAVEDADES[$gravedad],
            'articulo' => $articulo,
            'detalle' => $detalle,
            // Null = proceso administrativo interno. No es cero ni es una
            // destitución consumada: el Art. 47 exige proceso previo ante la
            // Autoridad Sumariante, que puede resolver otra sanción.
            'dias' => $dias,
            'procesoInterno' => $dias === null,
            'reincidencia' => $reincidencia,
            'reincidenciaEtiqueta' => $reincidenciaEtiqueta,
        ];
    }

    /**
     * «1.ª vez en la gestión», para la escala de atrasos, que se cuenta del
     * 1 de enero al 31 de diciembre (Art. 50).
     */
    private function vezEnLaGestion(int $reincidencia): string
    {
        return $reincidencia <= 1
            ? 'Primera vez en la gestión'
            : "{$reincidencia}.ª vez en la gestión";
    }

    /**
     * Días de remuneración escritos como los escribe el reglamento.
     */
    public static function dias(?float $dias): string
    {
        if ($dias === null) {
            return 'Proceso administrativo interno';
        }

        if ($dias === 0.5) {
            return 'Medio día de la remuneración mensual';
        }

        $entero = $dias == (int) $dias;
        $texto = $entero ? (string) (int) $dias : number_format($dias, 1, ',', '');
        $unidad = $dias == 1 ? 'día' : 'días';

        return "{$texto} {$unidad} de la remuneración mensual";
    }
}
