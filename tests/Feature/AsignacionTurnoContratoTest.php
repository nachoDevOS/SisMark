<?php

use App\Models\AsignacionHorario;
use App\Models\AsignacionTurno;
use App\Services\AsignadorTurnos;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/*
| Asignar un turno a mano con Mamoré configurado: se elige un contrato firmado o
| concluido de la persona y el turno queda atado a él.
|
| 111 tiene dos contratos firmados (uno vencido, uno vigente hasta el 30/10) y uno
| concluido. El vencido y el concluido pasaron sin turno: se pueden elegir para
| regularizar.
*/
beforeEach(function (): void {
    Carbon::setTestNow('2026-10-15');
    config(['services.mamore.url' => 'https://mamore.test/api', 'services.mamore.token' => 'token']);

    $this->turno = turnoLunesAViernes();

    Http::fake([
        'mamore.test/api/people/ci/111/contracts*' => Http::response(['data' => [
            ['id' => 10, 'code' => 'C-10', 'status' => 'firmado', 'start' => '2025-01-01', 'finish' => '2025-12-31', 'job' => ['name' => 'Viejo']],
            ['id' => 20, 'code' => 'C-20', 'status' => 'firmado', 'start' => '2026-07-01', 'finish' => '2026-10-30',
                'job' => ['name' => 'Técnico'], 'direccion_administrativa' => ['sigla' => 'DST']],
            ['id' => 30, 'code' => 'C-30', 'status' => 'concluido', 'start' => '2026-01-01', 'finish' => '2026-06-30'],
        ]]),
        'mamore.test/api/people/ci/111' => Http::response(['data' => ['ci' => '111', 'full_name' => 'JUAN PEREZ']]),
        'mamore.test/api/people/ci/222/contracts*' => Http::response(['data' => []]),
        'mamore.test/api/people/ci/222' => Http::response(['data' => ['ci' => '222', 'full_name' => 'ANA ROCA']]),
        // 333: Mamoré se cae.
        'mamore.test/api/people/ci/333*' => Http::response([], 500),
    ]);
});

/**
 * Asigna por la pantalla con el contrato vigente de 111.
 *
 * @param  array<string, mixed>  $datos
 */
function asignarConContrato(array $datos = [])
{
    return test()->actingAs(superAdmin())->post(route('turnos-asignados.store'), $datos + [
        'ci' => '111',
        'turno_id' => test()->turno->id,
        'contrato_id' => 20,
        'desde' => '2026-07-01',
        'hasta' => '2026-10-30',
    ]);
}

