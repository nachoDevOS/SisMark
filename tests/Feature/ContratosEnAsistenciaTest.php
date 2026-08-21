<?php

use App\Models\AsignacionTurno;
use App\Models\Asistencia;
use App\Models\Persona;
use App\Models\Turno;
use App\Services\ProcesadorAsistencia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| El contrato es la primera puerta del control de asistencia
|--------------------------------------------------------------------------
|
| Un día que ningún contrato de Mamoré cubre no se procesa, aunque la persona
| tenga turno asignado y aunque haya marcado. Sin contrato no era funcionaria,
| así que no había jornada que cumplir.
|
| El caso real que lo motivó: MILTON HIPAMO CHOLIMA, CI 10824260, con tres
| contratos en 2026, dos huecos entre ellos y marcaciones dentro de los huecos.
|
*/

const CI_CONTRATO = '10824260';

/**
 * Turno de lunes asignado todo julio de 2026, que tiene lunes 6, 13, 20 y 27.
 */
function funcionarioParaContratos(): void
{
    $hora = fn (string $hm): string => "1899-12-30 {$hm}:00";

    $turno = Turno::factory()->create([
        'dia' => '2',
        'nombreTurno' => 'LUN: 08:00 - 16:00',
        'hEntrada' => $hora('08:00'),
        'hTolerancia' => $hora('08:10'),
        'eMinima' => $hora('07:00'),
        'eMaxima' => $hora('09:00'),
        'hSalida' => $hora('16:00'),
        'sTolerancia' => $hora('16:00'),
        'sMinima' => $hora('16:00'),
        'sMaxima' => $hora('20:00'),
        'hTrabajadas' => 8,
        'siguienteDia' => false,
    ]);

    Persona::factory()->create(['ci' => CI_CONTRATO, 'nombres' => 'MILTON']);

    AsignacionTurno::factory()->create([
        'ci' => CI_CONTRATO,
        'turno_id' => $turno->id,
        'desde' => '2026-07-01 00:00:00',
        'hasta' => '2026-07-31 00:00:00',
    ]);
}

/**
 * @param  list<array<string, mixed>>  $contratos
 */
function fakeContratosDe(array $contratos): void
{
    fakeMamore([CI_CONTRATO => [
        'nombre' => 'MILTON HIPAMO CHOLIMA',
        'cargo' => 'TECNICO II',
        'contratos' => array_map(
            fn (array $c): array => $c + ['salary' => null, 'bonus' => null],
            $contratos,
        ),
    ]]);
}

/**
 * @return Collection<int, array<string, mixed>>
 */
function julioDeContratos(): Collection
{
    return app(ProcesadorAsistencia::class)->procesar(
        CI_CONTRATO,
        Carbon::parse('2026-07-01'),
        Carbon::parse('2026-07-31'),
    );
}

/**
 * @return array<string, array<string, mixed>>
 */
function julioDeContratosPorFecha(): array
{
    return julioDeContratos()
        ->keyBy(fn (array $dia): string => $dia['fecha']->toDateString())
        ->all();
}

it('no procesa el día que ningún contrato cubre, aunque tenga turno', function () {
    funcionarioParaContratos();

    // El contrato arranca el 15: los lunes 6 y 13 quedan afuera.
    fakeContratosDe([['start' => '2026-07-15', 'finish' => '2026-12-31']]);

    $dias = julioDeContratosPorFecha();

    expect($dias['2026-07-06']['estado'])->toBe(ProcesadorAsistencia::SIN_CONTRATO)
        ->and($dias['2026-07-13']['estado'])->toBe(ProcesadorAsistencia::SIN_CONTRATO)
        // Sin bloques: no se controló nada, así que no hay falta ni horas.
        ->and($dias['2026-07-06']['bloques'])->toBe([])
        ->and($dias['2026-07-06']['esperado'])->toBe(0)
        // Del 15 en adelante sí se procesa: el lunes 20 no marcó, es falta.
        ->and($dias['2026-07-20']['estado'])->toBe(ProcesadorAsistencia::FALTA);
});

it('tampoco procesa el día del hueco entre dos contratos', function () {
    funcionarioParaContratos();

    // Hueco del 08 al 15: el lunes 13 cae adentro.
    fakeContratosDe([
        ['start' => '2026-06-01', 'finish' => '2026-07-07'],
        ['start' => '2026-07-16', 'finish' => '2026-12-31'],
    ]);

    $dias = julioDeContratosPorFecha();

    expect($dias['2026-07-06']['estado'])->toBe(ProcesadorAsistencia::FALTA)
        ->and($dias['2026-07-13']['estado'])->toBe(ProcesadorAsistencia::SIN_CONTRATO)
        ->and($dias['2026-07-20']['estado'])->toBe(ProcesadorAsistencia::FALTA);
});

