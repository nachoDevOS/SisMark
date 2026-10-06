<?php

use App\Models\AsignacionHorario;
use App\Models\Asistencia;
use App\Services\AsignadorTurnos;
use App\Services\ProcesadorAsistencia as P;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-26');

    // Antes del turno: lo heredado del SIA, lunes de 09:00 a 17:00.
    AsignacionHorario::factory()->create([
        'ci' => '111', 'horario_id' => horario(2, '09:00', '17:00')->id,
        'desde' => '2026-01-01', 'hasta' => '2026-12-31',
    ]);

    // Desde el lunes 19: el turno de lunes a viernes de 08:00 a 16:00. Corta lo heredado al 18.
    app(AsignadorTurnos::class)->asignar('111', turnoLunesAViernes(), Carbon::parse('2026-10-19'), Carbon::parse('2026-12-31'));
});

/**
 * @param  list<string>  $horas
 */
function marcar(string $fecha, array $horas): void
{
    foreach ($horas as $hora) {
        Asistencia::factory()->create(['ci' => '111', 'fecha' => $fecha, 'hora' => "{$hora}:00"]);
    }
}

/**
 * Estado de cada día del rango, por fecha. Sin contratos conocidos (Mamoré sin
 * configurar), se procesa todo el rango.
 *
 * @return array<string, string>
 */
function estados(string $desde, string $hasta): array
{
    return app(P::class)
        ->procesarConTramos('111', Carbon::parse($desde), Carbon::parse($hasta), null)
        ->mapWithKeys(fn (array $dia): array => [$dia['fecha']->toDateString() => $dia['estado']])
        ->all();
}

test('con turno, cada día se procesa con los horarios del turno', function (): void {
    marcar('2026-10-19', ['07:55', '16:05']);   // lunes: a horario
    marcar('2026-10-21', ['08:40', '16:05']);   // miércoles: llega tarde
    // martes 20 sin marcas

    $estados = estados('2026-10-19', '2026-10-25');

    expect($estados['2026-10-19'])->toBe(P::CUMPLE)
        ->and($estados['2026-10-20'])->toBe(P::FALTA)
        ->and($estados['2026-10-21'])->toBe(P::ATRASO)
        ->and($estados['2026-10-24'])->toBe(P::NO_LABORABLE)
        ->and($estados['2026-10-25'])->toBe(P::NO_LABORABLE);
});

test('antes del turno se sigue procesando con lo heredado', function (): void {
    // Lunes 12: con el horario viejo (09:00 a 17:00), entrar 09:00 es a horario.
    marcar('2026-10-12', ['08:55', '17:05']);

    expect(estados('2026-10-12', '2026-10-12')['2026-10-12'])->toBe(P::CUMPLE);

    // El martes 13 el horario viejo no tenía nada: no laborable.
    expect(estados('2026-10-13', '2026-10-13')['2026-10-13'])->toBe(P::NO_LABORABLE);
});

test('el lunes del turno no se mezcla con el horario viejo', function (): void {
    // Con el turno, el lunes 19 es de 08:00 a 16:00: el bloque de 09:00 ya no existe.
    marcar('2026-10-19', ['07:55', '16:05']);

    $dia = app(P::class)
        ->procesarConTramos('111', Carbon::parse('2026-10-19'), Carbon::parse('2026-10-19'), null)
        ->first();

    expect($dia['bloques'])->toHaveCount(1)
        ->and($dia['bloques'][0]['horario']->hEntrada->format('H:i'))->toBe('08:00');
});
