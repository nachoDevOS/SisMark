<?php

use App\Models\AsignacionTurno;
use App\Models\Horario;
use App\Models\Turno;
use Database\Seeders\IntegracionMamoreSeeder;
use Illuminate\Support\Facades\Route;

/**
 * Una fila de horario como la manda el formulario de alta del turno.
 *
 * @return array<string, string>
 */
function filaHorario(int $dia, string $entrada = '08:00', string $salida = '16:00'): array
{
    return [
        'dia' => (string) $dia,
        'hEntrada' => $entrada,
        'hTolerancia' => $entrada,
        'eMinima' => $entrada,
        'eMaxima' => $entrada,
        'hSalida' => $salida,
        'sTolerancia' => $salida,
        'sMinima' => $salida,
        'sMaxima' => $salida,
    ];
}

test('crea un turno con sus horarios de la semana, que nacen siendo suyos', function (): void {
    $this->actingAs(superAdmin())
        ->post(route('turnos.store'), [
            'nombre' => 'Administrativo L-V',
            'sugerido' => '1',
            'horarios' => collect([2, 3, 4, 5, 6])->map(fn (int $dia): array => [
                ...filaHorario($dia),
                'hTolerancia' => '08:10',
                'eMinima' => '07:00',
                'eMaxima' => '10:00',
                'sMaxima' => '18:00',
            ])->all(),
        ])
        ->assertRedirect();

    $turno = Turno::query()->with('horarios')->sole();
    $lunes = $turno->horarios->first();

    expect($turno->nombre)->toBe('Administrativo L-V')
        ->and($turno->sugerido)->toBeTrue()
        ->and($turno->horarios)->toHaveCount(5)
        ->and($turno->dias_cubiertos)->toBe('Lun, Mar, Mié, Jue, Vie')
        ->and($turno->horas_semanales)->toBe(40.0)
        ->and(Horario::query()->whereNull('turno_id')->count())->toBe(0)
        ->and($lunes->nombreHorario)->toBe('LUN: 08:00 - 16:00')
        ->and($lunes->hTolerancia->format('H:i'))->toBe('08:10')
        ->and($lunes->eMinima->format('H:i'))->toBe('07:00')
        ->and($lunes->eMaxima->format('H:i'))->toBe('10:00')
        ->and($lunes->sMaxima->format('H:i'))->toBe('18:00')
        ->and($lunes->hEntrada->format('Y-m-d'))->toBe('1899-12-30')
        ->and($turno->horarios->pluck('idHorario')->filter())->toBeEmpty();
});

test('acepta mañana y tarde el mismo día', function (): void {
    $this->actingAs(superAdmin())
        ->post(route('turnos.store'), [
            'nombre' => 'Partido',
            'horarios' => [filaHorario(2, '08:00', '12:00'), filaHorario(2, '14:00', '18:00')],
        ])
        ->assertSessionHasNoErrors();

    expect(Turno::query()->sole()->horarios)->toHaveCount(2);
});

test('guarda el nocturno con salida al día siguiente', function (): void {
    $this->actingAs(superAdmin())
        ->post(route('turnos.store'), [
            'nombre' => 'Sereno',
            'horarios' => [filaHorario(2, '22:00', '06:00')],
        ])
        ->assertSessionHasNoErrors();

    expect(Turno::query()->sole()->horarios->sole()->siguienteDia)->toBeTrue();
});

test('rechaza horarios que coinciden en el mismo momento', function (array $primero, array $segundo): void {
    $this->actingAs(superAdmin())
        ->post(route('turnos.store'), [
            'nombre' => 'Con choque',
            'horarios' => [filaHorario(...$primero), filaHorario(...$segundo)],
        ])
        ->assertSessionHasErrors(['horarios' => 'Los horarios #1 y #2 coinciden en el mismo momento: un turno no puede tener dos horarios a la misma hora.']);

    expect(Turno::query()->count())->toBe(0)
        ->and(Horario::query()->count())->toBe(0);
})->with([
    'mismo día superpuestos' => [[2, '08:00', '16:00'], [2, '15:00', '18:00']],
    'el mismo horario dos veces' => [[2, '08:00', '16:00'], [2, '08:00', '16:00']],
    'nocturno del sábado contra el domingo a la mañana' => [[7, '22:00', '06:00'], [1, '05:00', '10:00']],
    'nocturno del lunes contra el martes a la mañana' => [[2, '22:00', '06:00'], [3, '05:00', '12:00']],
]);