test('la ficha muestra la persona, sus contratos (los terminados para regularizar) y sus turnos', function (): void {
    app(AsignadorTurnos::class)->asignar('111', $this->turno, Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'));

    $this->actingAs(superAdmin())
        ->getJson(route('turnos-asignados.ficha', ['ci' => '111']))
        ->assertOk()
        ->assertJsonPath('persona.nombre', 'JUAN PEREZ')
        ->assertJsonPath('mamore', true)
        ->assertJsonCount(3, 'contratos')
        ->assertJsonPath('contratos.0.id', 20)
        ->assertJsonPath('contratos.0.regularizar', false)
        // Los terminados van después, del más reciente al más viejo.
        ->assertJsonPath('contratos.1.id', 30)
        ->assertJsonPath('contratos.1.regularizar', true)
        ->assertJsonPath('contratos.2.id', 10)
        ->assertJsonPath('contratos.2.regularizar', true)
        ->assertJsonPath('contratos.0.cargo', 'Técnico')
        ->assertJsonPath('contratos.0.direccion', 'DST')
        ->assertJsonPath('contratos.0.desde', '2026-07-01')
        ->assertJsonPath('contratos.0.hastaTexto', '30/10/2026')
        ->assertJsonPath('contratos.0.empezo', true)
        ->assertJsonPath('contratos.0.turno', null)
        ->assertJsonPath('turnos.0.nombre', $this->turno->nombre)
        ->assertJsonPath('turnos.0.vigente', false)
        ->assertJsonPath('turnos.0.contrato', false);
});

test('asigna el turno atado al contrato elegido', function (): void {
    asignarConContrato()->assertSessionHasNoErrors()->assertRedirect();

    $asignacion = AsignacionTurno::query()->sole();

    expect($asignacion->contrato_id)->toBe(20)
        ->and($asignacion->horariosAsignados->pluck('contrato_id')->unique()->all())->toBe([20]);

    // Ya atado, la ficha lo muestra en el contrato y no deja elegirlo de nuevo.
    $this->getJson(route('turnos-asignados.ficha', ['ci' => '111']))
        ->assertJsonPath('contratos.0.turno.nombre', $this->turno->nombre);
});

test('rechaza lo que no cabe en el contrato', function (array $datos, string $campo, string $mensaje): void {
    asignarConContrato($datos)->assertSessionHasErrors([$campo => $mensaje]);

    expect(AsignacionTurno::query()->count())->toBe(0);
})->with([
    'sin contrato' => [['contrato_id' => null], 'contrato_id', 'Elegí el contrato al que corresponde el turno.'],
    'un contrato de otra persona' => [['contrato_id' => 99], 'contrato_id', 'Ese contrato no es un contrato firmado o concluido de este funcionario.'],
    'empieza antes del contrato' => [['desde' => '2026-06-30'], 'desde', 'La fecha de inicio no puede ser anterior al inicio del contrato (01/07/2026).'],
    'termina después del contrato' => [['hasta' => '2026-11-10'], 'hasta', 'La fecha de fin es la del contrato (30/10/2026).'],
    'termina antes del contrato' => [['hasta' => '2026-10-25'], 'hasta', 'La fecha de fin es la del contrato (30/10/2026).'],
]);

test('un contrato que ya tiene turno no admite otro', function (): void {
    asignarConContrato()->assertSessionHasNoErrors();

    asignarConContrato(['desde' => '2026-10-20'])
        ->assertSessionHasErrors(['contrato_id' => 'Ese contrato ya tiene el turno «'.$this->turno->nombre.'» del 01/07/2026 al 30/10/2026.']);
});

test('concluido el turno de un contrato vigente, se le asigna otro desde el día siguiente', function (): void {
    // Cambio de jornada: tenía el turno todo el contrato 20 y se concluyó el 31/08.
    asignarConContrato()->assertSessionHasNoErrors();
    app(AsignadorTurnos::class)->concluir(AsignacionTurno::query()->sole(), Carbon::parse('2026-08-31'));

    $this->getJson(route('turnos-asignados.ficha', ['ci' => '111']))
        ->assertJsonPath('contratos.0.id', 20)
        ->assertJsonPath('contratos.0.turno.hastaTexto', '31/08/2026')
        ->assertJsonPath('contratos.0.cobertura.estado', 'parcial')
        ->assertJsonPath('contratos.0.cobertura.libreDesde', '2026-09-01')
        ->assertJsonPath('contratos.0.cobertura.libreHasta', '2026-10-30');

    asignarConContrato(['desde' => '2026-09-01'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('estado', 'Turno asignado correctamente.');

    expect(AsignacionTurno::query()->where('contrato_id', 20)->orderBy('desde')->get()
        ->map(fn ($asignacion) => $asignacion->desde->toDateString().' '.$asignacion->hasta->toDateString())->all())
        ->toBe(['2026-07-01 2026-08-31', '2026-09-01 2026-10-30']);

    // Ya llega al fin del contrato: no admite otro.
    $this->getJson(route('turnos-asignados.ficha', ['ci' => '111']))
        ->assertJsonPath('contratos.0.cobertura.estado', 'completa')
        ->assertJsonPath('contratos.0.turno.desdeTexto', '01/09/2026');
});

test('el hueco que queda en el medio de un contrato vigente se cubre con su propio turno', function (): void {
    // Dos turnos en el contrato 20 y el primero se concluye antes: del 16/08 al
    // 31/08 queda sin turno, entre los dos.
    asignarConContrato()->assertSessionHasNoErrors();
    $asignador = app(AsignadorTurnos::class);
    $primero = AsignacionTurno::query()->sole();
    $asignador->concluir($primero, Carbon::parse('2026-08-31'));
    asignarConContrato(['desde' => '2026-09-01'])->assertSessionHasNoErrors();
    $asignador->concluir($primero->fresh(), Carbon::parse('2026-08-15'));

    $this->getJson(route('turnos-asignados.ficha', ['ci' => '111']))
        ->assertJsonPath('contratos.0.cobertura.estado', 'parcial')
        ->assertJsonPath('contratos.0.cobertura.libreDesde', '2026-08-16')
        ->assertJsonPath('contratos.0.cobertura.libreHasta', '2026-08-31');

    // El fin es el día antes del turno siguiente, no el del contrato.
    asignarConContrato(['desde' => '2026-08-16'])
        ->assertSessionHasErrors(['hasta' => 'La fecha de fin es el 31/08/2026: el día antes de los horarios que ya tiene asignados.']);
    asignarConContrato(['desde' => '2026-08-20', 'hasta' => '2026-08-31'])
        ->assertSessionHasErrors(['desde' => 'El turno nuevo empieza el 16/08/2026: el día siguiente al turno anterior («L-V 08:00 - 16:00», hasta el 15/08/2026).']);

    asignarConContrato(['desde' => '2026-08-16', 'hasta' => '2026-08-31'])->assertSessionHasNoErrors();

    expect(AsignacionTurno::query()->where('contrato_id', 20)->orderBy('desde')->get()
        ->map(fn ($asignacion) => $asignacion->desde->toDateString().' '.$asignacion->hasta->toDateString())->all())
        ->toBe(['2026-07-01 2026-08-15', '2026-08-16 2026-08-31', '2026-09-01 2026-10-30']);

    $this->getJson(route('turnos-asignados.ficha', ['ci' => '111']))
        ->assertJsonPath('contratos.0.cobertura.estado', 'completa');
});

test('el turno siguiente de un contrato vigente empieza justo después del anterior', function (array $fechas, string $campo, string $mensaje): void {
    asignarConContrato()->assertSessionHasNoErrors();
    app(AsignadorTurnos::class)->concluir(AsignacionTurno::query()->sole(), Carbon::parse('2026-08-31'));

    asignarConContrato($fechas)->assertSessionHasErrors([$campo => $mensaje]);

    expect(AsignacionTurno::query()->count())->toBe(1);
})->with([
    'deja días sin turno' => [['desde' => '2026-09-15'], 'desde', 'El turno nuevo empieza el 01/09/2026: el día siguiente al último turno del contrato («L-V 08:00 - 16:00», hasta el 31/08/2026).'],
    'se mete en el anterior' => [['desde' => '2026-08-15'], 'desde', 'El turno nuevo empieza el 01/09/2026: el día siguiente al último turno del contrato («L-V 08:00 - 16:00», hasta el 31/08/2026).'],
    'no llega al fin del contrato' => [['desde' => '2026-09-01', 'hasta' => '2026-10-15'], 'hasta', 'La fecha de fin es la del contrato (30/10/2026).'],
]);

test('sin contrato firmado ni concluido no se le puede asignar turno', function (): void {
    $this->actingAs(superAdmin())
        ->getJson(route('turnos-asignados.ficha', ['ci' => '222']))
        ->assertOk()
        ->assertJsonPath('mamore', true)
        ->assertJsonCount(0, 'contratos');

    $this->post(route('turnos-asignados.store'), [
        'ci' => '222', 'turno_id' => $this->turno->id, 'desde' => '2026-10-20', 'hasta' => '2026-12-31',
    ])->assertSessionHasErrors(['contrato_id' => 'El funcionario no tiene contrato firmado ni concluido en Mamoré: no se le puede asignar turno.']);
});

test('regulariza el turno de un contrato que ya terminó', function (int $contratoId, string $desde, string $hasta): void {
    asignarConContrato(['contrato_id' => $contratoId, 'desde' => $desde, 'hasta' => $hasta, 'observacion' => 'Memo 12'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('estado', 'Turno regularizado correctamente.');

    $asignacion = AsignacionTurno::query()->sole();

    expect($asignacion->contrato_id)->toBe($contratoId)
        ->and($asignacion->desde->toDateString())->toBe($desde)
        ->and($asignacion->hasta->toDateString())->toBe($hasta)
        ->and($asignacion->observacion)->toBe('Regularización. Memo 12')
        ->and($asignacion->horariosAsignados)->toHaveCount(5);
})->with([
    'concluido' => [30, '2026-01-01', '2026-06-30'],
    'firmado vencido' => [10, '2025-01-01', '2025-12-31'],
]);

test('un contrato sin fecha de fin deja elegir el fin del turno', function (): void {
    Http::fake([
        'mamore.test/api/people/ci/444/contracts*' => Http::response(['data' => [
            ['id' => 40, 'code' => 'C-40', 'status' => 'firmado', 'start' => '2026-10-01', 'finish' => null],
        ]]),
        'mamore.test/api/people/ci/444' => Http::response(['data' => ['ci' => '444', 'full_name' => 'LUIS SOSA']]),
    ]);

    asignarConContrato(['ci' => '444', 'contrato_id' => 40, 'desde' => '2026-10-20', 'hasta' => '2026-12-31'])
        ->assertSessionHasNoErrors();

    expect(AsignacionTurno::query()->sole()->hasta->toDateString())->toBe('2026-12-31');
});

test('al regularizar tampoco se sale del contrato', function (): void {
    asignarConContrato(['contrato_id' => 30, 'desde' => '2026-01-01', 'hasta' => '2026-07-15'])
        ->assertSessionHasErrors(['hasta' => 'La fecha de fin es la del contrato (30/06/2026).']);

    expect(AsignacionTurno::query()->count())->toBe(0);
});

/**
 * Horarios del sistema anterior de 111 (sin turno): por cada período, uno por
 * día de lunes a viernes, como los migró el SIA.
 *
 * @param  list<array{0: string, 1: string}>  $periodos
 */
function heredadosDe111(array $periodos): void
{
    foreach ($periodos as [$desde, $hasta]) {
        foreach (test()->turno->horarios as $horario) {
            AsignacionHorario::factory()->create([
                'ci' => '111', 'horario_id' => $horario->id,
                'desde' => $desde, 'hasta' => $hasta,
            ]);
        }
    }
}

/**
 * La cobertura del contrato 30 (01/01 al 30/06/2026, terminado) en la ficha de 111.
 *
 * @return array<string, mixed>
 */
function coberturaDel30(): array
{
    return collect(test()->actingAs(superAdmin())
        ->getJson(route('turnos-asignados.ficha', ['ci' => '111']))
        ->json('contratos'))->firstWhere('id', 30)['cobertura'];
}

test('la ficha dice qué tramo del contrato terminado quedó sin control', function (array $periodos, array $esperada): void {
    heredadosDe111($periodos);

    expect(coberturaDel30())->toMatchArray($esperada);
})->with([
    'sin horarios' => [[], [
        'estado' => 'libre', 'libreDesde' => '2026-01-01', 'libreHasta' => '2026-06-30', 'tramosPendientes' => 0, 'motivo' => null,
    ]],
    'cubierto el principio' => [[['2026-01-01', '2026-03-31']], [
        'estado' => 'parcial', 'libreDesde' => '2026-04-01', 'libreHasta' => '2026-06-30', 'tramosPendientes' => 0,
        'motivo' => 'Tiene horarios asignados en parte del contrato. Quedó sin control del 01/04/2026 al 30/06/2026: se regulariza ese tramo.',
    ]],
    'horarios que empiezan antes del contrato' => [[['2025-10-01', '2026-02-15']], ['estado' => 'parcial', 'libreDesde' => '2026-02-16', 'libreHasta' => '2026-06-30']],
    // El caso de 410/2022: sin control al principio, cubierto el final.
    'cubierto el final' => [[['2026-03-01', '2026-06-30']], [
        'estado' => 'parcial', 'libreDesde' => '2026-01-01', 'libreHasta' => '2026-02-28', 'tramosPendientes' => 0,
        'motivo' => 'Tiene horarios asignados en parte del contrato. Quedó sin control del 01/01/2026 al 28/02/2026: se regulariza ese tramo.',
    ]],
    'dos tramos sin control: se ofrece el más antiguo' => [[['2026-01-01', '2026-01-31'], ['2026-03-01', '2026-03-31']], [
        'estado' => 'parcial', 'libreDesde' => '2026-02-01', 'libreHasta' => '2026-02-28', 'tramosPendientes' => 1,
        'motivo' => 'Tiene horarios asignados en parte del contrato. Quedó sin control del 01/02/2026 al 28/02/2026: se regulariza ese tramo. Después queda otro tramo sin control, que se regulariza aparte.',
    ]],
    'todo, en dos períodos pegados' => [[['2026-01-01', '2026-03-31'], ['2026-04-01', '2026-06-30']], ['estado' => 'completa', 'libreDesde' => null]],
    // El SIA cortaba el viernes 27/03 y retomaba el lunes 30/03: ese fin de
    // semana no es un hueco para quien trabaja de lunes a viernes.
    'todo, cortado un fin de semana' => [[['2026-01-01', '2026-03-27'], ['2026-03-30', '2026-06-30']], ['estado' => 'completa']],
    'todo menos un lunes' => [[['2026-01-01', '2026-03-29'], ['2026-03-31', '2026-06-30']], [
        'estado' => 'parcial', 'libreDesde' => '2026-03-30', 'libreHasta' => '2026-03-30',
    ]],
    'todo, y más allá' => [[['2025-06-01', '2027-12-31']], [
        'estado' => 'completa',
        'motivo' => 'Ya tenía horarios asignados en todo el contrato: esos días ya se controlaban, no hace falta regularizar.',
    ]],
]);

test('un fin de semana sin cubrir sí es tramo sin control para quien trabajaba los sábados', function (): void {
    $sabado = horario(7, '08:00', '12:00');

    foreach ([['2026-01-01', '2026-03-27'], ['2026-03-30', '2026-06-30']] as [$desde, $hasta]) {
        AsignacionHorario::factory()->create(['ci' => '111', 'horario_id' => $sabado->id, 'desde' => $desde, 'hasta' => $hasta]);
    }

    expect(coberturaDel30())->toMatchArray(['estado' => 'parcial', 'libreDesde' => '2026-03-28', 'libreHasta' => '2026-03-29']);
});

test('regulariza lo que quedó sin cubrir después de los horarios del sistema anterior', function (): void {
    heredadosDe111([['2026-01-01', '2026-03-31']]);

    asignarConContrato(['contrato_id' => 30, 'desde' => '2026-04-01', 'hasta' => '2026-06-30'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('estado', 'Turno regularizado correctamente.');

    $asignacion = AsignacionTurno::query()->sole();

    expect($asignacion->desde->toDateString())->toBe('2026-04-01')
        ->and($asignacion->hasta->toDateString())->toBe('2026-06-30')
        // El heredado no se tocó: terminaba antes del turno.
        ->and(AsignacionHorario::query()->whereNull('asignacion_turno_id')->pluck('hasta')->map->toDateString()->unique()->all())->toBe(['2026-03-31']);
});

test('regulariza el principio sin control y el turno termina donde empiezan los horarios', function (): void {
    heredadosDe111([['2026-03-01', '2026-06-30']]);

    asignarConContrato(['contrato_id' => 30, 'desde' => '2026-01-01', 'hasta' => '2026-02-28'])
        ->assertSessionHasNoErrors();

    $asignacion = AsignacionTurno::query()->sole();

    expect($asignacion->hasta->toDateString())->toBe('2026-02-28')
        ->and(AsignacionHorario::query()->whereNull('asignacion_turno_id')->pluck('desde')->map->toDateString()->unique()->all())->toBe(['2026-03-01'])
        // Ya no le queda nada sin control: deja de ofrecerse.
        ->and(coberturaDel30()['estado'])->toBe('completa');
});

test('un contrato con varios tramos sin control se regulariza de a uno', function (): void {
    heredadosDe111([['2026-01-01', '2026-01-31'], ['2026-03-01', '2026-03-31']]);

    asignarConContrato(['contrato_id' => 30, 'desde' => '2026-02-01', 'hasta' => '2026-02-28'])->assertSessionHasNoErrors();

    // El turno del primer tramo cuenta como cubierto: ahora se ofrece el siguiente.
    expect(coberturaDel30())->toMatchArray(['estado' => 'parcial', 'libreDesde' => '2026-04-01', 'libreHasta' => '2026-06-30', 'tramosPendientes' => 0]);

    asignarConContrato(['contrato_id' => 30, 'desde' => '2026-04-01', 'hasta' => '2026-06-30'])->assertSessionHasNoErrors();

    expect(AsignacionTurno::query()->where('contrato_id', 30)->count())->toBe(2)
        ->and(coberturaDel30()['estado'])->toBe('completa');
});

test('al regularizar el turno cubre justo el tramo sin control', function (array $fechas, string $campo): void {
    heredadosDe111([['2026-03-01', '2026-03-31']]);

    // Tramo sin control: del 01/01 al 28/02. Después queda otro (01/04 al 30/06).
    asignarConContrato(['contrato_id' => 30, ...$fechas])
        ->assertSessionHasErrors([$campo => 'Tiene horarios asignados en parte del contrato. Quedó sin control del 01/01/2026 al 28/02/2026: se regulariza ese tramo. Después queda otro tramo sin control, que se regulariza aparte.']);

    expect(AsignacionTurno::query()->count())->toBe(0);
})->with([
    'se mete en lo cubierto' => [['desde' => '2026-01-01', 'hasta' => '2026-06-30'], 'hasta'],
    'termina antes del tramo' => [['desde' => '2026-01-01', 'hasta' => '2026-02-15'], 'hasta'],
]);

test('al regularizar no arranca antes del tramo sin control', function (): void {
    heredadosDe111([['2026-01-01', '2026-03-31']]);

    asignarConContrato(['contrato_id' => 30, 'desde' => '2026-03-15', 'hasta' => '2026-06-30'])
        ->assertSessionHasErrors(['desde' => 'Tiene horarios asignados en parte del contrato. Quedó sin control del 01/04/2026 al 30/06/2026: se regulariza ese tramo.']);

    expect(AsignacionTurno::query()->count())->toBe(0);
});

test('no regulariza el contrato que ya estaba cubierto entero', function (): void {
    heredadosDe111([['2025-06-01', '2027-12-31']]);

    asignarConContrato(['contrato_id' => 30, 'desde' => '2026-01-01', 'hasta' => '2026-06-30'])
        ->assertSessionHasErrors(['contrato_id' => 'Ya tenía horarios asignados en todo el contrato: esos días ya se controlaban, no hace falta regularizar.']);

    // Nada se asignó y los heredados siguen enteros.
    expect(AsignacionTurno::query()->count())->toBe(0)
        ->and(AsignacionHorario::query()->pluck('hasta')->map->toDateString()->unique()->all())->toBe(['2027-12-31']);
});

test('cada período de la solapa dice arriba su contrato y su turno, y abajo los días', function (): void {
    asignarConContrato()->assertSessionHasNoErrors();
    asignarConContrato(['contrato_id' => 30, 'desde' => '2026-01-01', 'hasta' => '2026-06-30'])->assertSessionHasNoErrors();
    AsignacionHorario::factory()->create([
        'ci' => '111', 'horario_id' => $this->turno->horarios->first()->id, 'desde' => '2025-03-01', 'hasta' => '2025-06-30',
    ]);

    $this->get(route('funcionarios.horarios.list', ['ci' => '111']))
        ->assertSuccessful()
        // El vigente primero; después los vencidos, del más reciente al más viejo.
        ->assertSeeTextInOrder([
            'C-20', 'L-V 08:00 - 16:00', '01/07/2026', '30/10/2026', 'Vigente', 'Lunes', 'Viernes',
            'C-30', 'L-V 08:00 - 16:00', '01/01/2026', '30/06/2026', 'Regularizado', 'Lunes', 'Viernes',
            // Los horarios del SIA no tienen contrato.
            'Sistema anterior', '01/03/2025', '30/06/2025', 'Lunes',
        ])
        // Cada día dice su tolerancia de entrada.
        ->assertSeeText('tolerancia hasta 08:10 (10 min)')
        // Cada contrato se pliega con su flecha: el vigente arranca abierto, el concluido plegado.
        ->assertSeeInOrder(['x-data="{ abierto: true }"', 'C-20', 'x-data="{ abierto: false }"', 'C-30'], false);
});

test('la solapa dice el contrato una sola vez, con sus turnos debajo', function (): void {
    // Cambio de jornada: dos turnos en el contrato 20.
    asignarConContrato()->assertSessionHasNoErrors();
    app(AsignadorTurnos::class)->concluir(AsignacionTurno::query()->sole(), Carbon::parse('2026-08-31'));
    asignarConContrato(['desde' => '2026-09-01'])->assertSessionHasNoErrors();

    $html = $this->get(route('funcionarios.horarios.list', ['ci' => '111']))
        ->assertSuccessful()
        ->assertSeeTextInOrder(['C-20', 'Vigente', '01/07/2026 al 30/10/2026', '01/09/2026', '30/10/2026', 'Lunes', '01/07/2026', '31/08/2026', 'Lunes'])
        ->getContent();

    expect(substr_count($html, 'C-20'))->toBe(1);
});

test('si Mamoré no responde, la solapa dice el número del contrato', function (): void {
    app(AsignadorTurnos::class)->asignar('333', $this->turno, Carbon::parse('2026-10-20'), Carbon::parse('2026-12-31'), 77);

    $this->actingAs(superAdmin())
        ->get(route('funcionarios.horarios.list', ['ci' => '333']))
        ->assertSuccessful()
        ->assertSeeTextInOrder(['Contrato #77', 'L-V 08:00 - 16:00', '20/10/2026']);
});

test('sin turnos con contrato, la solapa no le pregunta nada a Mamoré', function (): void {
    app(AsignadorTurnos::class)->asignar('111', $this->turno, Carbon::parse('2026-10-20'), Carbon::parse('2026-12-31'));

    $this->actingAs(superAdmin())
        ->get(route('funcionarios.horarios.list', ['ci' => '111']))
        ->assertSuccessful()
        ->assertDontSee('Contrato #');

    Http::assertNotSent(fn ($pedido): bool => str_contains($pedido->url(), '/contracts'));
});

test('si Mamoré no responde no se asigna', function (): void {
    $this->actingAs(superAdmin())
        ->getJson(route('turnos-asignados.ficha', ['ci' => '333']))
        ->assertOk()
        ->assertJsonPath('contratos', [])
        ->assertJsonPath('errorContratos', fn (string $error): bool => str_starts_with($error, 'No se pudieron consultar los contratos en Mamoré'));

    asignarConContrato(['ci' => '333'])->assertSessionHasErrors('contrato_id');
    expect(AsignacionTurno::query()->count())->toBe(0);
});

test('sin Mamoré configurado se asigna sin contrato, como antes', function (): void {
    config(['services.mamore.url' => null]);

    $this->actingAs(superAdmin())
        ->getJson(route('turnos-asignados.ficha', ['ci' => '111']))
        ->assertJsonPath('mamore', false);

    $this->post(route('turnos-asignados.store'), [
        'ci' => '111', 'turno_id' => $this->turno->id, 'desde' => '2026-10-20', 'hasta' => '2026-12-31',
    ])->assertSessionHasNoErrors();

    expect(AsignacionTurno::query()->sole()->contrato_id)->toBeNull();
});

test('las pantallas de asignar cargan el bloque de la ficha', function (): void {
    $this->actingAs(superAdmin())
        ->get(route('turnos-asignados.create', ['ci' => '111']))
        ->assertOk()
        ->assertSee('fichaAsignacion', false)
        ->assertSee('name="contrato_id"', false)
        // Lo que explica la pantalla antes de confirmar.
        ->assertSee('no se le controla la asistencia', false)
        ->assertSee('Un contrato admite un solo turno', false)
        ->assertSee('Se le asignará el turno', false)
        ->assertSee('data-nombre="'.$this->turno->nombre.'"', false);
});
