<?php

use App\Models\AsignacionTurno;
use App\Models\Asistencia;
use App\Models\DiaExcepcional;
use App\Models\Licencia;
use App\Models\Persona;
use App\Models\Turno;
use App\Services\ProcesadorAsistencia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * Todos los casos usan el mismo lunes, para que el turno con `dia = 2` aplique
 * sin depender de cuándo se corran las pruebas.
 */
const LUNES_PROC = '2026-07-27';

const CI_PROC = '7633685';

/**
 * Turno de referencia del documento de reglas: LUN 08:00–16:00, tolerancia
 * 08:10, ventana de entrada [07:00, 09:00] y de salida [16:00, 20:00].
 *
 * @param  array<string, mixed>  $atributos
 */
function turnoProc(array $atributos = []): Turno
{
    $hora = fn (string $hm): string => "1899-12-30 {$hm}:00";

    return Turno::factory()->create(array_merge([
        'dia' => '2',
        'nombreTurno' => 'LUN: 08:00 - 16:00',
        'hEntrada' => $hora('08:00'),
        'hTolerancia' => $hora('08:10'),
        'eMinima' => $hora('07:00'),
        'eMaxima' => $hora('09:00'),
        'hSalida' => $hora('16:00'),
        'sTolerancia' => $hora('16:00'),
        'sMinima' => $hora('16:00'),
        'sMaxima' => $hora('20:00'),
        'hTrabajadas' => 8,
        'siguienteDia' => false,
    ], $atributos));
}

/**
 * Asigna un turno al funcionario para todo 2026.
 */
function asignarProc(Turno $turno, string $ci = CI_PROC): AsignacionTurno
{
    Persona::factory()->create(['ci' => $ci]);

    return AsignacionTurno::factory()->create([
        'ci' => $ci,
        'turno_id' => $turno->id,
        'desde' => '2026-01-01 00:00:00',
        'hasta' => '2026-12-31 00:00:00',
    ]);
}

/**
 * Segunda asignación para el mismo funcionario (turno partido o rango nuevo).
 */
function asignarProcTambien(Turno $turno, string $desde = '2026-01-01'): AsignacionTurno
{
    return AsignacionTurno::factory()->create([
        'ci' => CI_PROC,
        'turno_id' => $turno->id,
        'desde' => $desde.' 00:00:00',
        'hasta' => '2026-12-31 00:00:00',
    ]);
}

/**
 * Registra marcaciones («08:25» o «08:25:14») en la fecha dada.
 */
function marcarProc(string $fecha, string ...$horas): void
{
    foreach ($horas as $hora) {
        Asistencia::factory()->create([
            'ci' => CI_PROC,
            'fecha' => $fecha.' 00:00:00',
            'hora' => (strlen($hora) === 5 ? $hora.':00' : $hora),
        ]);
    }
}

/**
 * Ficha procesada de un día.
 *
 * @return array<string, mixed>
 */
function diaProc(string $fecha = LUNES_PROC): array
{
    return app(ProcesadorAsistencia::class)
        ->procesar(CI_PROC, Carbon::parse($fecha), Carbon::parse($fecha))
        ->first();
}

/**
 * Turno de la mañana del caso de doble turno: LUN 08:00–12:00.
 */
function turnoProcManana(): Turno
{
    $hora = fn (string $hm): string => "1899-12-30 {$hm}:00";

    return turnoProc([
        'nombreTurno' => 'LUN: 08:00 - 12:00',
        'eMaxima' => $hora('10:00'),
        'hSalida' => $hora('12:00'),
        'sTolerancia' => $hora('12:00'),
        'sMinima' => $hora('12:00'),
        'sMaxima' => $hora('13:45'),
        'hTrabajadas' => 4,
    ]);
}

/**
 * Turno de la tarde del caso de doble turno: LUN 14:00–18:00.
 */
function turnoProcTarde(): Turno
{
    $hora = fn (string $hm): string => "1899-12-30 {$hm}:00";

    return turnoProc([
        'nombreTurno' => 'LUN: 14:00 - 18:00',
        'hEntrada' => $hora('14:00'),
        'hTolerancia' => $hora('14:10'),
        'eMinima' => $hora('13:15'),
        'eMaxima' => $hora('15:00'),
        'hSalida' => $hora('18:00'),
        'sTolerancia' => $hora('18:00'),
        'sMinima' => $hora('17:15'),
        'sMaxima' => $hora('23:59'),
        'hTrabajadas' => 4,
    ]);
}

