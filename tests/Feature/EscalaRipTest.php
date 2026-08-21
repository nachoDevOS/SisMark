<?php

use App\Services\EscalaRip;

/**
 * Las tres tablas del régimen disciplinario del RIP, fila por fila.
 *
 * `EscalaRip` no toca la base ni el reloj: entra un acumulado mensual y salen
 * las sanciones. Por eso cada tramo del reglamento se prueba como un caso
 * suelto, que es la única forma de tener confianza en algo que descuenta
 * sueldos.
 *
 * @param  array<string, mixed>  $campos
 * @return array<string, mixed>
 */
function acumuladoRip(array $campos = []): array
{
    return array_merge(EscalaRip::vacio(), $campos);
}

function escalaRip(): EscalaRip
{
    return new EscalaRip;
}

// ---------------------------------------------------------------------------
// Art. 45.I — atrasos acumulados en el mes
// ---------------------------------------------------------------------------

it('aplica la escala de atrasos del Art. 45.I', function (int $minutos, ?float $dias, string $articulo) {
    $sanciones = escalaRip()->evaluar(acumuladoRip(['atrasoSegundos' => $minutos * 60]));

    if ($dias === null && $articulo === '') {
        expect($sanciones)->toBe([]);

        return;
    }

    expect($sanciones)->toHaveCount(1)
        ->and($sanciones[0]['tipo'])->toBe(EscalaRip::ATRASO)
        ->and($sanciones[0]['dias'])->toBe($dias)
        ->and($sanciones[0]['articulo'])->toBe($articulo);
})->with([
    'sin sanción hasta 30' => [30, null, ''],
    '31 min → medio día' => [31, 0.5, '45.I'],
    '45 min → medio día' => [45, 0.5, '45.I'],
    '46 min → un día' => [46, 1.0, '45.I'],
    '60 min → un día' => [60, 1.0, '45.I'],
    '61 min → dos días' => [61, 2.0, '45.I'],
    '90 min → dos días' => [90, 2.0, '45.I'],
    '91 min → tres días' => [91, 3.0, '45.I'],
    '120 min → tres días' => [120, 3.0, '45.I'],
    '121 min → cuatro días' => [121, 4.0, '45.I'],
]);

it('no sanciona un mes sin atraso', function () {
    expect(escalaRip()->evaluar(acumuladoRip()))->toBe([]);
});

it('trunca los segundos sueltos: 30 min 59 seg sigue sin sanción', function () {
    expect(escalaRip()->evaluar(acumuladoRip(['atrasoSegundos' => 30 * 60 + 59])))->toBe([]);
});

it('escala el tope de 121 minutos según la reincidencia en la gestión', function (int $vez, ?float $dias, string $articulo, bool $proceso) {
    $sanciones = escalaRip()->evaluar(acumuladoRip([
        'atrasoSegundos' => 130 * 60,
        'atrasoReincidencia' => $vez,
    ]));

    expect($sanciones)->toHaveCount(1)
        ->and($sanciones[0]['dias'])->toBe($dias)
        ->and($sanciones[0]['articulo'])->toBe($articulo)
        ->and($sanciones[0]['procesoInterno'])->toBe($proceso);
})->with([
    'primera vez' => [1, 4.0, '45.I', false],
    'segunda vez' => [2, 6.0, '46.IV', false],
    'tercera vez' => [3, null, '47.III', true],
    'cuarta vez sigue siendo proceso' => [4, null, '47.III', true],
]);

// ---------------------------------------------------------------------------
// Art. 45.III — omisiones de registro
// ---------------------------------------------------------------------------

it('aplica la escala de omisiones del Art. 45.III', function (int $veces, ?float $dias, string $articulo) {
    $sanciones = escalaRip()->evaluar(acumuladoRip(['omisiones' => $veces]));

    expect($sanciones)->toHaveCount(1)
        ->and($sanciones[0]['tipo'])->toBe(EscalaRip::OMISION)
        ->and($sanciones[0]['dias'])->toBe($dias)
        ->and($sanciones[0]['articulo'])->toBe($articulo);
})->with([
    'primera vez' => [1, 0.5, '45.III'],
    'segunda vez' => [2, 1.0, '45.III'],
    'tercera vez' => [3, 2.0, '46.IV'],
    'cuarta vez → proceso interno' => [4, null, '47.III'],
    'quinta vez sigue siendo proceso' => [5, null, '47.III'],
]);

// ---------------------------------------------------------------------------
// Arts. 45.II, 46.IV, 47.III y 48 — inasistencias y ausencias
// ---------------------------------------------------------------------------

