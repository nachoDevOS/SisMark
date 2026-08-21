<?php

use App\Models\Persona;
use App\Services\HaberFuncionario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    // La ficha de Mamoré se cachea por un día: sin limpiarla, un caso se
    // llevaría el haber del anterior.
    Cache::flush();
});

/**
 * Deja a Mamoré respondiendo con un contrato firmado del haber y la vigencia
 * pedidos. `fakeMamore()` vive en tests/Pest.php.
 *
 * @param  array<string, mixed>  $contrato
 */
function conContratoDe(array $contrato = []): void
{
    fakeMamore([
        '7633685' => array_merge([
            'nombre' => 'IGNACIO MOLINA GUZMAN',
            'cargo' => 'TECNICO II',
            'direccion' => 'SDAF',
            'sueldo' => 6000,
            'bono' => 600,
            'start' => '2020-01-01',
            'finish' => null,
        ], $contrato),
    ]);
}

/**
 * Los dos contratos del caso real: uno se vence el 10 de agosto y otro arranca
 * el 20, con otro sueldo. Entre medio no hay ninguno.
 */
function conDosContratosEnAgosto(): void
{
    conContratoDe(['contratos' => [
        ['salary' => 5000, 'bonus' => null, 'start' => '2026-08-20', 'finish' => '2026-09-30'],
        ['salary' => 4500, 'bonus' => null, 'start' => '2026-05-01', 'finish' => '2026-08-10'],
    ]]);
}

/**
 * @return array<string, mixed>
 */
function haberDe(int $gestion = 2026, int $mes = 7): array
{
    return app(HaberFuncionario::class)->delMes('7633685', $gestion, $mes);
}

function elDiaDeAgosto(int $dia): Carbon
{
    return Carbon::create(2026, 8, $dia)->startOfDay();
}

// ---------------------------------------------------------------------------
// El divisor es siempre 30
// ---------------------------------------------------------------------------

it('divide el haber básico por 30 cuando el contrato cubre el mes entero', function () {
    Persona::factory()->create(['ci' => '7633685']);
    conContratoDe();

    $haber = haberDe();

    expect($haber['conocido'])->toBeTrue()
        ->and($haber['conHaber'])->toBeTrue()
        ->and($haber['divisor'])->toBe(30)
        ->and($haber['tramos'])->toHaveCount(1)
        ->and($haber['tramos'][0]['sueldo'])->toBe(6000.0)
        ->and($haber['tramos'][0]['base'])->toBe(6000.0)
        ->and($haber['tramos'][0]['valorDia'])->toBe(200.0);
});

it('usa 30 aunque el mes tenga 31 días', function () {
    Persona::factory()->create(['ci' => '7633685']);
    conContratoDe();

    expect(haberDe(2026, 8)['divisor'])->toBe(30);
});

it('usa 30 aunque febrero tenga 28 días', function () {
    Persona::factory()->create(['ci' => '7633685']);
    conContratoDe();

    $haber = haberDe(2026, 2);

    expect($haber['divisor'])->toBe(30)
        ->and($haber['diasDelMes'])->toBe(28)
        ->and($haber['tramos'][0]['valorDia'])->toBe(200.0);
});

it('sigue dividiendo por 30 aunque el contrato arranque en medio del mes', function () {
    Persona::factory()->create(['ci' => '7633685']);
    // Del 16 al 31 de julio: 16 días cubiertos, pero el día vale lo mismo.
    conContratoDe(['start' => '2026-07-16']);

    $haber = haberDe();

    // Dividir por los 16 días cubiertos daría 375 Bs: cuanto más corto el
    // contrato, más caro el día de sanción. Es al revés de lo que dice el
    // reglamento, que habla de «días de la remuneración mensual».
    expect($haber['divisor'])->toBe(30)
        ->and($haber['tramos'][0]['valorDia'])->toBe(200.0)
        ->and($haber['tramos'][0]['dias'])->toBe(16)
        ->and($haber['tramos'][0]['desde']->toDateString())->toBe('2026-07-16')
        ->and($haber['tramos'][0]['hasta']->toDateString())->toBe('2026-07-31');
});