test('entrada dentro de la tolerancia y salida completa cumplen el turno', function () {
    asignarProc(turnoProc());
    marcarProc(LUNES_PROC, '07:30', '16:59');

    $dia = diaProc();

    expect($dia['estado'])->toBe(ProcesadorAsistencia::CUMPLE)
        ->and($dia['atraso'])->toBe(0)
        ->and($dia['computado'])->toBe(8 * 3600)
        ->and($dia['bloques'][0]['permanencia'])->toBe(9 * 3600 + 29 * 60);
});

test('llegar dentro de la tolerancia no descuenta horas', function () {
    asignarProc(turnoProc());
    marcarProc(LUNES_PROC, '08:05', '16:05');

    $dia = diaProc();

    expect($dia['computado'])->toBe(8 * 3600)
        ->and($dia['estado'])->toBe(ProcesadorAsistencia::CUMPLE);
});

test('el atraso se dispara con la tolerancia pero se mide contra la hora de entrada', function () {
    asignarProc(turnoProc());
    marcarProc(LUNES_PROC, '08:25', '16:50');

    $dia = diaProc();

    expect($dia['estado'])->toBe(ProcesadorAsistencia::ATRASO)
        // 08:25 − 08:00, no 08:25 − 08:10.
        ->and($dia['atraso'])->toBe(25 * 60)
        ->and($dia['computado'])->toBe(7 * 3600 + 35 * 60);
});

test('la tolerancia cubre hasta el último segundo de su minuto', function (string $marca) {
    asignarProc(turnoProc());
    marcarProc(LUNES_PROC, $marca, '16:00');

    $dia = diaProc();

    // Con entrada 08:00 y tolerancia 08:10 son diez minutos de gracia: mientras
    // el reloj siga marcando las 08:10 van diez minutos, no once.
    expect($dia['atraso'])->toBe(0)
        ->and($dia['estado'])->toBe(ProcesadorAsistencia::CUMPLE)
        // Sin atraso no hay déficit: la jornada se acredita entera.
        ->and($dia['computado'])->toBe(8 * 3600);
})->with(['08:10:00', '08:10:01', '08:10:30', '08:10:59']);

test('pasado el minuto de tolerancia el atraso son minutos completos', function (string $marca) {
    asignarProc(turnoProc());
    marcarProc(LUNES_PROC, $marca, '16:00');

    $dia = diaProc();

    // 08:11:00 y 08:11:59 son los dos «11 minutos»: los segundos se descartan.
    expect($dia['atraso'])->toBe(11 * 60)
        ->and(ProcesadorAsistencia::desvio($dia['atraso']))->toBe('11 min')
        ->and($dia['estado'])->toBe(ProcesadorAsistencia::ATRASO)
        // Las horas descuentan los mismos once minutos que informa el atraso.
        ->and($dia['computado'])->toBe(8 * 3600 - 11 * 60);
})->with(['08:11:00', '08:11:01', '08:11:59']);

test('la marca posterior a la máxima hora de entrada no vale como entrada', function () {
    asignarProc(turnoProc());
    marcarProc(LUNES_PROC, '09:15', '16:10');

    $dia = diaProc();

    expect($dia['estado'])->toBe(ProcesadorAsistencia::SIN_ENTRADA)
        ->and($dia['computado'])->toBe(0);
});

test('la marca anterior a la mínima hora de entrada no vale como entrada', function () {
    asignarProc(turnoProc());
    marcarProc(LUNES_PROC, '06:59', '16:00');

    expect(diaProc()['estado'])->toBe(ProcesadorAsistencia::SIN_ENTRADA);
});

test('retirarse antes de la mínima hora de salida es abandono', function () {
    asignarProc(turnoProc());
    marcarProc(LUNES_PROC, '07:50', '15:40');

    $dia = diaProc();

    expect($dia['estado'])->toBe(ProcesadorAsistencia::ABANDONO)
        ->and($dia['computado'])->toBe(0)
        ->and($dia['bloques'][0]['avisos'])->toContain('Marcó antes de la mínima hora de salida (16:00:00).');
});

