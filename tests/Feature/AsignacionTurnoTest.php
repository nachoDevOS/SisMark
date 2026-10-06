<?php

use App\Models\AsignacionHorario;
use App\Models\AsignacionTurno;
use App\Services\AsignadorTurnos;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-15');
    $this->turno = turnoLunesAViernes();
});

/**
 * Asigna el turno por la pantalla.
 *
 * @param  array<string, mixed>  $datos
 */
function asignarPorPantalla(array $datos)
{
    return test()->post(route('turnos-asignados.store'), $datos + [
        'ci' => '111',
        'turno_id' => test()->turno->id,
        'desde' => '2026-10-20',
        'hasta' => '2026-12-31',
    ]);
}

test('asignar un turno crea la cabecera y un horario asignado por día', function (): void {
    $this->actingAs(superAdmin());

    asignarPorPantalla([])->assertSessionHasNoErrors()->assertRedirect();

    $asignacion = AsignacionTurno::query()->sole();

    expect($asignacion->ci)->toBe('111')
        ->and($asignacion->desde->toDateString())->toBe('2026-10-20')
        ->and($asignacion->hasta->toDateString())->toBe('2026-12-31')
        ->and($asignacion->horariosAsignados)->toHaveCount(5)
        ->and($asignacion->horariosAsignados->pluck('horario_id')->sort()->values()->all())
        ->toBe($this->turno->horarios->pluck('id')->sort()->values()->all());

    // Las fechas se guardan sin hora.
    expect($asignacion->getRawOriginal('desde'))->toBe('2026-10-20')
        ->and($asignacion->horariosAsignados->first()->getRawOriginal('hasta'))->toBe('2026-12-31');
});

test('la fecha de fin no puede ser anterior a la de inicio', function (): void {
    $this->actingAs(superAdmin());

    asignarPorPantalla(['desde' => '2026-12-01', 'hasta' => '2026-11-01'])->assertSessionHasErrors('hasta');

    expect(AsignacionTurno::query()->count())->toBe(0);
});

test('lo heredado que seguía en pie termina el día anterior al turno', function (): void {
    $heredado = AsignacionHorario::factory()->create([
        'ci' => '111', 'horario_id' => $this->turno->horarios->first()->id,
        'desde' => '2026-01-01', 'hasta' => '2026-12-31',
    ]);

    $this->actingAs(superAdmin());
    asignarPorPantalla([])->assertSessionHasNoErrors();

    expect($heredado->fresh()->hasta->toDateString())->toBe('2026-10-19');
});

test('rechaza lo que no se puede asignar', function (string $escenario, string $texto): void {
    match ($escenario) {
        'solapado' => app(AsignadorTurnos::class)->asignar('111', $this->turno, Carbon::parse('2026-11-01'), Carbon::parse('2027-01-31')),
        'heredado' => AsignacionHorario::factory()->create([
            'ci' => '111', 'horario_id' => $this->turno->horarios->first()->id, 'desde' => '2026-11-05', 'hasta' => '2026-12-31',
        ]),
        'horario eliminado' => $this->turno->horarios->first()->delete(),
    };

    $this->actingAs(superAdmin());

    asignarPorPantalla([])->assertSessionHasErrors('turno_id');

    expect(session('errors')->first('turno_id'))->toContain($texto)
        ->and(AsignacionTurno::query()->where('desde', '2026-10-20')->exists())->toBeFalse();
})->with([
    'otro turno que se pisa' => ['solapado', 'Ya tiene asignado'],
    'heredado que arranca dentro del rango' => ['heredado', 'sistema anterior'],
    'turno con un horario eliminado' => ['horario eliminado', 'horarios eliminados'],
]);

test('otra persona puede tener el mismo turno en las mismas fechas', function (): void {
    $this->actingAs(superAdmin());

    asignarPorPantalla([])->assertSessionHasNoErrors();
    asignarPorPantalla(['ci' => '222'])->assertSessionHasNoErrors();

    expect(AsignacionTurno::query()->count())->toBe(2);
});

