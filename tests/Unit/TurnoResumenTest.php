<?php

use App\Models\Horario;
use App\Models\Turno;

/**
 * Un turno armado en memoria, sin base: solo sus horarios.
 *
 * @param  list<array{0: int, 1: float}>  $dias  día de la semana y horas trabajadas
 */
function turnoEnMemoria(array $dias): Turno
{
    $turno = new Turno;
    $turno->setRelation('horarios', collect($dias)->map(
        fn (array $dia): Horario => new Horario(['dia' => (string) $dia[0], 'hTrabajadas' => $dia[1]])
    ));

    return $turno;
}

test('resume los días del turno en orden, sin repetir', function (): void {
    $turno = turnoEnMemoria([[6, 8], [2, 4], [2, 4], [4, 8], [3, 8]]);

    expect($turno->dias_cubiertos)->toBe('Lun, Mar, Mié, Vie');
});

test('suma las horas semanales de todos sus horarios', function (): void {
    $turno = turnoEnMemoria([[2, 4], [2, 4], [3, 8], [7, 4.5]]);

    expect($turno->horas_semanales)->toBe(20.5);
});

test('un turno sin horarios no cubre días', function (): void {
    expect(turnoEnMemoria([])->dias_cubiertos)->toBe('')
        ->and(turnoEnMemoria([])->horas_semanales)->toBe(0.0);
});
