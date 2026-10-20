<?php

use App\Models\AsignacionHorario;
use App\Models\Licencia;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/*
| Alcance «Todos los funcionarios»: el personal con contrato de toda la
| Gobernación, solo por horas. Se licencia a quien tenga turno ese día, sin
| importar a qué hora: el reporte procesado decide después a quién le afecta.
|
| El 22/10/2026 es jueves (5 en la convención del SIA). 111 trabaja de 08 a 16,
| 333 de 07 a 14 y 444 no tiene turno.
*/
beforeEach(function (): void {
    Carbon::setTestNow('2026-10-20');
    config(['services.mamore.url' => 'https://mamore.test/api', 'services.mamore.token' => 'token']);

    $contrato = [['id' => 1, 'status' => 'firmado', 'start' => '2026-01-05', 'finish' => '2026-12-31']];

    Http::fake([
        'mamore.test/api/people/contracts*' => Http::response(['data' => [
            '111' => $contrato, '333' => $contrato, '444' => $contrato,
        ]]),
        'mamore.test/api/people*' => Http::response(['data' => [
            ['ci' => '111', 'full_name' => 'JUAN PEREZ', 'gender' => 'masculino', 'contrato' => ['status' => 'firmado', 'start' => '2026-01-05', 'finish' => '2026-12-31', 'cargo' => 'Técnico']],
            ['ci' => '333', 'full_name' => 'ANA ROJAS', 'gender' => 'femenino', 'contrato' => ['status' => 'firmado', 'start' => '2026-01-05', 'finish' => '2026-12-31', 'cargo' => 'Analista']],
            ['ci' => '444', 'full_name' => 'LUIS VACA', 'gender' => 'masculino', 'contrato' => ['status' => 'firmado', 'start' => '2026-01-05', 'finish' => '2026-12-31', 'cargo' => 'Chofer']],
        ]]),
    ]);

    AsignacionHorario::factory()->create([
        'ci' => '111', 'horario_id' => horario(5, '08:00', '16:00')->id,
        'desde' => '2026-01-01', 'hasta' => '2026-12-31',
    ]);
    AsignacionHorario::factory()->create([
        'ci' => '333', 'horario_id' => horario(5, '07:00', '14:00')->id,
        'desde' => '2026-01-01', 'hasta' => '2026-12-31',
    ]);

    $this->actingAs(superAdmin());
});

/**
 * @param  array<string, mixed>  $cambios
 * @return array<string, mixed>
 */
function licenciaParaTodos(array $cambios = []): array
{
    return $cambios + [
        'modo' => 'todos', 'cisTodos' => '111,333,444',
        'desde' => '2026-10-22', 'hasta' => '2026-10-22',
        'tipo' => Licencia::TIPO_INSTITUCIONAL, 'tCompleto' => 0, 'goceHaberes' => 1,
        'lEntra' => '15:00', 'lSale' => '16:00', 'motivo' => 'Acto cívico',
    ];
}

test('la lista de todos trae el personal con contrato sin acotar por dirección y marca a quien no tiene turno', function (): void {
    $this->getJson(route('licencias.todos.funcionarios', ['desde' => '2026-10-22', 'hasta' => '2026-10-22']))
        ->assertOk()
        ->assertJsonCount(3, 'funcionarios')
        ->assertJsonPath('funcionarios.0.sinTurno', false)
        ->assertJsonPath('funcionarios.1.sinTurno', false)
        ->assertJsonPath('funcionarios.2.ci', '444')
        ->assertJsonPath('funcionarios.2.sinTurno', true);

    Http::assertSent(fn (Request $pedido): bool => str_contains($pedido->url(), '/people?')
        && ($pedido->data()['contrato'] ?? null) === 'con'
        && ! isset($pedido->data()['direccion']));
});

test('se registra la licencia por horas a todos los que tienen turno, sin importar a qué hora sea', function (): void {
    $this->post(route('licencias.store'), licenciaParaTodos())
        ->assertSessionHas('estado', fn (string $mensaje): bool => str_contains($mensaje, '2 licencia(s) anotada(s) para 2 funcionario(s)'));

    $licencias = Licencia::orderBy('ci')->get();

    // 333 sale a las 14:00: igual se le registra la de 15 a 16.
    expect($licencias->pluck('ci')->all())->toBe(['111', '333'])
        ->and($licencias->every(fn (Licencia $licencia): bool => ! $licencia->tCompleto
            && $licencia->lEntra->format('H:i') === '15:00'
            && $licencia->lSale->format('H:i') === '16:00'
            && $licencia->tipo === Licencia::TIPO_INSTITUCIONAL
            && $licencia->estado === Licencia::PENDIENTE))->toBeTrue();
});

test('solo se licencia a los marcados en la lista', function (): void {
    $this->post(route('licencias.store'), licenciaParaTodos(['cisTodos' => '333']));

    expect(Licencia::pluck('ci')->all())->toBe(['333']);
});

test('un carnet marcado que no está en el personal con contrato no se licencia', function (): void {
    $this->post(route('licencias.store'), licenciaParaTodos(['cisTodos' => '999']))
        ->assertSessionHas('error', 'Ninguno de los funcionarios elegidos tiene contrato dentro del rango.');

    expect(Licencia::count())->toBe(0);
});

test('para todos no se permite horario completo', function (): void {
    $this->post(route('licencias.store'), licenciaParaTodos(['tCompleto' => 1, 'lEntra' => null, 'lSale' => null]))
        ->assertSessionHasErrors(['tCompleto' => 'Para todo el personal la licencia es por horas. Si es el día completo, cargalo en «Días excepcionales».']);

    expect(Licencia::count())->toBe(0);
});

test('para todos no se permite el permiso personal', function (): void {
    $this->post(route('licencias.store'), licenciaParaTodos(['tipo' => Licencia::TIPO_PERSONAL]))
        ->assertSessionHasErrors('tipo');

    expect(Licencia::count())->toBe(0);
});

test('la pantalla ofrece la opción «Todos los funcionarios»', function (): void {
    $this->get(route('licencias.create'))
        ->assertOk()
        ->assertSee('Todos los funcionarios')
        ->assertSee(route('licencias.todos.funcionarios'), false)
        ->assertSee('name="cisTodos"', false);
});

test('la lista muestra el turno de cada uno esos días y su género', function (): void {
    $this->getJson(route('licencias.todos.funcionarios', ['desde' => '2026-10-22', 'hasta' => '2026-10-22']))
        ->assertOk()
        ->assertJsonPath('funcionarios.0.turno', '08:00–16:00')
        ->assertJsonPath('funcionarios.0.genero', 'masculino')
        ->assertJsonPath('funcionarios.1.turno', '07:00–14:00')
        ->assertJsonPath('funcionarios.1.genero', 'femenino')
        ->assertJsonPath('funcionarios.2.turno', '');
});

test('la lista ofrece acotar por género', function (): void {
    $this->get(route('licencias.create'))
        ->assertOk()
        ->assertSee('x-model="filtroGenero"', false)
        ->assertSee('<option value="femenino">Femenino</option>', false)
        ->assertSee('<option value="masculino">Masculino</option>', false);
});