test('concluir le pone fecha de fin al turno y a todos sus días', function (): void {
    $asignacion = app(AsignadorTurnos::class)->asignar('111', $this->turno, Carbon::parse('2026-10-20'), Carbon::parse('2026-12-31'));

    $this->actingAs(superAdmin())
        ->patch(route('turnos-asignados.concluir', $asignacion), ['hasta' => '2026-11-30'])
        ->assertSessionHasNoErrors();

    expect($asignacion->fresh()->hasta->toDateString())->toBe('2026-11-30')
        ->and(AsignacionHorario::query()->where('asignacion_turno_id', $asignacion->id)->pluck('hasta')
            ->map->toDateString()->unique()->all())->toBe(['2026-11-30']);
});

test('no se concluye antes de que empiece', function (): void {
    $asignacion = app(AsignadorTurnos::class)->asignar('111', $this->turno, Carbon::parse('2026-10-20'), Carbon::parse('2026-12-31'));

    $this->actingAs(superAdmin())
        ->patch(route('turnos-asignados.concluir', $asignacion), ['hasta' => '2026-10-01'])
        ->assertSessionHasErrors('hasta');
});

test('eliminar da de baja el turno y sus días, con el motivo', function (): void {
    $asignacion = app(AsignadorTurnos::class)->asignar('111', $this->turno, Carbon::parse('2026-10-20'), Carbon::parse('2026-12-31'));

    $this->actingAs(superAdmin())
        ->delete(route('turnos-asignados.destroy', $asignacion), ['deleteObservacion' => 'Cargado por error'])
        ->assertRedirect();

    expect($asignacion->fresh()->trashed())->toBeTrue()
        ->and($asignacion->fresh()->deleteObservacion)->toBe('Cargado por error')
        ->and(AsignacionHorario::query()->where('asignacion_turno_id', $asignacion->id)->count())->toBe(0)
        ->and(AsignacionHorario::onlyTrashed()->where('asignacion_turno_id', $asignacion->id)->count())->toBe(5);
});

test('los días de un turno no se concluyen ni eliminan sueltos, ni siendo super_admin', function (): void {
    $asignacion = app(AsignadorTurnos::class)->asignar('111', $this->turno, Carbon::parse('2026-10-20'), Carbon::parse('2026-12-31'));
    $dia = $asignacion->horariosAsignados()->first();

    $this->actingAs(superAdmin());

    $this->patch(route('horarios-asignados.concluir', $dia), ['hasta' => '2026-11-30'])->assertForbidden();
    $this->delete(route('horarios-asignados.destroy', $dia))->assertForbidden();

    expect($dia->fresh()->hasta->toDateString())->toBe('2026-12-31')
        ->and($dia->fresh()->trashed())->toBeFalse();
});

test('lo heredado del sistema anterior sí se concluye día por día', function (): void {
    $heredado = AsignacionHorario::factory()->create([
        'ci' => '111', 'horario_id' => $this->turno->horarios->first()->id,
        'desde' => '2026-01-01', 'hasta' => '2026-12-31',
    ]);

    $this->actingAs(superAdmin())
        ->patch(route('horarios-asignados.concluir', $heredado), ['hasta' => '2026-10-31'])
        ->assertSessionHasNoErrors();

    expect($heredado->fresh()->hasta->toDateString())->toBe('2026-10-31');
});

test('ya no se asignan horarios sueltos', function (): void {
    expect(Route::has('horarios-asignados.create'))->toBeFalse()
        ->and(Route::has('horarios-asignados.store'))->toBeFalse();
});

test('las pantallas responden y muestran el turno', function (): void {
    app(AsignadorTurnos::class)->asignar('111', $this->turno, Carbon::parse('2026-10-20'), Carbon::parse('2026-12-31'));

    $this->actingAs(superAdmin());

    $this->get(route('turnos-asignados.index'))->assertSuccessful();
    $this->get(route('turnos-asignados.list'))->assertSuccessful()->assertSee($this->turno->nombre);
    $this->get(route('turnos-asignados.create'))->assertSuccessful()->assertSee($this->turno->nombre);
    $this->get(route('horarios-asignados.list'))->assertSuccessful()->assertSee('Turno: '.$this->turno->nombre);
    $this->get(route('funcionarios.horarios.list', ['ci' => '111']))->assertSuccessful()
        ->assertSee($this->turno->nombre)->assertSee('Concluir turno');
});
