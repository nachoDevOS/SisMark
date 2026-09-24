<?php

namespace App\Services;

use App\Exceptions\MamoreException;
use App\Http\Controllers\LicenciaController;
use App\Models\Configuracion;
use App\Models\Licencia;
use App\Services\ProcesadorAsistencia as P;
use Illuminate\Support\Carbon;

/**
 * Tope mensual de permisos por horas.
 *
 * La configuración ({@see Configuracion::TOPE_PERMISO_MENSUAL}) dice cuánto
 * tiempo de permiso puede sumar un funcionario en el mes. Cada mes arranca de
 * cero. Cómo se reparte con más de un contrato en el mes lo dice
 * {@see Configuracion::TOPE_PERMISO_ALCANCE}:
 *
 * - **Por contrato** (el de siempre): cada contrato vigente en el mes tiene su
 *   propia bolsa, y lo que sobra de una no pasa a la otra. Con el tope en 1 h 30
 *   y dos contratos en septiembre —del 1 al 15 y del 16 al 30— son dos bolsas de
 *   1 h 30: un permiso del día 10 gasta la primera, uno del día 20 la segunda.
 * - **Por mes**: una sola bolsa de 1 h 30 para todo septiembre, tenga los
 *   contratos que tenga. No se consulta Mamoré.
 *
 * Qué cuenta:
 *
 * - Solo los **permisos personales** ({@see Licencia::TIPO_PERSONAL}). Las
 *   licencias institucionales no descuentan, y a ellas no se les aplica el tope.
 * - Solo los permisos **por horas** (con entrada y salida de licencia). Los de
 *   turno completo no descuentan.
 * - **Al pedir**, los aprobados y los **pendientes**: si no, se podrían pedir
 *   varios a la vez que juntos pasen el tope. Se muestran aparte —«usado» es
 *   lo aprobado, «pendiente» lo que espera decisión— y los dos restan de lo
 *   que queda.
 * - **Al aprobar**, solo lo aprobado más la solicitud que se aprueba
 *   ({@see LicenciaController::aprobar()}): cubre que se haya bajado el tope
 *   con pedidos ya hechos. Los rechazados y los dados de baja nunca cuentan.
 * - Un permiso cuenta **una vez por día**, aunque ese día ocupe más de una fila
 *   —una por turno—, porque es el mismo tiempo pedido.
 *
 * Por contrato, los contratos salen de Mamoré. Si no está configurado o no
 * contesta, el mes entero es una sola bolsa: se aplica el tope igual, en vez de
 * dejar pasar cualquier cosa porque la API se cayó.
 *
 * @phpstan-import-type Tramo from ContratosFuncionario
 */
class TopePermisos
{
    public function __construct(private ContratosFuncionario $contratos) {}

    /**
     * El tope configurado en minutos, o `null` si no hay tope.
     */
    public function minutos(): ?int
    {
        $minutos = (int) Configuracion::valor(Configuracion::TOPE_PERMISO_MENSUAL);

        return $minutos > 0 ? $minutos : null;
    }

    /**
     * ¿Cada contrato del mes tiene su propio tope, o hay uno solo para el mes?
     */
    public function porContrato(): bool
    {
        return Configuracion::vigente(Configuracion::TOPE_PERMISO_ALCANCE) !== Configuracion::TOPE_POR_MES;
    }

    /**
     * Qué funcionarios se pasarían del tope si se les anota este permiso.
     *
     * `$fechasPorCi` son los días que el permiso **va a crear** de verdad, ya
     * sin los que están ocupados ({@see RegistroLicencia::fechasNuevas()}).
     *
     * `$tramos` son los contratos de cada carnet cuando ya los trae quien pide
     * —Mamoré—, igual que en {@see saldo()}. Sin ellos se le preguntan a Mamoré.
     *
     * @param  array<string, list<string>>  $fechasPorCi  fechas `Y-m-d` por carnet
     *                                                    `$conPendientes` en falso es el control al aprobar: solo lo aprobado.
     * @param  array<string, list<array{desde: Carbon, hasta: ?Carbon}>>|null  $tramos
     * @return array<string, list<string>> por carnet, un mensaje por cada bolsa que se pasa
     */
    public function excesos(array $fechasPorCi, string $lEntra, string $lSale, ?array $tramos = null, bool $conPendientes = true): array
    {
        $tope = $this->minutos();
        $fechasPorCi = array_filter($fechasPorCi);

        if ($tope === null || $fechasPorCi === []) {
            return [];
        }

        $excesos = [];

        foreach ($this->bolsas($fechasPorCi, $lEntra, $lSale, tramosDados: $tramos, conPendientes: $conPendientes) as $ci => $bolsas) {
            foreach ($bolsas as $bolsa) {
                if ($bolsa['pedido'] > 0 && $tope < $bolsa['usado'] + $bolsa['pendiente'] + $bolsa['pedido']) {
                    $excesos[$ci][] = $this->mensaje($bolsa, $tope);
                }
            }
        }

        return $excesos;
    }

