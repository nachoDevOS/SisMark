<?php

use App\Models\Actividad;
use App\Models\Licencia;
use App\Models\SistemaExterno;
use App\Services\AsignadorTurnos;
use Illuminate\Support\Carbon;

/*
| Lo que un sistema externo hace por la API queda en la actividad con una frase
| que dice qué pasó: qué turno, qué fechas, antes → después, o que no cambió
| nada. Las consultas llevan frase pero siguen siendo detalle.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-15');

    $this->mamore = SistemaExterno::create(['slug' => 'mamore', 'nombre' => 'Mamoré', 'activo' => true]);
    $this->withToken($this->mamore->createToken('mamore', [
        'horarios:read', 'horarios:write', 'asistencia:read', 'licencias:read', 'licencias:write',
    ])->plainTextToken);
});

/**
 * El evento importante que dejó el pedido (no la fila del pedido en sí).
 */
function eventoDeLaApi(): Actividad
{
    return Actividad::query()->where('importante', true)->latest('id')->firstOrFail();
}

test('asignar un turno dice cuál, a quién, las fechas y el contrato', function (): void {
    turnoLunesAViernes(sugerido: true);

    $this->postJson('/api/v1/funcionarios/111/turnos', ['desde' => '2026-10-20', 'hasta' => '2026-12-31', 'contratoId' => 55])
        ->assertCreated();

    $evento = eventoDeLaApi();

    expect($evento->accion)->toBe(Actividad::CREAR)
        ->and($evento->sistema_externo_id)->toBe($this->mamore->id)
        ->and($evento->ci)->toBe('111')
        ->and($evento->modulo)->toBe('API')
        ->and($evento->descripcion)->toBe('Asignó el turno «L-V 08:00 - 16:00» a CI 111 del 20/10/2026 al 31/12/2026 (contrato 55)');
});

test('extender el contrato dice la vigencia de antes y la de después', function (): void {
    app(AsignadorTurnos::class)->asignar('111', turnoLunesAViernes(), Carbon::parse('2026-10-20'), Carbon::parse('2026-12-31'), 55);

    $this->putJson('/api/v1/funcionarios/111/turnos', ['contratoId' => 55, 'desde' => '2026-10-20', 'hasta' => '2027-03-31'])
        ->assertSuccessful();

    $evento = eventoDeLaApi();

    expect($evento->accion)->toBe(Actividad::EDITAR)
        ->and($evento->descripcion)->toBe('Cambió la vigencia del turno «L-V 08:00 - 16:00» de CI 111: 20/10/2026–31/12/2026 → 20/10/2026–31/03/2027 (contrato 55)')
        ->and($evento->datos['antes'])->toBe('20/10/2026–31/12/2026');
});

test('un aviso con las mismas fechas queda como «sin cambios»', function (): void {
    app(AsignadorTurnos::class)->asignar('111', turnoLunesAViernes(), Carbon::parse('2026-10-20'), Carbon::parse('2026-12-31'), 55);

    $this->putJson('/api/v1/funcionarios/111/turnos', ['contratoId' => 55, 'desde' => '2026-10-20', 'hasta' => '2026-12-31'])
        ->assertSuccessful()
        ->assertJsonPath('actualizados', 0);

    $evento = eventoDeLaApi();

    expect($evento->accion)->toBe(Actividad::SIN_CAMBIOS)
        ->and($evento->descripcion)->toContain('el turno ya tenía esas fechas, sin cambios');
});