test('marcar después de la máxima hora de salida no es abandono sino día sin cerrar', function () {
    asignarProc(turnoProc());
    marcarProc(LUNES_PROC, '08:12', '20:08');

    $dia = diaProc();

    expect($dia['estado'])->toBe(ProcesadorAsistencia::SIN_SALIDA)
        ->and($dia['atraso'])->toBe(12 * 60)
        ->and($dia['bloques'][0]['avisos'])->toContain('Marcó después de la máxima hora de salida (20:00:00).');
});

test('sin ninguna marca el día es falta', function () {
    asignarProc(turnoProc());

    $dia = diaProc();

    expect($dia['estado'])->toBe(ProcesadorAsistencia::FALTA)
        ->and($dia['esperado'])->toBe(8 * 3600);
});

test('los rebotes del reloj se cuentan como una sola marca', function () {
    asignarProc(turnoProc());
    marcarProc(LUNES_PROC, '07:44:12', '07:44:16', '16:10:51', '16:10:56');

    $dia = diaProc();

    expect($dia['marcas'])->toHaveCount(2)
        ->and($dia['estado'])->toBe(ProcesadorAsistencia::CUMPLE);
});

test('las marcas repetidas en la ventana de entrada se ignoran y manda la primera', function () {
    asignarProc(turnoProc());
    marcarProc(LUNES_PROC, '07:59', '08:29', '17:56');

    $dia = diaProc();

    expect($dia['estado'])->toBe(ProcesadorAsistencia::CUMPLE)
        ->and($dia['bloques'][0]['entrada'])->toBe(7 * 3600 + 59 * 60)
        ->and($dia['atraso'])->toBe(0);
});

test('las marcas de la zona muerta se descartan', function () {
    asignarProc(turnoProc());
    marcarProc(LUNES_PROC, '07:58', '08:05', '13:00', '18:45');

    $dia = diaProc();

    expect($dia['estado'])->toBe(ProcesadorAsistencia::CUMPLE)
        ->and($dia['marcas'])->toHaveCount(4)
        ->and($dia['marcasUsadas'])->toBe(2);
});

test('sin turno asignado el día no es falta', function () {
    asignarProc(turnoProc());

    // Domingo: el turno es de los lunes.
    expect(diaProc('2026-07-26')['estado'])->toBe(ProcesadorAsistencia::NO_LABORABLE);
});

test('el día excepcional manda sobre las marcaciones y sobre el turno', function () {
    asignarProc(turnoProc());
    marcarProc(LUNES_PROC, '08:25', '13:10', '16:50');
    DiaExcepcional::factory()->create(['fecha' => LUNES_PROC.' 00:00:00', 'motivoInasistencia' => 'CARNAVAL']);

    $dia = diaProc();

    expect($dia['estado'])->toBe(ProcesadorAsistencia::EXCEPCIONAL)
        ->and($dia['motivo'])->toBe('CARNAVAL')
        ->and($dia['bloques'])->toBe([])
        ->and($dia['atraso'])->toBe(0);
});

test('el día del calendario sin motivo no es excepcional', function () {
    asignarProc(turnoProc());
    marcarProc(LUNES_PROC, '08:25', '16:50');
    DiaExcepcional::factory()->create(['fecha' => LUNES_PROC.' 00:00:00', 'motivoInasistencia' => null]);

    expect(diaProc()['estado'])->toBe(ProcesadorAsistencia::ATRASO);
});

test('la licencia de turno completo cubre todos los turnos del día', function () {
    $manana = turnoProcManana();
    asignarProc($manana);
    asignarProcTambien(turnoProcTarde());

    // Apunta a un solo turno, pero el día entero queda licenciado.
    Licencia::factory()->create([
        'ci' => CI_PROC,
        'fecha' => LUNES_PROC.' 00:00:00',
        'turno_id' => $manana->id,
        'tCompleto' => true,
        'motivo' => 'VACACIÓN',
    ]);

    $dia = diaProc();

    expect($dia['estado'])->toBe(ProcesadorAsistencia::LICENCIA)
        ->and($dia['motivo'])->toBe('VACACIÓN')
        // Un bloque por turno, para que el reporte muestre el horario licenciado
        // con su T.C. y su motivo, pero sin sumar ni restar horas.
        ->and($dia['bloques'])->toHaveCount(2)
        ->and($dia['bloques'][1]['estado'])->toBe(ProcesadorAsistencia::LICENCIA)
        ->and($dia['bloques'][1]['licencia']->motivo)->toBe('VACACIÓN')
        ->and($dia['esperado'])->toBe(0)
        ->and($dia['computado'])->toBe(0);
});

