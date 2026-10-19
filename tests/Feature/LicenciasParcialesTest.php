<?php

use App\Models\AsignacionHorario;
use App\Models\Asistencia;
use App\Models\Horario;
use App\Models\Licencia;
use App\Services\ProcesadorAsistencia as P;
use App\Services\RegistroLicencia;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-26');

    // Lunes de 08:00 a 16:00. El 19/10/2026 es lunes.
    $this->horario = horario(2, '08:00', '16:00');

    AsignacionHorario::factory()->create([
        'ci' => '111', 'horario_id' => $this->horario->id,
        'desde' => '2026-01-01', 'hasta' => '2026-12-31',
    ]);
});

function licenciaParcial(Horario $horario, string $entra, string $sale): Licencia
{
    return Licencia::factory()->porHoras($entra, $sale)->create([
        'ci' => '111', 'fecha' => '2026-10-19', 'horario_id' => $horario->id, 'tipo' => Licencia::TIPO_INSTITUCIONAL,
    ]);
}

/**
 * Anota para el 19/10 con el servicio de alta y devuelve el conteo.
 *
 * @return array{creadas: int, existentes: int, fueraDeVigencia: int, sinHorario: int, diasSinHorario: list<string>, funcionarios: int}
 */
function anotarEl19(?string $entra, ?string $sale): array
{
    $registro = app(RegistroLicencia::class);
    $dia = Carbon::parse('2026-10-19');
    // Hasta el martes, que no tiene horario: sqlite guarda la fecha con hora y
    // un rango de un solo día no encontraría lo ya anotado.
    $hasta = Carbon::parse('2026-10-20');

    return $registro->anotar($registro->horariosDelRango(['111'], $dia, $hasta), $dia, $hasta, [
        'tCompleto' => $entra === null,
        'goceHaberes' => true,
        'motivo' => 'Trámite',
        'lEntra' => $entra,
        'lSale' => $sale,
        'usuario' => 'rrhh',
        'usuarioId' => null,
        'tipo' => Licencia::TIPO_INSTITUCIONAL,
    ]);
}

/**
 * El único bloque del lunes 19/10, procesado.
 *
 * @return array<string, mixed>
 */
function bloqueDel19(): array
{
    $dia = app(P::class)
        ->procesarConTramos('111', Carbon::parse('2026-10-19'), Carbon::parse('2026-10-19'), null)
        ->first();

    return $dia['bloques'][0] + ['estadoDia' => $dia['estado']];
}

function marcarEl19(string ...$horas): void
{
    foreach ($horas as $hora) {
        Asistencia::factory()->create(['ci' => '111', 'fecha' => '2026-10-19', 'hora' => "{$hora}:00"]);
    }
}

test('otra licencia parcial del mismo día entra si no se pisa con la que ya había', function (): void {
    licenciaParcial($this->horario, '12:00', '16:00');

    expect(anotarEl19('08:00', '12:00'))->toMatchArray(['creadas' => 1, 'existentes' => 0]);
    expect(Licencia::where('ci', '111')->count())->toBe(2);
});

test('la que se pisa con una ya anotada no entra', function (?string $entra, ?string $sale): void {
    licenciaParcial($this->horario, '12:00', '16:00');

    expect(anotarEl19($entra, $sale))->toMatchArray(['creadas' => 0, 'existentes' => 1]);
})->with([
    'tramo superpuesto' => ['11:00', '13:00'],
    'horario completo' => [null, null],
]);

test('nada entra sobre una licencia de horario completo', function (): void {
    Licencia::factory()->create([
        'ci' => '111', 'fecha' => '2026-10-19', 'horario_id' => $this->horario->id, 'tipo' => Licencia::TIPO_INSTITUCIONAL,
    ]);

    expect(anotarEl19('08:00', '09:00'))->toMatchArray(['creadas' => 0, 'existentes' => 1]);
});

test('dos licencias pegadas cubren el horario entero sin marcas', function (): void {
    licenciaParcial($this->horario, '12:00', '16:00');
    licenciaParcial($this->horario, '08:00', '12:00');

    $bloque = bloqueDel19();

    expect($bloque['estado'])->toBe(P::CUMPLE)
        ->and($bloque['entradaExigida'])->toBeFalse()
        ->and($bloque['salidaExigida'])->toBeFalse()
        ->and($bloque['licenciado'])->toBe(8 * 3600)
        ->and($bloque['computado'])->toBe($bloque['esperado'])
        ->and($bloque['licencias'])->toHaveCount(2);
});

test('el hueco entre dos licencias que tapan los bordes se acredita si marcó adentro', function (): void {
    licenciaParcial($this->horario, '08:00', '09:00');
    licenciaParcial($this->horario, '14:00', '16:00');
    marcarEl19('09:02', '14:01');

    $bloque = bloqueDel19();

    expect($bloque['estado'])->toBe(P::CUMPLE)
        ->and($bloque['licenciado'])->toBe(3 * 3600)
        ->and($bloque['computado'])->toBe($bloque['esperado']);
});

test('sin marcas en el hueco entre las licencias es falta', function (): void {
    licenciaParcial($this->horario, '08:00', '09:00');
    licenciaParcial($this->horario, '14:00', '16:00');

    $bloque = bloqueDel19();

    expect($bloque['estado'])->toBe(P::FALTA)
        ->and($bloque['computado'])->toBe(3 * 3600)
        ->and($bloque['avisos'])->toContain('No marcó en el tiempo que quedaba entre las licencias.');
});

test('con entrada y salida marcadas, dos licencias en el medio descuentan solo su tiempo', function (): void {
    licenciaParcial($this->horario, '10:00', '11:00');
    licenciaParcial($this->horario, '13:00', '14:00');
    marcarEl19('07:58', '16:03');

    $bloque = bloqueDel19();

    expect($bloque['estado'])->toBe(P::CUMPLE)
        ->and($bloque['entradaExigida'])->toBeTrue()
        ->and($bloque['salidaExigida'])->toBeTrue()
        ->and($bloque['licenciado'])->toBe(2 * 3600)
        ->and($bloque['computado'])->toBe($bloque['esperado']);
});

test('el botón de la pantalla Licenciar se traba y dice «Guardando…» mientras envía', function (): void {
    $this->actingAs(superAdmin())
        ->get(route('licencias.create'))
        ->assertOk()
        ->assertSee('x-on:submit="guardando = true"', false)
        ->assertSee('Guardando…');
});

test('lo que se anota desde SisMark nace pendiente y no justifica hasta aprobarse', function (): void {
    $conteo = anotarEl19('08:00', '12:00');

    expect(Licencia::where('ci', '111')->pluck('estado')->all())->toBe([Licencia::PENDIENTE])
        ->and(app(RegistroLicencia::class)->mensaje($conteo))->toContain('pendiente(s) de aprobación')
        ->and(bloqueDel19()['licencias'])->toBe([]);
});