it('sigue dividiendo por 30 cuando el contrato cubre nueve días de febrero', function () {
    Persona::factory()->create(['ci' => '7633685']);
    conContratoDe(['start' => '2026-02-20']);

    $haber = haberDe(2026, 2);

    // Por los días cubiertos serían 6000/9 = 666,67 Bs el día.
    expect($haber['tramos'][0]['dias'])->toBe(9)
        ->and($haber['tramos'][0]['valorDia'])->toBe(200.0);
});

it('respeta el divisor fijo si Recursos Humanos define uno', function () {
    Persona::factory()->create(['ci' => '7633685']);
    conContratoDe(['start' => '2026-07-16']);

    config()->set('rip.haber.divisorFijo', 15);

    expect(haberDe()['divisor'])->toBe(15);
});

it('suma el bono a la base solo si se lo configura', function () {
    Persona::factory()->create(['ci' => '7633685']);
    conContratoDe();

    expect(haberDe()['tramos'][0]['base'])->toBe(6000.0);

    config()->set('rip.haber.incluirBono', true);
    Cache::flush();

    $conBono = haberDe();

    expect($conBono['tramos'][0]['base'])->toBe(6600.0)
        ->and($conBono['tramos'][0]['valorDia'])->toBe(220.0)
        ->and($conBono['baseEtiqueta'])->toBe('haber básico más bono');
});

// ---------------------------------------------------------------------------
// Dos contratos en el mismo mes
// ---------------------------------------------------------------------------

it('arma un tramo por cada contrato del mes, con su propio valor de día', function () {
    Persona::factory()->create(['ci' => '7633685']);
    conDosContratosEnAgosto();

    $haber = haberDe(2026, 8);

    expect($haber['tramos'])->toHaveCount(2)
        // Ordenados por vigencia y recortados al mes.
        ->and($haber['tramos'][0]['desde']->toDateString())->toBe('2026-08-01')
        ->and($haber['tramos'][0]['hasta']->toDateString())->toBe('2026-08-10')
        ->and($haber['tramos'][0]['valorDia'])->toBe(150.0)
        ->and($haber['tramos'][1]['desde']->toDateString())->toBe('2026-08-20')
        ->and($haber['tramos'][1]['hasta']->toDateString())->toBe('2026-08-31')
        ->and($haber['tramos'][1]['valorDia'])->toBe(5000 / 30);
});

it('sabe qué contrato regía en cada fecha y cuál no cubre ninguno', function () {
    Persona::factory()->create(['ci' => '7633685']);
    conDosContratosEnAgosto();

    $servicio = app(HaberFuncionario::class);
    $haber = haberDe(2026, 8);

    expect($servicio->valorDiaEn($haber, elDiaDeAgosto(5)))->toBe(150.0)
        ->and($servicio->cubierto($haber, elDiaDeAgosto(5)))->toBeTrue()
        // En el hueco no es funcionario: no hay día que valorar.
        ->and($servicio->valorDiaEn($haber, elDiaDeAgosto(14)))->toBeNull()
        ->and($servicio->cubierto($haber, elDiaDeAgosto(14)))->toBeFalse()
        ->and($servicio->valorDiaEn($haber, elDiaDeAgosto(25)))->toBe(5000 / 30)
        ->and($servicio->cubierto($haber, elDiaDeAgosto(25)))->toBeTrue();
});