test('la licencia por horas que cubre la entrada no exige marcar al ingresar', function () {
    $turno = turnoProc();
    asignarProc($turno);
    Licencia::factory()->porHoras('08:00', '11:00')->create([
        'ci' => CI_PROC, 'fecha' => LUNES_PROC.' 00:00:00', 'turno_id' => $turno->id,
    ]);
    marcarProc(LUNES_PROC, '16:56');

    $dia = diaProc();

    expect($dia['estado'])->toBe(ProcesadorAsistencia::CUMPLE)
        ->and($dia['bloques'][0]['entradaExigida'])->toBeFalse()
        // 3h de licencia + 5h trabajadas.
        ->and($dia['computado'])->toBe(8 * 3600);
});

test('la licencia por horas que arranca tarde deja un hueco y no marcar es abandono', function () {
    $turno = turnoProc();
    asignarProc($turno);
    Licencia::factory()->porHoras('08:05', '11:00')->create([
        'ci' => CI_PROC, 'fecha' => LUNES_PROC.' 00:00:00', 'turno_id' => $turno->id,
    ]);
    marcarProc(LUNES_PROC, '16:25');

    $dia = diaProc();

    expect($dia['estado'])->toBe(ProcesadorAsistencia::ABANDONO)
        ->and($dia['bloques'][0]['entradaExigida'])->toBeTrue()
        // 2h55 de licencia + 5h trabajadas: faltan los 5 min sin justificar.
        ->and($dia['computado'])->toBe(7 * 3600 + 55 * 60);
});

test('la licencia por horas al final del turno no exige marcar la salida', function () {
    $turno = turnoProc();
    asignarProc($turno);
    Licencia::factory()->porHoras('13:00', '16:00')->create([
        'ci' => CI_PROC, 'fecha' => LUNES_PROC.' 00:00:00', 'turno_id' => $turno->id,
    ]);
    marcarProc(LUNES_PROC, '08:05');

    $dia = diaProc();

    expect($dia['estado'])->toBe(ProcesadorAsistencia::CUMPLE)
        ->and($dia['bloques'][0]['salidaExigida'])->toBeFalse()
        ->and($dia['computado'])->toBe(8 * 3600);
});

test('el turno nocturno busca la salida en la fecha siguiente', function () {
    $hora = fn (string $hm): string => "1899-12-30 {$hm}:00";

    asignarProc(turnoProc([
        'nombreTurno' => 'LUN: 18:00 - 07:00',
        'hEntrada' => $hora('18:00'), 'hTolerancia' => $hora('18:10'),
        'eMinima' => $hora('16:00'), 'eMaxima' => $hora('21:00'),
        'hSalida' => $hora('07:00'), 'sTolerancia' => $hora('07:10'),
        'sMinima' => $hora('06:00'), 'sMaxima' => $hora('10:00'),
        'hTrabajadas' => 13, 'siguienteDia' => true,
    ]));
    marcarProc(LUNES_PROC, '17:55');
    marcarProc('2026-07-28', '07:05');

    $dia = diaProc();

    expect($dia['estado'])->toBe(ProcesadorAsistencia::CUMPLE)
        ->and($dia['computado'])->toBe(13 * 3600);
});

test('reparte las marcas entre los dos turnos del día', function () {
    asignarProc(turnoProcManana());
    asignarProcTambien(turnoProcTarde());

    marcarProc(LUNES_PROC, '08:00', '09:01', '12:42', '14:36', '18:53');

    $dia = diaProc();

    expect($dia['bloques'])->toHaveCount(2)
        ->and($dia['bloques'][0]['estado'])->toBe(ProcesadorAsistencia::CUMPLE)
        ->and($dia['bloques'][0]['computado'])->toBe(4 * 3600)
        ->and($dia['bloques'][1]['estado'])->toBe(ProcesadorAsistencia::ATRASO)
        ->and($dia['bloques'][1]['atraso'])->toBe(36 * 60)
        ->and($dia['bloques'][1]['computado'])->toBe(3 * 3600 + 24 * 60)
        // Gana el estado más grave de los dos turnos.
        ->and($dia['estado'])->toBe(ProcesadorAsistencia::ATRASO)
        ->and($dia['computado'])->toBe(7 * 3600 + 24 * 60)
        ->and($dia['marcasUsadas'])->toBe(4);
});