    /**
     * El saldo de un funcionario en los meses que toca el rango, con el permiso
     * que se está por pedir sumado. Es lo que se muestra antes de guardar, y
     * sale del mismo cálculo que {@see excesos()}: lo que se ve y lo que se
     * bloquea no pueden diferir.
     *
     * Se listan todas las bolsas de esos meses —también las de un contrato sin
     * permisos todavía—, porque ver cuánto queda en cada una es el punto.
     * Sin horas cargadas, el pedido va en cero y se ve solo lo usado.
     *
     * `$tramos` son los contratos del funcionario cuando ya los trae quien
     * pregunta —Mamoré, que es su dueño—: con ellos no se sale a pedirlos. Sin
     * ellos, se le preguntan a Mamoré. Solo importan si se cuenta por contrato.
     *
     * `null` si no hay tope configurado.
     *
     * @param  list<string>  $fechas  los días que crearía el permiso
     * @param  list<array{desde: Carbon, hasta: ?Carbon}>|null  $tramos
     * @return array{tope: int, porContrato: bool, excede: bool, bolsas: list<array{titulo: string, usado: int, pendiente: int, pedido: int, queda: int, excede: bool}>}|null
     */
    public function saldo(string $ci, Carbon $desde, Carbon $hasta, array $fechas, ?string $lEntra, ?string $lSale, ?array $tramos = null): ?array
    {
        $tope = $this->minutos();

        if ($tope === null) {
            return null;
        }

        $ci = trim($ci);
        $bolsas = $this->bolsas(
            [$ci => $fechas],
            (string) $lEntra,
            (string) $lSale,
            $desde,
            $hasta,
            $tramos === null ? null : [$ci => $tramos],
        )[$ci] ?? [];

        $filas = array_map(fn (array $bolsa): array => [
            'titulo' => ucfirst($this->descripcion($bolsa)),
            'usado' => $bolsa['usado'],
            'pendiente' => $bolsa['pendiente'],
            'pedido' => $bolsa['pedido'],
            'queda' => max(0, $tope - $bolsa['usado'] - $bolsa['pendiente'] - $bolsa['pedido']),
            'excede' => $bolsa['pedido'] > 0 && $tope < $bolsa['usado'] + $bolsa['pendiente'] + $bolsa['pedido'],
        ], array_values($bolsas));

        return [
            'tope' => $tope,
            'porContrato' => $this->porContrato(),
            'excede' => in_array(true, array_column($filas, 'excede'), true),
            'bolsas' => $filas,
        ];
    }

