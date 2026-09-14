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

function moverVigenciaComoMamore(string $ci, array $datos): TestResponse
{
    return test()->putJson("/api/v1/funcionarios/{$ci}/turnos", $datos, cabecerasApi());
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

it('deja las cinco filas atadas al contrato que las originó', function (): void {
    horarioGeneralSugerido();

    asignarComoMamore('7633685', [
        'desde' => '2026-01-05',
        'hasta' => '2026-12-31',
        'contratoId' => 16599,
        'observacion' => 'Contrato A-001/2026',
    ])->assertStatus(201);

    // Las cinco, no una: sin el vínculo en todas, renovar el contrato movería
    // unos días y dejaría los otros con la vigencia vieja.
    expect(AsignacionTurno::query()->delContrato(16599)->count())->toBe(5);
});

it('acepta la asignación sin contrato, para lo que se carga a mano', function (): void {
    horarioGeneralSugerido();

    asignarComoMamore('7633685', ['desde' => '2026-01-05', 'hasta' => '2026-12-31'])
        ->assertStatus(201);

    expect(AsignacionTurno::query()->where('ci', '7633685')->whereNotNull('contrato_id')->count())->toBe(0);
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

/*
|--------------------------------------------------------------------------
| Mover la vigencia cuando el contrato cambia de fechas
|--------------------------------------------------------------------------
*/

/**
 * Deja en la base el horario general asignado a un contrato.
 *
 * Se arma derecho contra el modelo y no llamando al endpoint de alta: el guard
 * de Sanctum cachea el usuario resuelto durante todo el test, así que una
 * llamada autenticada acá dejaría autenticadas también a las que vienen después
 * y las pruebas de «exige el token» pasarían por el motivo equivocado.
 */
function asignarContrato(string $ci, int $contratoId, string $desde, string $hasta): void
{
    foreach (horarioGeneralSugerido() as $turno) {
        AsignacionTurno::create([
            'ci' => $ci,
            'turno_id' => $turno->id,
            'idTurno' => trim((string) $turno->idTurno),
            'desde' => $desde,
            'hasta' => $hasta,
            'contrato_id' => $contratoId,
        ]);
    }
}

it('extiende la vigencia cuando una adenda renueva el contrato', function (): void {
    asignarContrato('7633685', 16599, '2026-01-05', '2026-06-30');

    moverVigenciaComoMamore('7633685', [
        'contratoId' => 16599,
        'desde' => '2026-01-05',
        'hasta' => '2026-12-31',
    ])->assertOk()->assertJsonPath('actualizados', 5);

    // Las cinco: si se moviera una sola, los otros cuatro días de la semana
    // saldrían «no laborable» a partir de julio.
    $hastas = AsignacionTurno::query()->delContrato(16599)->pluck('hasta')
        ->map(fn ($fecha): string => $fecha->toDateString())->unique()->all();

    expect($hastas)->toBe(['2026-12-31']);
});

it('recorta la vigencia cuando el contrato se concluye antes', function (): void {
    asignarContrato('7633685', 16599, '2026-01-05', '2026-12-31');

    moverVigenciaComoMamore('7633685', [
        'contratoId' => 16599,
        'desde' => '2026-01-05',
        'hasta' => '2026-03-31',
    ])->assertOk()->assertJsonPath('actualizados', 5);

    expect(AsignacionTurno::query()->delContrato(16599)->get()
        ->every(fn (AsignacionTurno $a): bool => $a->hasta->toDateString() === '2026-03-31'))->toBeTrue();
});

it('mueve también la fecha de inicio', function (): void {
    asignarContrato('7633685', 16599, '2026-01-05', '2026-06-30');

    moverVigenciaComoMamore('7633685', [
        'contratoId' => 16599,
        'desde' => '2026-02-01',
        'hasta' => '2026-06-30',
    ])->assertOk();

    expect(AsignacionTurno::query()->delContrato(16599)->get()
        ->every(fn (AsignacionTurno $a): bool => $a->desde->toDateString() === '2026-02-01'))->toBeTrue();
});

it('no toca las asignaciones de otro contrato del mismo funcionario', function (): void {
    asignarContrato('7633685', 16599, '2026-01-05', '2026-06-30');

    // Un segundo contrato, con los mismos turnos pero otro período.
    asignarComoMamore('7633685', [
        'desde' => '2026-07-01',
        'hasta' => '2026-12-31',
        'contratoId' => 16600,
    ])->assertStatus(201);

    moverVigenciaComoMamore('7633685', [
        'contratoId' => 16599,
        'desde' => '2026-01-05',
        'hasta' => '2026-05-31',
    ])->assertOk();

    expect(AsignacionTurno::query()->delContrato(16600)->get()
        ->every(fn (AsignacionTurno $a): bool => $a->hasta->toDateString() === '2026-12-31'))->toBeTrue();
});

it('no le mueve el horario a otra persona aunque el contrato coincida', function (): void {
    asignarContrato('7633685', 16599, '2026-01-05', '2026-06-30');

    // La cédula manda junto con el contrato: sin ese filtro, una cédula
    // equivocada del otro lado movería filas de quien no corresponde. Y tampoco
    // se crean nuevas: eso dejaría dos personas con el mismo contrato.
    moverVigenciaComoMamore('9999999', [
        'contratoId' => 16599,
        'desde' => '2026-01-05',
        'hasta' => '2026-12-31',
    ])->assertStatus(422);

    $delContrato = AsignacionTurno::query()->delContrato(16599)->get();

    expect($delContrato)->toHaveCount(5)
        ->and($delContrato->every(fn (AsignacionTurno $a): bool => $a->ci === '7633685'
            && $a->hasta->toDateString() === '2026-06-30'))->toBeTrue();
});

it('le asigna el horario al contrato que todavía no tenía ninguno', function (): void {
    horarioGeneralSugerido();

    // Un contrato anterior a esta integración, o uno cuyo alta falló: la
    // edición es la oportunidad de dejarlo bien sin cargarlo a mano.
    moverVigenciaComoMamore('7633685', [
        'contratoId' => 16599,
        'desde' => '2026-01-05',
        'hasta' => '2026-12-31',
    ])->assertOk()->assertJsonPath('creados', 5);

    expect(AsignacionTurno::query()->delContrato(16599)->count())->toBe(5);
});

it('avisa cuando no hay horario sugerido y el contrato no tenía nada', function (): void {
    Turno::factory()->count(3)->create(['sugerido' => false]);

    moverVigenciaComoMamore('7633685', [
        'contratoId' => 16599,
        'desde' => '2026-01-05',
        'hasta' => '2026-12-31',
    ])->assertStatus(422);

    expect(AsignacionTurno::query()->count())->toBe(0);
});

it('rechaza un rango invertido al mover la vigencia', function (): void {
    asignarContrato('7633685', 16599, '2026-01-05', '2026-06-30');

    moverVigenciaComoMamore('7633685', [
        'contratoId' => 16599,
        'desde' => '2026-12-31',
        'hasta' => '2026-01-05',
    ])->assertStatus(422)->assertJsonValidationErrors('hasta');
});

it('exige el contrato para mover la vigencia', function (): void {
    asignarContrato('7633685', 16599, '2026-01-05', '2026-06-30');

    // Sin contrato no hay forma de saber qué filas mover: mover «las del
    // funcionario» pisaría las de sus otros contratos.
    moverVigenciaComoMamore('7633685', ['desde' => '2026-01-05', 'hasta' => '2026-12-31'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('contratoId');
});

it('exige el token para mover la vigencia', function (): void {
    asignarContrato('7633685', 16599, '2026-01-05', '2026-06-30');

    test()->putJson('/api/v1/funcionarios/7633685/turnos', [
        'contratoId' => 16599,
        'desde' => '2026-01-05',
        'hasta' => '2026-12-31',
    ])->assertStatus(401);

    expect(AsignacionTurno::query()->delContrato(16599)->get()
        ->every(fn (AsignacionTurno $a): bool => $a->hasta->toDateString() === '2026-06-30'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Dar de baja el horario cuando el contrato se anula
|--------------------------------------------------------------------------
*/

function anularContratoComoMamore(string $ci, array $datos): TestResponse
{
    return test()->deleteJson("/api/v1/funcionarios/{$ci}/turnos", $datos, cabecerasApi());
}

it('da de baja el horario cuando el contrato se anula', function (): void {
    asignarContrato('7633685', 16599, '2026-01-05', '2026-12-31');

    anularContratoComoMamore('7633685', ['contratoId' => 16599])
        ->assertOk()
        ->assertJsonPath('eliminados', 5);

    expect(AsignacionTurno::query()->delContrato(16599)->count())->toBe(0);
});

it('la baja es lógica: la fila se queda con su fecha', function (): void {
    asignarContrato('7633685', 16599, '2026-01-05', '2026-12-31');

    anularContratoComoMamore('7633685', ['contratoId' => 16599])->assertOk();

    // El turno es el respaldo de por qué se le exigió marcar a esa persona en
    // esas fechas: borrarlo de verdad dejaría sin explicación las faltas ya
    // imputadas.
    $bajas = AsignacionTurno::onlyTrashed()->delContrato(16599)->get();

    expect($bajas)->toHaveCount(5)
        ->and($bajas->every(fn (AsignacionTurno $a): bool => $a->deleted_at !== null))->toBeTrue()
        ->and($bajas->first()->deleteObservacion)->toBe('Contrato anulado en Mamoré.');
});

it('guarda el motivo que manda el consumidor', function (): void {
    asignarContrato('7633685', 16599, '2026-01-05', '2026-12-31');

    anularContratoComoMamore('7633685', [
        'contratoId' => 16599,
        'observacion' => 'Contrato A-001/2026 anulado por resolución 12/2026.',
    ])->assertOk();

    expect(AsignacionTurno::onlyTrashed()->delContrato(16599)->first()->deleteObservacion)
        ->toBe('Contrato A-001/2026 anulado por resolución 12/2026.');
});

it('no le da de baja el horario a otro contrato del funcionario', function (): void {
    asignarContrato('7633685', 16599, '2026-01-05', '2026-06-30');

    asignarComoMamore('7633685', [
        'desde' => '2026-07-01',
        'hasta' => '2026-12-31',
        'contratoId' => 16600,
    ])->assertStatus(201);

    anularContratoComoMamore('7633685', ['contratoId' => 16599])->assertOk();

    expect(AsignacionTurno::query()->delContrato(16600)->count())->toBe(5)
        ->and(AsignacionTurno::query()->delContrato(16599)->count())->toBe(0);
});

it('no le da de baja el horario a otra persona', function (): void {
    asignarContrato('7633685', 16599, '2026-01-05', '2026-12-31');

    anularContratoComoMamore('9999999', ['contratoId' => 16599])
        ->assertOk()
        ->assertJsonPath('eliminados', 0);

    expect(AsignacionTurno::query()->delContrato(16599)->count())->toBe(5);
});

it('anular dos veces no es un error', function (): void {
    asignarContrato('7633685', 16599, '2026-01-05', '2026-12-31');

    anularContratoComoMamore('7633685', ['contratoId' => 16599])->assertOk();

    // El estado final es el que se pidió: reintentar porque la red cortó la
    // respuesta no puede contestar un error.
    anularContratoComoMamore('7633685', ['contratoId' => 16599])
        ->assertOk()
        ->assertJsonPath('eliminados', 0);
});

it('exige el contrato para dar de baja', function (): void {
    asignarContrato('7633685', 16599, '2026-01-05', '2026-12-31');

    // Sin contrato, «dar de baja los turnos del funcionario» le borraría también
    // los de sus otros contratos.
    anularContratoComoMamore('7633685', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('contratoId');

    expect(AsignacionTurno::query()->delContrato(16599)->count())->toBe(5);
});

it('exige el token para dar de baja', function (): void {
    asignarContrato('7633685', 16599, '2026-01-05', '2026-12-31');

    test()->deleteJson('/api/v1/funcionarios/7633685/turnos', ['contratoId' => 16599])
        ->assertStatus(401);

    expect(AsignacionTurno::query()->delContrato(16599)->count())->toBe(5);
});

it('revive el horario si el contrato se vuelve a cargar', function (): void {
    asignarContrato('7633685', 16599, '2026-01-05', '2026-12-31');
    AsignacionTurno::query()->delContrato(16599)->get()->each->delete();

    // La única de la tabla no distingue `deleted_at`, así que la fila muerta
    // bloqueaba el alta y el funcionario quedaba sin horario —o sea sin control
    // de asistencia— sin que nadie lo notara.
    asignarComoMamore('7633685', [
        'desde' => '2026-01-05',
        'hasta' => '2026-12-31',
        'contratoId' => 16599,
    ])->assertStatus(201)->assertJsonPath('revividos', 5)->assertJsonPath('creados', 5);

    expect(AsignacionTurno::query()->delContrato(16599)->count())->toBe(5);
});

it('no revive la asignación dada de baja de otro contrato', function (): void {
    asignarContrato('7633685', 16599, '2026-01-05', '2026-12-31');
    AsignacionTurno::query()->delContrato(16599)->get()->each->delete();

    // Otro contrato que cae en la misma terna: revivir lo ajeno le daría a este
    // contrato filas que no son suyas.
    asignarComoMamore('7633685', [
        'desde' => '2026-01-05',
        'hasta' => '2026-12-31',
        'contratoId' => 16600,
    ])->assertOk()->assertJsonPath('creados', 0)->assertJsonPath('omitidos', 5);

    expect(AsignacionTurno::query()->delContrato(16600)->count())->toBe(0)
        ->and(AsignacionTurno::onlyTrashed()->delContrato(16599)->count())->toBe(5);
});

/*
|--------------------------------------------------------------------------
| Consultar el horario asignado
|--------------------------------------------------------------------------
|
| Lo lee Mamoré para mostrarle a cada funcionario su propio horario desde su
| perfil, sin darle cuenta en SisMark.
*/

function pedirTurnosComoMamore(string $ci, array $parametros = []): TestResponse
{
    $consulta = $parametros === [] ? '' : '?'.http_build_query($parametros);

    return test()->getJson("/api/v1/funcionarios/{$ci}/turnos{$consulta}", cabecerasApi());
}

it('entrega el horario asignado agrupado por vigencia', function (): void {
    asignarContrato('7633685', 16599, '2026-01-05', '2026-12-31');

    $respuesta = pedirTurnosComoMamore('7633685');

    // Uno y no cinco: las cinco filas del horario semanal comparten período, y
    // sueltas le mostrarían al funcionario cinco «turnos» donde tiene uno.
    $respuesta->assertOk()->assertJsonCount(1, 'data');

    $periodo = $respuesta->json('data.0');

    expect($periodo['desde'])->toBe('2026-01-05')
        ->and($periodo['hasta'])->toBe('2026-12-31')
        ->and($periodo['dias'])->toHaveCount(5)
        ->and(array_column($periodo['dias'], 'dia'))->toBe([2, 3, 4, 5, 6])
        ->and($periodo['dias'][0]['hEntrada'])->toBe('08:00')
        ->and($periodo['dias'][0]['hSalida'])->toBe('16:00')
        // La pregunta que se hace todo el mundo: hasta qué hora puedo llegar.
        ->and($periodo['dias'][0]['hTolerancia'])->toBe('08:10')
        ->and($periodo['dias'][0]['eMinima'])->toBe('07:00')
        ->and($periodo['dias'][0]['sMaxima'])->toBe('23:59');
});

it('separa en bloques distintos dos vigencias del mismo funcionario', function (): void {
    asignarContrato('7633685', 16599, '2020-01-06', '2020-12-31');
    asignarContrato('7633685', 16600, '2026-01-05', '2026-12-31');

    // El contrato viejo es historial: quien entra a ver su horario viene a mirar
    // el que rige, y en una carrera larga lo vencido tapa lo vigente.
    pedirTurnosComoMamore('7633685')->assertOk()->assertJsonCount(1, 'data');

    $todas = pedirTurnosComoMamore('7633685', ['vencidas' => 1])->assertOk();

    $todas->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.situacion', 'vigente')
        ->assertJsonPath('data.1.situacion', 'vencida');
});

it('avisa que el horario todavía no empezó', function (): void {
    asignarContrato('7633685', 16599, now()->addMonth()->toDateString(), now()->addYear()->toDateString());

    // «Futura» y no «vigente»: el funcionario tiene que poder distinguir el
    // horario que ya le corre del que empieza el mes que viene.
    pedirTurnosComoMamore('7633685')->assertOk()->assertJsonPath('data.0.situacion', 'futura');
});

it('devuelve la lista vacía para quien no tiene horario asignado', function (): void {
    horarioGeneralSugerido();

    // No es un error: sin turno, el procesador resuelve todos sus días como «no
    // laborable» y esa persona queda sin control de asistencia. Verlo vacío es
    // justamente lo que hace falta.
    pedirTurnosComoMamore('7633685')->assertOk()->assertExactJson(['data' => []]);
});

it('no le muestra a un funcionario el horario de otro', function (): void {
    asignarContrato('7633685', 16599, '2026-01-05', '2026-12-31');

    pedirTurnosComoMamore('9999999')->assertOk()->assertExactJson(['data' => []]);
});

it('exige el token para consultar el horario', function (): void {
    asignarContrato('7633685', 16599, '2026-01-05', '2026-12-31');

    test()->getJson('/api/v1/funcionarios/7633685/turnos')->assertStatus(401);
});