test('con dos turnos, irse al mediodía deja abandono en el primero y falta en el segundo', function () {
    asignarProc(turnoProcManana());
    asignarProcTambien(turnoProcTarde());

    marcarProc(LUNES_PROC, '08:12', '11:08');

    $dia = diaProc();

    expect($dia['bloques'][0]['estado'])->toBe(ProcesadorAsistencia::ABANDONO)
        ->and($dia['bloques'][0]['atraso'])->toBe(12 * 60)
        ->and($dia['bloques'][1]['estado'])->toBe(ProcesadorAsistencia::FALTA)
        ->and($dia['estado'])->toBe(ProcesadorAsistencia::FALTA)
        ->and($dia['computado'])->toBe(0)
        ->and($dia['esperado'])->toBe(8 * 3600);
});

test('el turno con ventana de salida invertida se reporta mal configurado', function () {
    asignarProc(turnoProc([
        'sMinima' => '1899-12-30 20:00:00',
        'sMaxima' => '1899-12-30 16:00:00',
    ]));
    marcarProc(LUNES_PROC, '08:00', '16:10');

    $dia = diaProc();

    expect($dia['estado'])->toBe(ProcesadorAsistencia::TURNO_INVALIDO)
        ->and($dia['bloques'][0]['avisos'])->toContain('Ventana de salida invertida (mínima mayor que máxima).');
});

test('avisa cuando la mínima hora de salida es igual a la tolerancia', function () {
    asignarProc(turnoProc());
    marcarProc(LUNES_PROC, '08:00', '16:10');

    expect(diaProc()['bloques'][0]['avisos'])
        ->toContain('Mínima hora de salida igual a la tolerancia: nunca se reporta salida anticipada.');
});

test('avisa cuando el turno diurno está marcado como salida al día siguiente', function () {
    asignarProc(turnoProc(['siguienteDia' => true]));

    expect(diaProc()['bloques'][0]['avisos'])
        ->toContain('Marcado como salida al día siguiente, pero la salida es posterior a la entrada.');
});

test('los totales del rango suman los días procesados', function () {
    asignarProc(turnoProc());
    marcarProc(LUNES_PROC, '08:25', '16:50');
    marcarProc('2026-07-28', '08:00', '16:00');

    $procesador = app(ProcesadorAsistencia::class);
    $dias = $procesador->procesar(CI_PROC, Carbon::parse(LUNES_PROC), Carbon::parse('2026-07-31'));
    $totales = $procesador->totales($dias);

    expect($totales['dias'])->toBe(5)
        ->and($totales['atraso'])->toBe(25 * 60)
        ->and($totales['computado'])->toBe(7 * 3600 + 35 * 60)
        ->and($totales['esperado'])->toBe(8 * 3600)
        ->and($totales['saldo'])->toBe(-25 * 60)
        ->and($totales['porEstado'][ProcesadorAsistencia::ATRASO])->toBe(1)
        ->and($totales['porEstado'][ProcesadorAsistencia::NO_LABORABLE])->toBe(4);
});

test('con dos asignaciones del mismo turno no se duplica el bloque', function () {
    $turno = turnoProc();

    asignarProc($turno);
    // Rango solapado, mismo turno: en el SIA pasa seguido.
    asignarProcTambien($turno, '2026-06-01');

    marcarProc(LUNES_PROC, '08:00', '16:10');

    expect(diaProc()['bloques'])->toHaveCount(1);
});

test('la duración y el desvío se formatean para el reporte', function () {
    expect(ProcesadorAsistencia::duracion(8 * 3600 + 25 * 60))->toBe('8h 25m')
        ->and(ProcesadorAsistencia::duracion(null))->toBe('—')
        ->and(ProcesadorAsistencia::desvio(0))->toBe('0 min')
        ->and(ProcesadorAsistencia::desvio(25 * 60))->toBe('25 min')
        ->and(ProcesadorAsistencia::desvio(601))->toBe('10 min 1 seg')
        ->and(ProcesadorAsistencia::hora(null))->toBe('—')
        ->and(ProcesadorAsistencia::hora(8 * 3600 + 5 * 60))->toBe('08:05:00');
});