it('cobra dos días de remuneración por cada jornada de inasistencia', function (float $jornadas, int $dias, int $continuos, ?float $descuento, string $articulo) {
    $sanciones = escalaRip()->evaluar(acumuladoRip([
        'inasistenciaJornadas' => $jornadas,
        'inasistenciaDias' => $dias,
        'inasistenciaContinuos' => $continuos,
    ]));

    expect($sanciones)->toHaveCount(1)
        ->and($sanciones[0]['tipo'])->toBe(EscalaRip::INASISTENCIA)
        ->and($sanciones[0]['dias'])->toBe($descuento)
        ->and($sanciones[0]['articulo'])->toBe($articulo);
})->with([
    'media jornada → un día' => [0.5, 1, 1, 1.0, '45.II'],
    'una jornada → dos días' => [1.0, 1, 1, 2.0, '45.II'],
    'dos días continuos → cuatro días, grave' => [2.0, 2, 2, 4.0, '46.IV'],
    'dos días sueltos → grave igual' => [2.0, 2, 1, 4.0, '46.IV'],
    'tres continuos → abandono de funciones' => [3.0, 3, 3, null, '48'],
    'seis discontinuos → abandono de funciones' => [6.0, 6, 1, null, '48'],
]);

it('manda la ausencia en el puesto al Art. 47.III y no al 48', function () {
    $sanciones = escalaRip()->evaluar(acumuladoRip([
        'ausenciaJornadas' => 3.0,
        'ausenciaDias' => 3,
        'ausenciaContinuos' => 3,
    ]));

    expect($sanciones)->toHaveCount(1)
        ->and($sanciones[0]['tipo'])->toBe(EscalaRip::AUSENCIA)
        ->and($sanciones[0]['articulo'])->toBe('47.III')
        ->and($sanciones[0]['procesoInterno'])->toBeTrue();
});

it('cuenta las inasistencias y las ausencias por separado', function () {
    $sanciones = escalaRip()->evaluar(acumuladoRip([
        'inasistenciaJornadas' => 1.0,
        'inasistenciaDias' => 1,
        'inasistenciaContinuos' => 1,
        'ausenciaJornadas' => 1.0,
        'ausenciaDias' => 1,
        'ausenciaContinuos' => 1,
    ]));

    expect($sanciones)->toHaveCount(2)
        ->and(array_column($sanciones, 'tipo'))
        ->toEqualCanonicalizing([EscalaRip::INASISTENCIA, EscalaRip::AUSENCIA]);
});

// ---------------------------------------------------------------------------
// Totales
// ---------------------------------------------------------------------------

it('suma los días de todas las sanciones del mes', function () {
    $escala = escalaRip();

    $sanciones = $escala->evaluar(acumuladoRip([
        'atrasoSegundos' => 50 * 60,      // 1 día
        'omisiones' => 1,                  // medio día
        'inasistenciaJornadas' => 0.5,     // 1 día
        'inasistenciaDias' => 1,
        'inasistenciaContinuos' => 1,
    ]));

    expect($escala->totalDias($sanciones))->toBe(2.5);
});

it('no da un total en días si alguna sanción abrió proceso interno', function () {
    $escala = escalaRip();

    $sanciones = $escala->evaluar(acumuladoRip([
        'atrasoSegundos' => 50 * 60,
        'omisiones' => 4,
    ]));

    expect($escala->totalDias($sanciones))->toBeNull();
});

it('el mes sin sanciones da cero días', function () {
    expect(escalaRip()->totalDias([]))->toBe(0.0);
});

it('ordena las sanciones de la más grave a la más leve', function () {
    $sanciones = escalaRip()->evaluar(acumuladoRip([
        'atrasoSegundos' => 40 * 60,       // leve
        'omisiones' => 4,                   // gravísima
        'ausenciaJornadas' => 2.0,          // grave
        'ausenciaDias' => 2,
        'ausenciaContinuos' => 2,
    ]));

    expect(array_column($sanciones, 'gravedad'))
        ->toBe([EscalaRip::GRAVISIMA, EscalaRip::GRAVE, EscalaRip::LEVE]);
});

it('escribe los días como los escribe el reglamento', function (?float $dias, string $texto) {
    expect(EscalaRip::dias($dias))->toBe($texto);
})->with([
    'medio día' => [0.5, 'Medio día de la remuneración mensual'],
    'un día' => [1.0, '1 día de la remuneración mensual'],
    'cuatro días' => [4.0, '4 días de la remuneración mensual'],
    'dos días y medio' => [2.5, '2,5 días de la remuneración mensual'],
    'proceso interno' => [null, 'Proceso administrativo interno'],
]);