it('reparte los días de la sanción entre los contratos que la generaron', function () {
    Persona::factory()->create(['ci' => '7633685']);
    conDosContratosEnAgosto();

    $servicio = app(HaberFuncionario::class);
    $haber = haberDe(2026, 8);

    // 65 minutos de atraso en el mes → 2 días (Art. 45.I, tramo 61-90).
    // 45 min se acumularon bajo el contrato viejo y 20 bajo el nuevo.
    $monto = $servicio->montoRepartido(2.0, $haber, [0 => 2700.0, 1 => 1200.0]);

    expect($monto)->toBe(310.26);

    // Con un solo valor de día habrían sido 2 × 416,67 = 833,33 Bs.
    expect($monto)->toBeLessThan(833.33);
});

it('no da monto si algún pedazo cae en un contrato sin sueldo', function () {
    Persona::factory()->create(['ci' => '7633685']);

    conContratoDe(['contratos' => [
        ['salary' => null, 'bonus' => null, 'start' => '2026-08-20', 'finish' => '2026-09-30'],
        ['salary' => 4500, 'bonus' => null, 'start' => '2026-05-01', 'finish' => '2026-08-10'],
    ]]);

    $servicio = app(HaberFuncionario::class);
    $haber = haberDe(2026, 8);

    // Un monto al que le falta una parte parece completo y no lo es.
    expect($servicio->montoRepartido(2.0, $haber, [0 => 2700.0, 1 => 1200.0]))->toBeNull()
        // Si todo cae en el contrato que sí tiene sueldo, se puede cobrar.
        ->and($servicio->montoRepartido(2.0, $haber, [0 => 2700.0]))->toBe(300.0);
});

// ---------------------------------------------------------------------------
// Lo que nunca hace: inventar un monto
// ---------------------------------------------------------------------------

it('da por cubiertos todos los días si no se conocen los contratos', function () {
    // Mamoré caído: no se puede concluir que la persona no es funcionaria. Se
    // la califica igual y lo único que falta es el monto.
    Persona::factory()->create(['ci' => '7633685']);
    fakeMamore([]);

    $haber = haberDe();

    expect($haber['conocido'])->toBeFalse()
        ->and($haber['conHaber'])->toBeFalse()
        ->and(app(HaberFuncionario::class)->cubierto($haber, Carbon::create(2026, 7, 15)))->toBeTrue();
});

it('no arma tramo si el contrato no toca el mes', function () {
    Persona::factory()->create(['ci' => '7633685']);
    conContratoDe(['finish' => '2026-06-30']);

    $haber = haberDe(2026, 7);

    expect($haber['tramos'])->toBe([])
        ->and($haber['conHaber'])->toBeFalse();
});

it('no inventa un haber cuando el contrato no trae sueldo', function () {
    Persona::factory()->create(['ci' => '7633685']);
    conContratoDe(['sueldo' => null]);

    $haber = haberDe();

    // El tramo existe —es funcionario, y sus días se califican— pero sin precio.
    expect($haber['tramos'])->toHaveCount(1)
        ->and($haber['tramos'][0]['valorDia'])->toBeNull()
        ->and($haber['conHaber'])->toBeFalse();
});

it('no da monto cuando no hay días', function () {
    Persona::factory()->create(['ci' => '7633685']);
    conContratoDe();

    $servicio = app(HaberFuncionario::class);
    $haber = haberDe();

    // Proceso interno: no hay días, así que no hay monto.
    expect($servicio->montoRepartido(null, $haber, [0 => 1.0]))->toBeNull()
        // Sin reparto tampoco: nada que imputar.
        ->and($servicio->montoRepartido(2.0, $haber, []))->toBeNull()
        ->and($servicio->montoRepartido(2.5, $haber, [0 => 1.0]))->toBe(500.0);
});

it('escribe los importes como se escriben en Bolivia', function (?float $monto, string $texto) {
    expect(HaberFuncionario::bolivianos($monto))->toBe($texto);
})->with([
    'sin dato' => [null, '—'],
    'cero' => [0.0, '0,00 Bs'],
    'con decimales' => [200.5, '200,50 Bs'],
    'con miles' => [1234.56, '1.234,56 Bs'],
]);
