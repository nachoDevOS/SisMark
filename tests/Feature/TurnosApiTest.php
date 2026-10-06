<?php

use App\Models\AsignacionHorario;
use App\Models\AsignacionTurno;
use App\Models\SistemaExterno;
use App\Services\AsignadorTurnos;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-15');

    // Un token real, como el que usa Mamoré: prueba también la autenticación.
    $this->mamore = SistemaExterno::create(['slug' => 'mamore', 'nombre' => 'Mamoré', 'activo' => true]);
    $this->withToken($this->mamore->createToken('mamore', ['horarios:read', 'horarios:write'])->plainTextToken);

    $this->contrato = ['desde' => '2026-10-20', 'hasta' => '2026-12-31', 'contratoId' => 55];
});

test('lista solo los turnos sugeridos, con sus días', function (): void {
    $sugerido = turnoLunesAViernes(sugerido: true);
    turnoLunesAViernes('14:00', '22:00');

    $this->getJson('/api/v1/turnos/sugeridos')
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.turnoId', $sugerido->id)
        ->assertJsonPath('data.0.nombre', $sugerido->nombre)
        ->assertJsonPath('data.0.horasSemanales', 40)
        ->assertJsonCount(5, 'data.0.dias')
        ->assertJsonPath('data.0.dias.0.hEntrada', '08:00');
});

test('sin el alcance de lectura no ve los turnos', function (): void {
    $this->withToken($this->mamore->createToken('solo-asistencia', ['asistencia:read'])->plainTextToken);

    $this->getJson('/api/v1/turnos/sugeridos')->assertForbidden();
});

test('sin turnoId asigna el único sugerido, atado al contrato', function (): void {
    $turno = turnoLunesAViernes(sugerido: true);

    $this->postJson('/api/v1/funcionarios/111/turnos', $this->contrato)
        ->assertCreated()
        ->assertJsonPath('creados', 1)
        ->assertJsonPath('turno', $turno->nombre);

    $asignacion = AsignacionTurno::query()->sole();

    expect($asignacion->turno_id)->toBe($turno->id)
        ->and($asignacion->contrato_id)->toBe(55)
        ->and($asignacion->horariosAsignados)->toHaveCount(5)
        ->and($asignacion->horariosAsignados->pluck('contrato_id')->unique()->all())->toBe([55]);
});

test('con turnoId asigna ese turno', function (): void {
    turnoLunesAViernes(sugerido: true);
    $otro = turnoLunesAViernes('14:00', '22:00');

    $this->postJson('/api/v1/funcionarios/111/turnos', $this->contrato + ['turnoId' => $otro->id])->assertCreated();

    expect(AsignacionTurno::query()->sole()->turno_id)->toBe($otro->id);
});

test('reintentar no duplica', function (): void {
    turnoLunesAViernes(sugerido: true);

    $this->postJson('/api/v1/funcionarios/111/turnos', $this->contrato)->assertCreated();
    $this->postJson('/api/v1/funcionarios/111/turnos', $this->contrato)
        ->assertSuccessful()
        ->assertJsonPath('omitidos', 1);

    expect(AsignacionTurno::query()->count())->toBe(1)
        ->and(AsignacionHorario::query()->count())->toBe(5);
});

test('sin turno que asignar contesta 422 con el motivo', function (int $sugeridos, string $texto): void {
    foreach (range(1, $sugeridos) as $i) {
        if ($sugeridos > 0) {
            turnoLunesAViernes(sprintf('%02d:00', 6 + $i), '16:00', sugerido: true);
        }
    }

    $this->postJson('/api/v1/funcionarios/111/turnos', $this->contrato)
        ->assertUnprocessable()
        ->assertJsonPath('message', fn (string $mensaje): bool => str_contains($mensaje, $texto));

    expect(AsignacionTurno::query()->count())->toBe(0);
})->with([
    'ninguno sugerido' => [0, 'ningún turno sugerido'],
    'varios sugeridos' => [2, 'varios turnos sugeridos'],
]);

