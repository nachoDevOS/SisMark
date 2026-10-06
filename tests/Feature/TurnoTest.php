<?php

use App\Models\AsignacionTurno;
use App\Models\Turno;
use Database\Seeders\IntegracionMamoreSeeder;
use Illuminate\Support\Facades\Route;

test('crea un turno con sus horarios de la semana', function (): void {
    $horarios = collect([2, 3, 4, 5, 6])->map(fn (int $dia) => horario($dia));

    $this->actingAs(superAdmin())
        ->post(route('turnos.store'), [
            'nombre' => 'Administrativo L-V',
            'sugerido' => '1',
            'horarioIds' => $horarios->pluck('id')->all(),
        ])
        ->assertRedirect();

    $turno = Turno::query()->with('horarios')->sole();

    expect($turno->nombre)->toBe('Administrativo L-V')
        ->and($turno->sugerido)->toBeTrue()
        ->and($turno->horarios)->toHaveCount(5)
        ->and($turno->dias_cubiertos)->toBe('Lun, Mar, Mié, Jue, Vie')
        ->and($turno->horas_semanales)->toBe(40.0);
});

test('acepta mañana y tarde el mismo día', function (): void {
    $this->actingAs(superAdmin())
        ->post(route('turnos.store'), [
            'nombre' => 'Partido',
            'horarioIds' => [horario(2, '08:00', '12:00')->id, horario(2, '14:00', '18:00')->id],
        ])
        ->assertSessionHasNoErrors();

    expect(Turno::query()->sole()->horarios)->toHaveCount(2);
});

test('rechaza horarios que se pisan', function (array $primero, array $segundo): void {
    $this->actingAs(superAdmin())
        ->post(route('turnos.store'), [
            'nombre' => 'Con choque',
            'horarioIds' => [horario(...$primero)->id, horario(...$segundo)->id],
        ])
        ->assertSessionHasErrors('horarioIds');

    expect(Turno::query()->count())->toBe(0);
})->with([
    'mismo día superpuestos' => [[2, '08:00', '16:00'], [2, '15:00', '18:00']],
    'nocturno del sábado contra el domingo a la mañana' => [[7, '22:00', '06:00', true], [1, '05:00', '10:00']],
    'nocturno del lunes contra el martes a la mañana' => [[2, '22:00', '06:00', true], [3, '05:00', '12:00']],
]);

test('valida nombre único, al menos un horario y sin repetidos', function (array $datos, string $campo): void {
    Turno::factory()->create(['nombre' => 'Ya existe']);

    $this->actingAs(superAdmin())
        ->post(route('turnos.store'), $datos)
        ->assertSessionHasErrors($campo);
})->with([
    'sin horarios' => fn () => [['nombre' => 'Nuevo', 'horarioIds' => []], 'horarioIds'],
    'nombre repetido' => fn () => [['nombre' => 'Ya existe', 'horarioIds' => [horario(2)->id]], 'nombre'],
    'horario repetido' => fn () => [['nombre' => 'Nuevo', 'horarioIds' => [$id = horario(2)->id, $id]], 'horarioIds.0'],
]);

test('un turno no se edita: no hay rutas de edición', function (): void {
    expect(Route::has('turnos.edit'))->toBeFalse()
        ->and(Route::has('turnos.update'))->toBeFalse();
});

test('solo se cambia la marca de sugerido', function (): void {
    $turno = turnoLunesAViernes();

    $this->actingAs(superAdmin())->patch(route('turnos.sugerido', $turno))->assertRedirect();
    expect($turno->fresh()->sugerido)->toBeTrue();

    $this->patch(route('turnos.sugerido', $turno))->assertRedirect();
    expect($turno->fresh()->sugerido)->toBeFalse()
        ->and($turno->fresh()->horarios)->toHaveCount(5);
});

test('elimina con baja lógica y conserva sus horarios', function (): void {
    $turno = turnoLunesAViernes();

    $this->actingAs(superAdmin())
        ->delete(route('turnos.destroy', $turno), ['deleteObservacion' => 'Cargado por error'])
        ->assertRedirect();

    $eliminado = Turno::withTrashed()->find($turno->id);

    expect($eliminado->trashed())->toBeTrue()
        ->and($eliminado->deleteObservacion)->toBe('Cargado por error')
        ->and($eliminado->horarios)->toHaveCount(5);
});

test('no elimina un turno con asignaciones vigentes o futuras', function (string $hasta): void {
    $turno = turnoLunesAViernes();
    AsignacionTurno::factory()->create(['turno_id' => $turno->id, 'desde' => '2026-01-01', 'hasta' => $hasta]);

    $this->actingAs(superAdmin())
        ->delete(route('turnos.destroy', $turno))
        ->assertSessionHas('error');

    expect($turno->fresh()->trashed())->toBeFalse();
})->with([
    'vigente' => fn () => today()->addMonth()->toDateString(),
    'que vence hoy' => fn () => today()->toDateString(),
]);

test('sí elimina un turno cuyas asignaciones ya vencieron', function (): void {
    $turno = turnoLunesAViernes();
    AsignacionTurno::factory()->create(['turno_id' => $turno->id, 'desde' => '2025-01-01', 'hasta' => today()->subDay()]);

    $this->actingAs(superAdmin())->delete(route('turnos.destroy', $turno));

    expect($turno->fresh()->trashed())->toBeTrue();
});

test('las pantallas de turnos responden', function (): void {
    $turno = turnoLunesAViernes();

    $this->actingAs(superAdmin());

    $this->get(route('turnos.index'))->assertSuccessful();
    $this->get(route('turnos.list'))->assertSuccessful()->assertSee($turno->nombre);
    $this->get(route('turnos.create'))->assertSuccessful();
    $this->get(route('turnos.show', $turno))->assertSuccessful()->assertSee('08:00');
});

test('sin permiso no crea turnos', function (): void {
    $this->actingAs(usuarioCon('ViewAny:Turno'))
        ->post(route('turnos.store'), ['nombre' => 'X', 'horarioIds' => [horario(2)->id]])
        ->assertForbidden();
});

test('el seeder de desarrollo de Mamoré crea el turno sugerido con el horario general', function (): void {
    foreach ([2, 3, 4, 5, 6] as $dia) {
        horario($dia);
    }

    $this->seed(IntegracionMamoreSeeder::class);

    $turno = Turno::query()->sugeridos()->with('horarios')->sole();

    expect($turno->horarios)->toHaveCount(5)
        ->and($turno->dias_cubiertos)->toBe('Lun, Mar, Mié, Jue, Vie');

    // Correrlo de nuevo no crea otro.
    $this->seed(IntegracionMamoreSeeder::class);
    expect(Turno::query()->count())->toBe(1);
});