test('acortar el contrato dice qué turno terminó antes y cuál se dio de baja', function (): void {
    $turno = turnoLunesAViernes();
    $asignador = app(AsignadorTurnos::class);
    $asignador->asignar('111', $turno, Carbon::parse('2026-01-05'), Carbon::parse('2026-08-31'), 55);
    $asignador->asignar('111', $turno, Carbon::parse('2026-09-01'), Carbon::parse('2026-11-30'), 55);

    $this->putJson('/api/v1/funcionarios/111/turnos', ['contratoId' => 55, 'desde' => '2026-01-05', 'hasta' => '2026-07-31'])
        ->assertSuccessful();

    $evento = eventoDeLaApi();

    expect($evento->accion)->toBe(Actividad::EDITAR)
        ->and($evento->descripcion)->toStartWith('Acortó el contrato 55 de CI 111 al 31/07/2026: ')
        ->and($evento->descripcion)->toContain('05/01/2026–31/08/2026 → 05/01/2026–31/07/2026')
        ->and($evento->descripcion)->toContain('01/09/2026–30/11/2026 dado de baja por quedar fuera');
});

test('anular el contrato dice qué se dio de baja, y repetirlo queda sin cambios', function (): void {
    app(AsignadorTurnos::class)->asignar('111', turnoLunesAViernes(), Carbon::parse('2026-10-20'), Carbon::parse('2026-12-31'), 55);

    $this->deleteJson('/api/v1/funcionarios/111/turnos', ['contratoId' => 55])->assertSuccessful();

    expect(eventoDeLaApi()->accion)->toBe(Actividad::ELIMINAR)
        ->and(eventoDeLaApi()->descripcion)->toBe('Dio de baja el turno «L-V 08:00 - 16:00» del 20/10/2026 al 31/12/2026 de CI 111: contrato 55 anulado');

    $this->deleteJson('/api/v1/funcionarios/111/turnos', ['contratoId' => 55])->assertSuccessful();

    expect(eventoDeLaApi()->accion)->toBe(Actividad::SIN_CAMBIOS)
        ->and(eventoDeLaApi()->descripcion)->toBe('Avisó la anulación del contrato 55 de CI 111: no tenía turno en SisMark, sin cambios');
});

test('lo que SisMark rechaza no se anota como hecho', function (): void {
    $this->postJson('/api/v1/funcionarios/111/turnos', ['desde' => '2026-10-20', 'hasta' => '2026-12-31', 'contratoId' => 55])
        ->assertUnprocessable();

    expect(Actividad::where('importante', true)->exists())->toBeFalse()
        ->and(Actividad::where('ruta', 'api.funcionarios.turnos.store')->value('estado'))->toBe(422);
});

test('pedir y cancelar una licencia dicen para quién, cuándo y que queda pendiente', function (): void {
    app(AsignadorTurnos::class)->asignar('111', turnoLunesAViernes(), Carbon::parse('2026-10-01'), Carbon::parse('2026-12-31'));

    $this->postJson('/api/v1/funcionarios/111/licencias', [
        'desde' => '2026-10-21', 'hasta' => '2026-10-21', 'motivo' => 'Cita médica', 'tCompleto' => true,
    ])->assertCreated();

    $pedida = eventoDeLaApi();

    expect($pedida->accion)->toBe(Actividad::CREAR)
        ->and($pedida->descripcion)->toBe('Pidió licencia para CI 111 el 21/10/2026, horario completo: «Cita médica». Queda pendiente (1 día)');

    $licencia = Licencia::query()->where('ci', '111')->firstOrFail();

    $this->deleteJson("/api/v1/funcionarios/111/licencias/{$licencia->getKey()}")->assertSuccessful();

    expect(eventoDeLaApi()->accion)->toBe(Actividad::ELIMINAR)
        ->and(eventoDeLaApi()->descripcion)->toBe("Canceló la solicitud de licencia #{$licencia->getKey()} de CI 111 del 21/10/2026 (1 día)");
});

test('las consultas de la API llevan frase pero siguen siendo detalle', function (): void {
    $this->getJson('/api/v1/funcionarios/111/horarios')->assertSuccessful();

    $consulta = Actividad::where('ruta', 'api.funcionarios.horarios.index')->sole();

    expect($consulta->importante)->toBeFalse()
        ->and($consulta->accion)->toBe(Actividad::PEDIDO)
        ->and($consulta->descripcion)->toBe('Consultó los horarios de CI 111')
        ->and($consulta->ci)->toBe('111');
});