    /**
     * Arma las bolsas de cada carnet: lo ya usado y lo que suma el permiso nuevo,
     * repartido por mes y —si se cuenta por contrato— por contrato.
     *
     * Con `$desde`/`$hasta` se abren además todas las bolsas de esos meses,
     * aunque estén vacías: es lo que necesita {@see saldo()} para mostrarlas.
     *
     * Con `$tramosDados` se usan esos contratos en vez de pedirlos a Mamoré.
     * Sin `$conPendientes` se cuenta solo lo aprobado.
     *
     * @param  array<string, list<string>>  $fechasPorCi
     * @param  array<string, list<array{desde: Carbon, hasta: ?Carbon}>>|null  $tramosDados
     * @return array<string, array<string, array{usado: int, pendiente: int, pedido: int, mes: Carbon, tramo: ?array}>>
     */
    private function bolsas(array $fechasPorCi, string $lEntra, string $lSale, ?Carbon $desde = null, ?Carbon $hasta = null, ?array $tramosDados = null, bool $conPendientes = true): array
    {
        $lEntra = substr(trim($lEntra), 0, 5);
        $lSale = substr(trim($lSale), 0, 5);
        $pedido = $lEntra !== '' && $lSale !== '' ? max(0, self::minutosEntre($lEntra, $lSale)) : 0;
        $abrirTodas = $desde !== null && $hasta !== null;

        if (! $abrirTodas) {
            $todas = array_merge(...array_values($fechasPorCi));
            sort($todas);
            $desde = Carbon::parse($todas[0]);
            $hasta = Carbon::parse(end($todas));
        }

        // Meses enteros: lo ya usado se cuenta desde el día 1 aunque el permiso
        // nuevo caiga a fin de mes.
        $desde = $desde->copy()->startOfMonth();
        $hasta = $hasta->copy()->endOfMonth()->startOfDay();

        $cis = array_map('strval', array_keys($fechasPorCi));
        // Por mes no hay contratos que mirar: sin tramos, cada mes es una sola
        // bolsa.
        $tramos = $this->porContrato() ? ($tramosDados ?? $this->tramos($cis, $desde, $hasta)) : null;
        $usados = $this->usados($cis, $desde, $hasta, $conPendientes);

        $resultado = [];

        foreach ($fechasPorCi as $ci => $fechas) {
            $ci = (string) $ci;
            $tramosCi = $tramos === null ? null : ($tramos[$ci] ?? []);

            /** @var array<string, array{usado: int, pendiente: int, pedido: int, mes: Carbon, tramo: ?array}> $bolsas */
            $bolsas = [];
            $contados = [];

            if ($abrirTodas) {
                $this->abrirBolsas($desde, $hasta, $tramosCi, $bolsas);
            }

            foreach ($usados[$ci] ?? [] as $permiso) {
                $clave = "{$permiso['fecha']}|{$permiso['entra']}|{$permiso['sale']}";

                if (isset($contados[$clave])) {
                    continue;
                }

                $contados[$clave] = true;
                $bolsa = $this->bolsa($permiso['fecha'], $tramosCi, $bolsas);
                $bolsas[$bolsa][$permiso['pendiente'] ? 'pendiente' : 'usado'] += self::minutosEntre($permiso['entra'], $permiso['sale']);
            }

            if ($pedido > 0) {
                foreach (array_unique($fechas) as $fecha) {
                    // El mismo permiso ya anotado ese día en otro turno no se
                    // cobra dos veces.
                    if (isset($contados["{$fecha}|{$lEntra}|{$lSale}"])) {
                        continue;
                    }

                    $bolsa = $this->bolsa($fecha, $tramosCi, $bolsas);
                    $bolsas[$bolsa]['pedido'] += $pedido;
                }
            }

            ksort($bolsas);
            $resultado[$ci] = $bolsas;
        }

        return $resultado;
    }

    /**
     * Abre vacías las bolsas de cada mes del rango: una por contrato que toque
     * el mes, o una sola si no hay contratos que mirar.
     *
     * @param  list<Tramo>|null  $tramos
     * @param  array<string, array{usado: int, pendiente: int, pedido: int, mes: Carbon, tramo: ?array}>  $bolsas
     */
    private function abrirBolsas(Carbon $desde, Carbon $hasta, ?array $tramos, array &$bolsas): void
    {
        for ($mes = $desde->copy()->startOfMonth(); $mes->lessThanOrEqualTo($hasta); $mes->addMonthNoOverflow()) {
            $finDeMes = $mes->copy()->endOfMonth()->startOfDay();
            $abiertas = 0;

            foreach ($tramos ?? [] as $tramo) {
                if ($tramo['desde']->greaterThan($finDeMes)
                    || ($tramo['hasta'] !== null && $tramo['hasta']->lessThan($mes))) {
                    continue;
                }

                // Un día del mes que el contrato cubre: el más tardío entre el
                // inicio del mes y el del contrato.
                $this->bolsa($tramo['desde']->max($mes)->toDateString(), $tramos, $bolsas);
                $abiertas++;
            }

            if ($abiertas === 0) {
                $this->bolsa($mes->toDateString(), $tramos, $bolsas);
            }
        }
    }

    /**
     * Los mismos excesos en un solo texto, para un aviso de error.
     *
     * @param  array<string, list<string>>  $excesos
     */
    public function mensajeDeExcesos(array $excesos, bool $conCarnet): string
    {
        $partes = [];

        foreach ($excesos as $ci => $mensajes) {
            foreach ($mensajes as $mensaje) {
                $partes[] = $conCarnet ? "CI {$ci}: {$mensaje}" : $mensaje;
            }
        }

        return 'Supera el tope mensual de permisos ('.self::duracion((int) $this->minutos())
            .($this->porContrato() ? ' por contrato' : ' por mes').'). '
            .implode(' ', $partes);
    }

    /**
     * Contratos de cada carnet en el rango, o `null` si no se pueden saber.
     *
     * @param  list<string>  $cis
     * @return array<string, list<Tramo>>|null
     */
    private function tramos(array $cis, Carbon $desde, Carbon $hasta): ?array
    {
        try {
            return $this->contratos->tramosDeVarios($cis, $desde, $hasta);
        } catch (MamoreException) {
            return null;
        }
    }