it('no procesa el día del hueco ni siquiera si la persona marcó', function () {
    funcionarioParaContratos();

    fakeContratosDe([['start' => '2026-07-16', 'finish' => '2026-12-31']]);

    // El lunes 13 fue a trabajar jornada completa, sin contrato vigente.
    Asistencia::factory()->create(['ci' => CI_CONTRATO, 'fecha' => '2026-07-13', 'hora' => '08:02:00']);
    Asistencia::factory()->create(['ci' => CI_CONTRATO, 'fecha' => '2026-07-13', 'hora' => '16:10:00']);

    $lunes13 = julioDeContratosPorFecha()['2026-07-13'];

    // Ni cumple ni falta: el día no se controla.
    expect($lunes13['estado'])->toBe(ProcesadorAsistencia::SIN_CONTRATO)
        ->and($lunes13['computado'])->toBe(0)
        ->and($lunes13['esperado'])->toBe(0)
        // Las marcas viajan igual: la solapa Marcaciones las sigue mostrando.
        ->and($lunes13['marcas'])->toHaveCount(2);
});

it('un sábado sin contrato sigue siendo un sábado, no un día sin contrato', function () {
    funcionarioParaContratos();
    fakeContratosDe([['start' => '2026-07-16', 'finish' => '2026-12-31']]);

    // El 11/07/2026 es sábado: no tenía turno igual, con contrato o sin él.
    // Marcarlo «sin contrato» llenaría el reporte de avisos que no son avisos.
    expect(julioDeContratosPorFecha()['2026-07-11']['estado'])->toBe(ProcesadorAsistencia::NO_LABORABLE);
});

it('sin ningún contrato no se controla ningún día del rango', function () {
    funcionarioParaContratos();

    // Persona en Mamoré pero sin contratos: son las 11 que tienen turno
    // asignado y ni un contrato cargado.
    fakeMamore([CI_CONTRATO => ['nombre' => 'MILTON HIPAMO CHOLIMA']]);

    $estados = julioDeContratos()->pluck('estado')->unique()->values()->all();

    sort($estados);

    expect($estados)->toBe([
        ProcesadorAsistencia::NO_LABORABLE,
        ProcesadorAsistencia::SIN_CONTRATO,
    ]);
});

it('procesa todo como antes si Mamoré no está configurado', function () {
    // No saber no puede significar «no tuvo contrato»: dejaría el reporte de
    // todo el mundo en blanco sin que nadie entienda por qué.
    funcionarioParaContratos();

    config()->set('services.mamore', ['url' => null, 'key' => null]);

    $dias = julioDeContratosPorFecha();

    expect($dias['2026-07-06']['estado'])->toBe(ProcesadorAsistencia::FALTA)
        ->and($dias['2026-07-13']['estado'])->toBe(ProcesadorAsistencia::FALTA);
});

it('el reporte procesado no se genera si no se pudieron verificar los contratos', function () {
    // Sin contratos verificados no se procesa nada: generar el reporte igual
    // daría faltas en días que quizá estaban cubiertos por una renovación.
    $this->actingAs(asSuperAdmin());

    funcionarioParaContratos();

    config()->set('services.mamore.url', 'http://mamore.test/api/personal');
    config()->set('services.mamore.key', 'secreta');

    // La ficha responde, pero los contratos no: es el caso de una caída parcial.
    Http::fake([
        'mamore.test/api/personal/people/ci/*/contracts*' => Http::response(['message' => 'boom'], 500),
        'mamore.test/api/personal/people/ci/*' => Http::response(['data' => [
            'ci' => CI_CONTRATO,
            'full_name' => 'MILTON HIPAMO CHOLIMA',
        ]]),
    ]);

    // La tabla se pide por AJAX, así que el aviso vuelve como parcial. Una
    // redirección la seguiría `fetch` y dibujaría la pantalla entera —sidebar
    // incluido— adentro del recuadro de la tabla.
    $this->get(route('reportes.marcaciones.procesado.generar', [
        'persona' => CI_CONTRATO,
        'desde' => '2026-07-01',
        'hasta' => '2026-07-31',
    ]))
        ->assertOk()
        ->assertSee('No se pudieron verificar los contratos en Mamoré')
        ->assertSee('aviso--error', escape: false)
        // Nada de layout: es un parcial, no una página.
        ->assertDontSee('<!DOCTYPE html>', escape: false);
});

it('el imprimible sí redirige cuando el reporte no se puede generar', function () {
    // El imprimible se abre con un enlace, no por AJAX: ahí la redirección con
    // su mensaje es lo correcto.
    $this->actingAs(asSuperAdmin());

    $this->get(route('reportes.marcaciones.procesado.generar', ['persona' => '', 'print' => 1]))
        ->assertRedirect(route('reportes.marcaciones.procesado'))
        ->assertSessionHas('error');
});
