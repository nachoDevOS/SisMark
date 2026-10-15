<?php

use App\Models\AsignacionTurno;
use App\Services\AsignadorTurnos;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/*
| Asignar un turno a mano con Mamoré configurado: se elige un contrato firmado
| vigente de la persona y el turno queda atado a él.
|
| 111 tiene dos contratos firmados (uno vencido, uno vigente hasta el 30/10) y uno
| concluido; ninguno de los dos primeros debería poder elegirse salvo el vigente.
*/
beforeEach(function (): void {
    Carbon::setTestNow('2026-10-15');
    config(['services.mamore.url' => 'https://mamore.test/api', 'services.mamore.token' => 'token']);

    $this->turno = turnoLunesAViernes();

    Http::fake([
        'mamore.test/api/people/ci/111/contracts*' => Http::response(['data' => [
            ['id' => 10, 'code' => 'C-10', 'status' => 'firmado', 'start' => '2025-01-01', 'finish' => '2025-12-31', 'job' => ['name' => 'Viejo']],
            ['id' => 20, 'code' => 'C-20', 'status' => 'firmado', 'start' => '2026-07-01', 'finish' => '2026-10-30',
                'job' => ['name' => 'Técnico'], 'direccion_administrativa' => ['sigla' => 'DST']],
            ['id' => 30, 'code' => 'C-30', 'status' => 'concluido', 'start' => '2026-01-01', 'finish' => '2026-06-30'],
        ]]),
        'mamore.test/api/people/ci/111' => Http::response(['data' => ['ci' => '111', 'full_name' => 'JUAN PEREZ']]),
        'mamore.test/api/people/ci/222/contracts*' => Http::response(['data' => []]),
        'mamore.test/api/people/ci/222' => Http::response(['data' => ['ci' => '222', 'full_name' => 'ANA ROCA']]),
        // 333: Mamoré se cae.
        'mamore.test/api/people/ci/333*' => Http::response([], 500),
    ]);
});

/**
 * Asigna por la pantalla con el contrato vigente de 111.
 *
 * @param  array<string, mixed>  $datos
 */
function asignarConContrato(array $datos = [])
{
    return test()->actingAs(superAdmin())->post(route('turnos-asignados.store'), $datos + [
        'ci' => '111',
        'turno_id' => test()->turno->id,
        'contrato_id' => 20,
        'desde' => '2026-07-01',
        'hasta' => '2026-10-30',
    ]);
}