    /**
     * Permisos por horas que ya ocupan tiempo en el rango, por carnet.
     *
     * @param  list<string>  $cis
     * @return array<string, list<array{fecha: string, entra: string, sale: string, pendiente: bool}>>
     */
    private function usados(array $cis, Carbon $desde, Carbon $hasta, bool $conPendientes): array
    {
        return Licencia::query()
            ->whereIn('ci', $cis)
            ->where('tCompleto', false)
            ->whereIn('estado', $conPendientes ? [Licencia::APROBADO, Licencia::PENDIENTE] : [Licencia::APROBADO])
            // Solo los permisos personales: una licencia institucional —un acto,
            // una capacitación— no le come el tiempo al funcionario.
            ->where('tipo', Licencia::TIPO_PERSONAL)
            ->whereNotNull('lEntra')
            ->whereNotNull('lSale')
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->get(['ci', 'fecha', 'lEntra', 'lSale', 'estado'])
            ->groupBy(fn (Licencia $licencia): string => trim((string) $licencia->ci))
            ->map(fn ($licencias): array => $licencias
                ->map(fn (Licencia $licencia): array => [
                    'fecha' => $licencia->fecha->toDateString(),
                    'entra' => $licencia->lEntra->format('H:i'),
                    'sale' => $licencia->lSale->format('H:i'),
                    'pendiente' => $licencia->estado === Licencia::PENDIENTE,
                ])
                ->values()
                ->all())
            ->all();
    }

    /**
     * La bolsa a la que va un día: su mes y el contrato que lo cubre. La crea
     * vacía la primera vez.
     *
     * @param  list<Tramo>|null  $tramos
     * @param  array<string, array{usado: int, pendiente: int, pedido: int, mes: Carbon, tramo: ?array}>  $bolsas
     */
    private function bolsa(string $fecha, ?array $tramos, array &$bolsas): string
    {
        $dia = Carbon::parse($fecha)->startOfDay();
        $indice = null;

        foreach ($tramos ?? [] as $i => $tramo) {
            if ($dia->greaterThanOrEqualTo($tramo['desde'])
                && ($tramo['hasta'] === null || $dia->lessThanOrEqualTo($tramo['hasta']))) {
                $indice = $i;

                break;
            }
        }

        // Un día sin contrato que lo cubra —o sin contratos conocidos— va a la
        // bolsa general del mes.
        $clave = $dia->format('Y-m').'|'.($indice ?? '-');

        $bolsas[$clave] ??= [
            'usado' => 0,
            'pendiente' => 0,
            'pedido' => 0,
            'mes' => $dia->copy()->startOfMonth(),
            'tramo' => $indice === null ? null : $tramos[$indice],
        ];

        return $clave;
    }

    /**
     * @param  array{usado: int, pendiente: int, pedido: int, mes: Carbon, tramo: ?array}  $bolsa
     */
    private function mensaje(array $bolsa, int $tope): string
    {
        $queda = max(0, $tope - $bolsa['usado'] - $bolsa['pendiente']);
        $lleva = self::duracion($bolsa['usado']).' aprobado'
            .($bolsa['pendiente'] > 0 ? ' y '.self::duracion($bolsa['pendiente']).' pendiente' : '');

        return "En {$this->descripcion($bolsa)} ya lleva {$lleva}, y este permiso suma "
            .self::duracion($bolsa['pedido']).': le queda '.self::duracion($queda).'.';
    }

    /**
     * «septiembre de 2026», y el contrato si la bolsa es de uno.
     *
     * @param  array{usado: int, pendiente: int, pedido: int, mes: Carbon, tramo: ?array}  $bolsa
     */
    private function descripcion(array $bolsa): string
    {
        $texto = $bolsa['mes']->translatedFormat('F \d\e Y');

        if ($bolsa['tramo'] !== null) {
            $texto .= ' (contrato del '.$bolsa['tramo']['desde']->format('d/m/Y')
                .($bolsa['tramo']['hasta'] === null ? ' en adelante)' : ' al '.$bolsa['tramo']['hasta']->format('d/m/Y').')');
        }

        return $texto;
    }

    private static function minutosEntre(string $entra, string $sale): int
    {
        return (int) Carbon::parse($entra)->diffInMinutes(Carbon::parse($sale), false);
    }

    private static function duracion(int $minutos): string
    {
        return P::duracion($minutos * 60);
    }
}