test('valida nombre único, al menos un horario y cada campo del horario', function (array $datos, string $campo): void {
    Turno::factory()->create(['nombre' => 'Ya existe']);

    $this->actingAs(superAdmin())
        ->post(route('turnos.store'), $datos)
        ->assertSessionHasErrors($campo);

    expect(Horario::query()->count())->toBe(0);
})->with([
    'sin horarios' => fn () => [['nombre' => 'Nuevo', 'horarios' => []], 'horarios'],
    'nombre repetido' => fn () => [['nombre' => 'Ya existe', 'horarios' => [filaHorario(2)]], 'nombre'],
    'día inválido' => fn () => [['nombre' => 'Nuevo', 'horarios' => [filaHorario(8)]], 'horarios.0.dia'],
    'hora mal escrita' => fn () => [['nombre' => 'Nuevo', 'horarios' => [[...filaHorario(2), 'hSalida' => '25:00']]], 'horarios.0.hSalida'],
    'falta una ventana' => fn () => [['nombre' => 'Nuevo', 'horarios' => [array_diff_key(filaHorario(2), ['eMaxima' => 1])]], 'horarios.0.eMaxima'],
]);

test('el error de un campo dice de qué horario es', function (): void {
    $this->actingAs(superAdmin())
        ->post(route('turnos.store'), [
            'nombre' => 'Nuevo',
            'horarios' => [filaHorario(2), [...filaHorario(3), 'hEntrada' => '']],
        ])
        ->assertSessionHasErrors(['horarios.1.hEntrada' => 'El campo hora de entrada del horario #2 es obligatorio.']);
});

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
        ->post(route('turnos.store'), ['nombre' => 'X', 'horarios' => [filaHorario(2)]])
        ->assertForbidden();
});

test('el seeder de desarrollo de Mamoré crea el turno sugerido con copias del horario general', function (): void {
    $delSia = collect([2, 3, 4, 5, 6])->map(fn (int $dia): Horario => horario($dia));

    $this->seed(IntegracionMamoreSeeder::class);

    $turno = Turno::query()->sugeridos()->with('horarios')->sole();

    expect($turno->horarios)->toHaveCount(5)
        ->and($turno->dias_cubiertos)->toBe('Lun, Mar, Mié, Jue, Vie')
        ->and($turno->horarios->pluck('id')->intersect($delSia->pluck('id')))->toBeEmpty()
        ->and($turno->horarios->pluck('idHorario')->filter())->toBeEmpty()
        ->and(Horario::query()->whereNull('turno_id')->count())->toBe(5);

    // Correrlo de nuevo no crea otro.
    $this->seed(IntegracionMamoreSeeder::class);
    expect(Turno::query()->count())->toBe(1);
});

test('el alta guía en tres pasos y, si la validación rebota, devuelve los horarios cargados', function (): void {
    $this->actingAs(superAdmin())
        ->get(route('turnos.create'))
        ->assertSuccessful()
        ->assertSeeInOrder(['¿Cómo se llama el turno?', '¿Qué días y en qué horario trabaja?', 'Revisá cómo queda la semana']);

    $this->actingAs(superAdmin())
        ->from(route('turnos.create'))
        ->post(route('turnos.store'), ['nombre' => '', 'horarios' => [filaHorario(2)]])
        ->assertSessionHasErrors('nombre');

    $this->get(route('turnos.create'))
        // @js escapa las comillas del JSON como ".
        ->assertSee('hEntrada\u0022:\u002208:00', false);
});

test('las horas de cada horario se calculan de la entrada a la salida y no se cargan a mano', function (): void {
    $this->actingAs(superAdmin())
        ->post(route('turnos.store'), [
            'nombre' => 'Horas calculadas',
            'horarios' => [
                filaHorario(2),
                filaHorario(3, '07:00', '12:10'),
                filaHorario(4, '22:00', '06:00'),
            ],
        ])
        ->assertSessionHasNoErrors();

    expect(Horario::query()->orderBy('dia')->pluck('hTrabajadas')->map(fn ($horas): float => (float) $horas)->all())
        ->toBe([8.0, 5.17, 8.0]);

    // Un valor puesto a mano no se acepta: lo calcula el sistema.
    $this->actingAs(superAdmin())
        ->post(route('turnos.store'), ['nombre' => 'A mano', 'horarios' => [[...filaHorario(2), 'hTrabajadas' => '5']]])
        ->assertSessionHasErrors('horarios.0');
});

test('la salida al día siguiente se deduce de las horas y no se marca a mano', function (): void {
    $this->actingAs(superAdmin())
        ->post(route('turnos.store'), [
            'nombre' => 'Diurno y nocturno',
            'horarios' => [filaHorario(2), filaHorario(3, '22:00', '06:00'), filaHorario(5, '22:00', '06:00')],
        ])
        ->assertSessionHasNoErrors();

    // Martes 22:00 sale el miércoles 06:00 y no choca con el jueves 22:00.
    expect(Horario::query()->orderBy('dia')->pluck('siguienteDia')->map(fn ($valor): bool => (bool) $valor)->all())
        ->toBe([false, true, true]);

    // Marcarlo en un 08:00 → 16:00 lo volvía una jornada de 32 horas: ya no se acepta.
    $this->actingAs(superAdmin())
        ->post(route('turnos.store'), ['nombre' => 'A mano', 'horarios' => [[...filaHorario(2), 'siguienteDia' => '1']]])
        ->assertSessionHasErrors('horarios.0');
});