test('un contrato anulado y vuelto a cargar revive su asignación', function (): void {
    turnoLunesAViernes(sugerido: true);

    $this->postJson('/api/v1/funcionarios/111/turnos', $this->contrato)->assertCreated();
    $this->deleteJson('/api/v1/funcionarios/111/turnos', ['contratoId' => 55])->assertSuccessful();
    $this->postJson('/api/v1/funcionarios/111/turnos', $this->contrato)
        ->assertCreated()
        ->assertJsonPath('revividos', 1);

    expect(AsignacionTurno::query()->count())->toBe(1)
        ->and(AsignacionHorario::query()->count())->toBe(5);
});

test('mover la vigencia del contrato mueve el turno y sus días', function (): void {
    turnoLunesAViernes(sugerido: true);
    $this->postJson('/api/v1/funcionarios/111/turnos', $this->contrato)->assertCreated();

    $this->putJson('/api/v1/funcionarios/111/turnos', ['contratoId' => 55, 'desde' => '2026-10-20', 'hasta' => '2027-03-31'])
        ->assertSuccessful()
        ->assertJsonPath('actualizados', 1);

    expect(AsignacionTurno::query()->sole()->hasta->toDateString())->toBe('2027-03-31')
        ->and(AsignacionHorario::query()->pluck('hasta')->map->toDateString()->unique()->all())->toBe(['2027-03-31']);
});

test('mover un contrato sin nada asignado le asigna el sugerido', function (): void {
    turnoLunesAViernes(sugerido: true);

    $this->putJson('/api/v1/funcionarios/111/turnos', ['contratoId' => 55, 'desde' => '2026-10-20', 'hasta' => '2026-12-31'])
        ->assertSuccessful()
        ->assertJsonPath('creados', 1);

    expect(AsignacionTurno::query()->sole()->contrato_id)->toBe(55);
});

test('un contrato anterior a los turnos mueve sus horarios sueltos', function (): void {
    $horario = horario(2);
    $heredado = AsignacionHorario::factory()->create([
        'ci' => '111', 'horario_id' => $horario->id, 'contrato_id' => 55, 'desde' => '2026-01-01', 'hasta' => '2026-06-30',
    ]);

    $this->putJson('/api/v1/funcionarios/111/turnos', ['contratoId' => 55, 'desde' => '2026-01-01', 'hasta' => '2026-12-31'])
        ->assertSuccessful()
        ->assertJsonPath('actualizados', 1);

    expect($heredado->fresh()->hasta->toDateString())->toBe('2026-12-31')
        ->and(AsignacionTurno::query()->count())->toBe(0);
});

test('no mueve el turno de otra cédula', function (): void {
    turnoLunesAViernes(sugerido: true);
    $this->postJson('/api/v1/funcionarios/111/turnos', $this->contrato)->assertCreated();

    $this->putJson('/api/v1/funcionarios/999/turnos', ['contratoId' => 55, 'desde' => '2026-10-20', 'hasta' => '2027-03-31'])
        ->assertUnprocessable();

    expect(AsignacionTurno::query()->sole()->hasta->toDateString())->toBe('2026-12-31');
});

test('anular el contrato da de baja el turno y sus días, con el motivo', function (): void {
    turnoLunesAViernes(sugerido: true);
    $this->postJson('/api/v1/funcionarios/111/turnos', $this->contrato)->assertCreated();

    $this->deleteJson('/api/v1/funcionarios/111/turnos', ['contratoId' => 55, 'observacion' => 'Contrato anulado'])
        ->assertSuccessful()
        ->assertJsonPath('eliminados', 1);

    expect(AsignacionTurno::onlyTrashed()->sole()->deleteObservacion)->toBe('Contrato anulado')
        ->and(AsignacionHorario::query()->count())->toBe(0);

    // Anular de nuevo no es un error.
    $this->deleteJson('/api/v1/funcionarios/111/turnos', ['contratoId' => 55])->assertJsonPath('eliminados', 0);
});

test('el horario del funcionario trae el nombre del turno', function (): void {
    $turno = turnoLunesAViernes();
    app(AsignadorTurnos::class)->asignar('111', $turno, Carbon::parse('2026-10-20'), Carbon::parse('2026-12-31'));

    $this->getJson('/api/v1/funcionarios/111/horarios')
        ->assertSuccessful()
        ->assertJsonPath('data.0.turno', $turno->nombre)
        ->assertJsonPath('data.0.desde', '2026-10-20')
        ->assertJsonCount(5, 'data.0.dias');
});
