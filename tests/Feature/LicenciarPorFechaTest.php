<?php

use App\Models\AsignacionHorario;
use App\Models\Licencia;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/*
| Pantalla «Licenciar» con la fecha primero: se licencia a quien tuvo contrato
| esos días —firmado o ya concluido—, aunque hoy ya no esté.
|
| 111 tuvo contrato hasta el 15/10 (hoy concluido); 222 no tuvo contrato en
| octubre.
*/
beforeEach(function (): void {
    Carbon::setTestNow('2026-10-20');
    config(['services.mamore.url' => 'https://mamore.test/api', 'services.mamore.token' => 'token']);

    Http::fake([
        'mamore.test/api/people/contracts*' => Http::response(['data' => [
            '111' => [['id' => 1, 'status' => 'concluido', 'start' => '2026-01-05', 'finish' => '2026-10-15']],
            '222' => [],
        ]]),
        'mamore.test/api/people/ci/111' => Http::response(['data' => ['ci' => '111', 'full_name' => 'JUAN PEREZ']]),
        'mamore.test/api/people*' => Http::response(['data' => [[
            'ci' => '111', 'full_name' => 'JUAN PEREZ',
            'contrato' => ['status' => 'concluido', 'start' => '2026-01-05', 'finish' => '2026-10-15', 'cargo' => 'Técnico'],
        ]]]),
    ]);

    $this->actingAs(superAdmin());
});

test('el buscador pide a Mamoré solo a quien tuvo contrato en las fechas y muestra ese contrato', function (): void {
    $this->getJson(route('licencias.funcionarios', ['q' => 'perez', 'desde' => '2026-10-08', 'hasta' => '2026-10-08']))
        ->assertOk()
        ->assertJsonPath('0.id', '111')
        ->assertJsonPath('0.contrato', 'Contrato del 05/01/2026 al 15/10/2026');

    Http::assertSent(fn (Request $pedido): bool => str_contains($pedido->url(), '/people?')
        && ($pedido->data()['contrato'] ?? null) === 'con'
        && ($pedido->data()['desde'] ?? null) === '2026-10-08'
        && ($pedido->data()['hasta'] ?? null) === '2026-10-08');
});

test('sin fechas el buscador no filtra por contrato', function (): void {
    $this->getJson(route('licencias.funcionarios', ['q' => 'perez']))->assertOk();

    Http::assertSent(fn (Request $pedido): bool => str_contains($pedido->url(), '/people?')
        && ! isset($pedido->data()['contrato'])
        && ! isset($pedido->data()['desde']));
});

test('al cambiar las fechas se revisa el contrato de los ya elegidos', function (): void {
    $this->getJson(route('licencias.contratos', ['cis' => ['111', '222'], 'desde' => '2026-10-08', 'hasta' => '2026-10-08']))
        ->assertOk()
        ->assertJson([
            'configurado' => true,
            'contratos' => ['111' => 'Contrato del 05/01/2026 al 15/10/2026', '222' => null],
        ]);
});

test('no anota a quien no tuvo contrato en las fechas', function (): void {
    $this->post(route('licencias.store'), [
        'modo' => 'varios', 'cis' => ['111', '222'],
        'desde' => '2026-10-08', 'hasta' => '2026-10-08',
        'tipo' => Licencia::TIPO_INSTITUCIONAL, 'tCompleto' => 1, 'goceHaberes' => 1, 'motivo' => 'Capacitación',
    ])->assertSessionHas('error', 'Sin contrato entre el 08/10/2026 y el 08/10/2026: CI 222. Quitalos o cambiá las fechas.');

    expect(Licencia::count())->toBe(0);
});

test('elegir un funcionario conserva las fechas elegidas', function (): void {
    $this->get(route('licencias.create', ['ci' => '111', 'desde' => '2026-10-08', 'hasta' => '2026-10-09']))
        ->assertOk()
        ->assertSee('value="2026-10-08"', false)
        ->assertSee('value="2026-10-09"', false)
        ->assertSeeInOrder(['¿Cuándo?', '¿A quién se licencia?']);
});

test('en un rango largo solo se licencian los días que cubre el contrato', function (): void {
    // Lunes a viernes de 08:00 a 16:00 todo el año. 111 tuvo contrato hasta el 15/10.
    foreach (range(2, 6) as $dia) {
        AsignacionHorario::factory()->create([
            'ci' => '111', 'horario_id' => horario($dia)->id,
            'desde' => '2026-01-01', 'hasta' => '2026-12-31',
        ]);
    }

    $this->post(route('licencias.store'), [
        'modo' => 'uno', 'ci' => '111',
        'desde' => '2026-10-01', 'hasta' => '2026-10-31',
        'tipo' => Licencia::TIPO_INSTITUCIONAL, 'tCompleto' => 1, 'goceHaberes' => 1, 'motivo' => 'Comisión',
    ])->assertSessionHas('estado', fn (string $mensaje): bool => str_contains($mensaje, '11 licencia(s) anotada(s)')
        && str_contains($mensaje, '11 día(s) fuera del contrato no se anotaron'));

    // Del 1 al 15 de octubre hay 11 días hábiles; del 16 al 31, otros 11 que quedan afuera.
    expect(Licencia::where('ci', '111')->count())->toBe(11)
        ->and(Licencia::where('ci', '111')->max('fecha'))->toStartWith('2026-10-15');
});
