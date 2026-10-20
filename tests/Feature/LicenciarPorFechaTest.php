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

test('con contrato y turno asignado la licencia se registra pendiente', function (): void {
    // 08/10/2026 es jueves (5 en la convención del SIA).
    AsignacionHorario::factory()->create([
        'ci' => '111', 'horario_id' => horario(5)->id,
        'desde' => '2026-01-01', 'hasta' => '2026-12-31',
    ]);

    $this->post(route('licencias.store'), [
        'modo' => 'uno', 'ci' => '111',
        'desde' => '2026-10-08', 'hasta' => '2026-10-08',
        'tipo' => Licencia::TIPO_INSTITUCIONAL, 'tCompleto' => 1, 'goceHaberes' => 1, 'motivo' => 'Comisión',
    ])->assertSessionHas('estado', fn (string $mensaje): bool => str_contains($mensaje, '1 licencia(s) anotada(s)'));

    $licencia = Licencia::sole();

    expect($licencia->ci)->toBe('111')
        ->and($licencia->fecha->toDateString())->toBe('2026-10-08')
        ->and($licencia->estado)->toBe(Licencia::PENDIENTE);
});

test('con contrato pero sin turno asignado no se registra nada', function (): void {
    $this->post(route('licencias.store'), [
        'modo' => 'uno', 'ci' => '111',
        'desde' => '2026-10-08', 'hasta' => '2026-10-08',
        'tipo' => Licencia::TIPO_INSTITUCIONAL, 'tCompleto' => 1, 'goceHaberes' => 1, 'motivo' => 'Comisión',
    ])->assertSessionHas('error', 'El funcionario no tiene ningún horario asignado dentro de ese rango de fechas.');

    expect(Licencia::count())->toBe(0);
});

test('con turno solo algunos días se registran esos y se avisa cuáles quedaron afuera', function (): void {
    // Turno solo los lunes (2); el rango va del lunes 05/10 al miércoles 07/10.
    AsignacionHorario::factory()->create([
        'ci' => '111', 'horario_id' => horario(2)->id,
        'desde' => '2026-01-01', 'hasta' => '2026-12-31',
    ]);

    $this->post(route('licencias.store'), [
        'modo' => 'uno', 'ci' => '111',
        'desde' => '2026-10-05', 'hasta' => '2026-10-07',
        'tipo' => Licencia::TIPO_INSTITUCIONAL, 'tCompleto' => 1, 'goceHaberes' => 1, 'motivo' => 'Comisión',
    ])->assertSessionHas('estado', fn (string $mensaje): bool => str_contains($mensaje, '1 licencia(s) anotada(s)')
        && str_contains($mensaje, 'sin horario asignado el')
        && str_contains($mensaje, '06/10')
        && str_contains($mensaje, '07/10'));

    expect(Licencia::pluck('fecha')->map->toDateString()->all())->toBe(['2026-10-05']);
});

test('con turno pero fuera del contrato no se registra nada', function (): void {
    // 111 tuvo contrato hasta el 15/10: el lunes 19/10 ya no.
    AsignacionHorario::factory()->create([
        'ci' => '111', 'horario_id' => horario(2)->id,
        'desde' => '2026-01-01', 'hasta' => '2026-12-31',
    ]);

    $this->post(route('licencias.store'), [
        'modo' => 'uno', 'ci' => '111',
        'desde' => '2026-10-19', 'hasta' => '2026-10-19',
        'tipo' => Licencia::TIPO_INSTITUCIONAL, 'tCompleto' => 1, 'goceHaberes' => 1, 'motivo' => 'Comisión',
    ])->assertSessionHas('error', fn (string $mensaje): bool => str_contains($mensaje, 'No se anotó ninguna licencia')
        && str_contains($mensaje, '1 día(s) fuera del contrato no se anotaron'));

    expect(Licencia::count())->toBe(0);
});

test('la lista de la dirección marca a quien no tiene turno en las fechas', function (): void {
    $this->getJson(route('licencias.direccion.funcionarios', ['direccion' => 5, 'desde' => '2026-10-08', 'hasta' => '2026-10-08']))
        ->assertOk()
        ->assertJsonPath('funcionarios.0.ci', '111')
        ->assertJsonPath('funcionarios.0.sinTurno', true);
});

test('la lista de la dirección no marca a quien tiene turno en las fechas', function (): void {
    // 08/10/2026 es jueves (5 en la convención del SIA).
    AsignacionHorario::factory()->create([
        'ci' => '111', 'horario_id' => horario(5)->id,
        'desde' => '2026-01-01', 'hasta' => '2026-12-31',
    ]);

    $this->getJson(route('licencias.direccion.funcionarios', ['direccion' => 5, 'desde' => '2026-10-08', 'hasta' => '2026-10-08']))
        ->assertOk()
        ->assertJsonPath('funcionarios.0.sinTurno', false);
});

test('un turno de otro día de la semana no cuenta como turno en las fechas', function (): void {
    // Turno solo los lunes (2); el 08/10/2026 es jueves.
    AsignacionHorario::factory()->create([
        'ci' => '111', 'horario_id' => horario(2)->id,
        'desde' => '2026-01-01', 'hasta' => '2026-12-31',
    ]);

    $this->getJson(route('licencias.direccion.funcionarios', ['direccion' => 5, 'desde' => '2026-10-08', 'hasta' => '2026-10-08']))
        ->assertOk()
        ->assertJsonPath('funcionarios.0.sinTurno', true);
});

test('en la lista de la dirección no se puede marcar a quien no tiene turno', function (): void {
    $this->get(route('licencias.create'))
        ->assertOk()
        ->assertSee('this.funcionarios.filter((p) => ! p.sinTurno)', false)
        ->assertSee(":disabled=\"modo !== 'direccion' || persona.sinTurno\"", false);
});