test('la ficha muestra la persona, solo los contratos firmados vigentes y sus turnos', function (): void {
    app(AsignadorTurnos::class)->asignar('111', $this->turno, Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'));

    $this->actingAs(superAdmin())
        ->getJson(route('turnos-asignados.ficha', ['ci' => '111']))
        ->assertOk()
        ->assertJsonPath('persona.nombre', 'JUAN PEREZ')
        ->assertJsonPath('mamore', true)
        ->assertJsonCount(1, 'contratos')
        ->assertJsonPath('contratos.0.id', 20)
        ->assertJsonPath('contratos.0.cargo', 'Técnico')
        ->assertJsonPath('contratos.0.direccion', 'DST')
        ->assertJsonPath('contratos.0.desde', '2026-07-01')
        ->assertJsonPath('contratos.0.hastaTexto', '30/10/2026')
        ->assertJsonPath('contratos.0.empezo', true)
        ->assertJsonPath('contratos.0.turno', null)
        ->assertJsonPath('turnos.0.nombre', $this->turno->nombre)
        ->assertJsonPath('turnos.0.vigente', false)
        ->assertJsonPath('turnos.0.contrato', false);
});

test('asigna el turno atado al contrato elegido', function (): void {
    asignarConContrato()->assertSessionHasNoErrors()->assertRedirect();

    $asignacion = AsignacionTurno::query()->sole();

    expect($asignacion->contrato_id)->toBe(20)
        ->and($asignacion->horariosAsignados->pluck('contrato_id')->unique()->all())->toBe([20]);

    // Ya atado, la ficha lo muestra en el contrato y no deja elegirlo de nuevo.
    $this->getJson(route('turnos-asignados.ficha', ['ci' => '111']))
        ->assertJsonPath('contratos.0.turno.nombre', $this->turno->nombre);
});

test('rechaza lo que no cabe en el contrato', function (array $datos, string $campo, string $mensaje): void {
    asignarConContrato($datos)->assertSessionHasErrors([$campo => $mensaje]);

    expect(AsignacionTurno::query()->count())->toBe(0);
})->with([
    'sin contrato' => [['contrato_id' => null], 'contrato_id', 'Elegí el contrato al que corresponde el turno.'],
    'un contrato vencido' => [['contrato_id' => 10], 'contrato_id', 'Ese contrato no es un contrato firmado vigente de este funcionario.'],
    'un contrato concluido' => [['contrato_id' => 30], 'contrato_id', 'Ese contrato no es un contrato firmado vigente de este funcionario.'],
    'un contrato de otra persona' => [['contrato_id' => 99], 'contrato_id', 'Ese contrato no es un contrato firmado vigente de este funcionario.'],
    'empieza antes del contrato' => [['desde' => '2026-06-30'], 'desde', 'La fecha de inicio no puede ser anterior al inicio del contrato (01/07/2026).'],
    'termina después del contrato' => [['hasta' => '2026-11-10'], 'hasta', 'La fecha de fin no puede pasar el fin del contrato (30/10/2026).'],
]);

test('un contrato que ya tiene turno no admite otro', function (): void {
    asignarConContrato()->assertSessionHasNoErrors();

    asignarConContrato(['desde' => '2026-10-20'])
        ->assertSessionHasErrors(['contrato_id' => 'Ese contrato ya tiene el turno «'.$this->turno->nombre.'» del 01/07/2026 al 30/10/2026.']);
});

test('sin contrato firmado vigente no se le puede asignar turno', function (): void {
    $this->actingAs(superAdmin())
        ->getJson(route('turnos-asignados.ficha', ['ci' => '222']))
        ->assertOk()
        ->assertJsonPath('mamore', true)
        ->assertJsonCount(0, 'contratos');

    $this->post(route('turnos-asignados.store'), [
        'ci' => '222', 'turno_id' => $this->turno->id, 'desde' => '2026-10-20', 'hasta' => '2026-12-31',
    ])->assertSessionHasErrors(['contrato_id' => 'El funcionario no tiene contrato firmado vigente en Mamoré: no se le puede asignar turno.']);
});

test('si Mamoré no responde no se asigna', function (): void {
    $this->actingAs(superAdmin())
        ->getJson(route('turnos-asignados.ficha', ['ci' => '333']))
        ->assertOk()
        ->assertJsonPath('contratos', [])
        ->assertJsonPath('errorContratos', fn (string $error): bool => str_starts_with($error, 'No se pudieron consultar los contratos en Mamoré'));

    asignarConContrato(['ci' => '333'])->assertSessionHasErrors('contrato_id');
    expect(AsignacionTurno::query()->count())->toBe(0);
});

test('sin Mamoré configurado se asigna sin contrato, como antes', function (): void {
    config(['services.mamore.url' => null]);

    $this->actingAs(superAdmin())
        ->getJson(route('turnos-asignados.ficha', ['ci' => '111']))
        ->assertJsonPath('mamore', false);

    $this->post(route('turnos-asignados.store'), [
        'ci' => '111', 'turno_id' => $this->turno->id, 'desde' => '2026-10-20', 'hasta' => '2026-12-31',
    ])->assertSessionHasNoErrors();

    expect(AsignacionTurno::query()->sole()->contrato_id)->toBeNull();
});

test('las pantallas de asignar cargan el bloque de la ficha', function (): void {
    $this->actingAs(superAdmin())
        ->get(route('turnos-asignados.create', ['ci' => '111']))
        ->assertOk()
        ->assertSee('fichaAsignacion', false)
        ->assertSee('name="contrato_id"', false)
        // Lo que explica la pantalla antes de confirmar.
        ->assertSee('no se le controla la asistencia', false)
        ->assertSee('Un contrato admite un solo turno', false)
        ->assertSee('Se le asignará el turno', false)
        ->assertSee('data-nombre="'.$this->turno->nombre.'"', false);
});
