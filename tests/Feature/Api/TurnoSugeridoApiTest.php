<?php

use App\Models\AsignacionTurno;
use App\Models\Turno;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

function pedirSugeridos(): TestResponse
{
    return test()->getJson('/api/v1/turnos/sugeridos', cabecerasApi());
}

function asignarComoMamore(string $ci, array $datos): TestResponse
{
    return test()->postJson("/api/v1/funcionarios/{$ci}/turnos", $datos, cabecerasApi());
}

/**
 * El horario general: cinco turnos idénticos salvo el día, uno por cada día
 * hábil. Es la forma que tiene la tabla real.
 *
 * @return Collection<int, Turno>
 */
function horarioGeneralSugerido(): Collection
{
    return collect([2, 3, 4, 5, 6])->map(fn (int $dia): Turno => Turno::factory()->create([
        'dia' => (string) $dia,
        'nombreTurno' => Turno::DIAS[$dia].': 08:00 - 16:00',
        'sugerido' => true,
    ]));
}

it('agrupa en una sola sugerencia los cinco turnos del horario general', function (): void {
    horarioGeneralSugerido();

    $respuesta = pedirSugeridos();

    $respuesta->assertOk()->assertJsonCount(1, 'data');

    $sugerencia = $respuesta->json('data.0');

    expect($sugerencia['nombre'])->toBe('08:00 - 16:00')
        ->and($sugerencia['hEntrada'])->toBe('08:00')
        ->and($sugerencia['hSalida'])->toBe('16:00')
        ->and($sugerencia['hTolerancia'])->toBe('08:10')
        // JSON no distingue 8 de 8.0: se compara el valor, no el tipo.
        ->and((float) $sugerencia['hTrabajadas'])->toBe(8.0)
        ->and($sugerencia['dias'])->toHaveCount(5)
        ->and($sugerencia['turnoIds'])->toHaveCount(5)
        ->and(array_column($sugerencia['dias'], 'dia'))->toBe([2, 3, 4, 5, 6]);
});

it('entrega las ventanas de marcación de la jornada', function (): void {
    horarioGeneralSugerido();

    $sugerencia = pedirSugeridos()->assertOk()->json('data.0');

    // Sin esto, el consumidor no puede contestar «¿desde qué hora puedo marcar?».
    expect($sugerencia['eMinima'])->toBe('07:00')
        ->and($sugerencia['eMaxima'])->toBe('10:00')
        ->and($sugerencia['sMinima'])->toBe('16:00')
        ->and($sugerencia['sMaxima'])->toBe('23:59');
});

it('no ofrece los turnos que no están marcados', function (): void {
    horarioGeneralSugerido();
    Turno::factory()->count(20)->create(['sugerido' => false]);

    $respuesta = pedirSugeridos();

    expect($respuesta->json('data'))->toHaveCount(1)
        ->and($respuesta->json('data.0.turnoIds'))->toHaveCount(5);
});

it('separa en sugerencias distintas los horarios con horas distintas', function (): void {
    horarioGeneralSugerido();

    Turno::factory()->create([
        'dia' => '2',
        'sugerido' => true,
        'hEntrada' => '1899-12-30 06:30:00',
        'hSalida' => '1899-12-30 14:30:00',
    ]);

    pedirSugeridos()->assertOk()->assertJsonCount(2, 'data');
});

it('devuelve la lista vacía cuando nadie marcó un horario', function (): void {
    Turno::factory()->count(5)->create(['sugerido' => false]);

    pedirSugeridos()->assertOk()->assertExactJson(['data' => []]);
});

it('exige el token', function (): void {
    horarioGeneralSugerido();

    test()->getJson('/api/v1/turnos/sugeridos')->assertStatus(401);
});

it('asigna el horario sugerido con solo mandar la cédula y las fechas', function (): void {
    $turnos = horarioGeneralSugerido();

    $respuesta = asignarComoMamore('7633685', [
        'desde' => '2026-01-05',
        'hasta' => '2026-12-31',
        'observacion' => 'Contrato 123',
    ]);

    $respuesta->assertStatus(201)->assertJsonPath('creados', 5)->assertJsonPath('omitidos', 0);

    expect(AsignacionTurno::query()->where('ci', '7633685')->count())->toBe(5);

    $asignacion = AsignacionTurno::query()->where('turno_id', $turnos->first()->id)->firstOrFail();

    expect($asignacion->desde->toDateString())->toBe('2026-01-05')
        ->and($asignacion->hasta->toDateString())->toBe('2026-12-31')
        ->and(trim($asignacion->idTurno))->toBe(trim($turnos->first()->idTurno))
        ->and($asignacion->observacion)->toBe('Contrato 123');
});

it('asigna solo los turnos indicados cuando vienen en la petición', function (): void {
    horarioGeneralSugerido();
    $otro = Turno::factory()->create(['dia' => '7', 'sugerido' => false]);

    asignarComoMamore('7633685', [
        'desde' => '2026-01-05',
        'hasta' => '2026-06-30',
        'turnoIds' => [$otro->id],
    ])->assertStatus(201)->assertJsonPath('creados', 1);

    expect(AsignacionTurno::query()->where('ci', '7633685')->pluck('turno_id')->all())->toBe([$otro->id]);
});

it('no duplica nada si el contrato se guarda dos veces', function (): void {
    horarioGeneralSugerido();

    $datos = ['desde' => '2026-01-05', 'hasta' => '2026-12-31'];

    asignarComoMamore('7633685', $datos)->assertStatus(201);
    $segunda = asignarComoMamore('7633685', $datos);

    $segunda->assertOk()->assertJsonPath('creados', 0)->assertJsonPath('omitidos', 5);

    expect(AsignacionTurno::query()->where('ci', '7633685')->count())->toBe(5);
});

it('no revienta cuando la asignación previa estaba dada de baja', function (): void {
    horarioGeneralSugerido();

    $datos = ['desde' => '2026-01-05', 'hasta' => '2026-12-31'];

    asignarComoMamore('7633685', $datos)->assertStatus(201);
    AsignacionTurno::query()->where('ci', '7633685')->delete();

    asignarComoMamore('7633685', $datos)->assertOk()->assertJsonPath('omitidos', 5);
});

it('avisa cuando no hay ningún horario sugerido cargado', function (): void {
    Turno::factory()->count(3)->create(['sugerido' => false]);

    asignarComoMamore('7633685', ['desde' => '2026-01-05', 'hasta' => '2026-12-31'])
        ->assertStatus(422)
        ->assertJsonPath('creados', 0);

    expect(AsignacionTurno::query()->count())->toBe(0);
});

it('rechaza un rango invertido', function (): void {
    horarioGeneralSugerido();

    asignarComoMamore('7633685', ['desde' => '2026-12-31', 'hasta' => '2026-01-05'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('hasta');
});

it('exige el token para asignar', function (): void {
    horarioGeneralSugerido();

    test()->postJson('/api/v1/funcionarios/7633685/turnos', ['desde' => '2026-01-05', 'hasta' => '2026-12-31'])
        ->assertStatus(401);

    expect(AsignacionTurno::query()->count())->toBe(0);
});
