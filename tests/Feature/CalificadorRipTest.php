<?php

use App\Models\AsignacionTurno;
use App\Models\Asistencia;
use App\Models\DiaExcepcional;
use App\Models\Persona;
use App\Models\Turno;
use App\Services\CalificadorRip;
use App\Services\EscalaRip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * Julio de 2026 tiene cuatro lunes —6, 13, 20 y 27— y ningún otro día con turno
 * asignado en estas pruebas. Así el mes queda con exactamente cuatro días
 * controlados y las rachas de continuidad se pueden armar a mano.
 */
const CI_RIP = '7633685';

const LUNES_RIP = ['2026-07-06', '2026-07-13', '2026-07-20', '2026-07-27'];

/**
 * Turno de referencia: LUN 08:00–16:00, tolerancia 08:10, entrada [07:00,
 * 09:00], salida [16:00, 20:00]. Los mismos valores del documento de reglas.
 *
 * @param  array<string, mixed>  $atributos
 */
function turnoRip(array $atributos = []): Turno
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

function asignarRip(Turno $turno): AsignacionTurno
{
    Persona::firstOrCreate(['ci' => CI_RIP], Persona::factory()->make(['ci' => CI_RIP])->toArray());

    return AsignacionTurno::factory()->create([
        'ci' => CI_RIP,
        'turno_id' => $turno->id,
        'desde' => '2026-01-01 00:00:00',
        'hasta' => '2026-12-31 00:00:00',
    ]);
}

function marcarRip(string $fecha, string ...$horas): void
{
    foreach ($horas as $hora) {
        Asistencia::factory()->create([
            'ci' => CI_RIP,
            'fecha' => $fecha.' 00:00:00',
            'hora' => (strlen($hora) === 5 ? $hora.':00' : $hora),
        ]);
    }
}

/**
 * Marca la jornada completa y puntual, para que el día no aporte nada.
 */
function jornadaLimpiaRip(string $fecha): void
{
    marcarRip($fecha, '08:00', '16:05');
}

/**
 * Califica julio de 2026 para el funcionario de prueba.
 *
 * @return array<string, mixed>
 */
function julioRip(): array
{
    return app(CalificadorRip::class)->mes(CI_RIP, 2026, 7);
}

// ---------------------------------------------------------------------------
// Atrasos
// ---------------------------------------------------------------------------

it('acumula los minutos de atraso de todo el mes', function () {
    asignarRip(turnoRip());

    marcarRip('2026-07-06', '08:25', '16:05');   // 25 min
    marcarRip('2026-07-13', '08:20', '16:05');   // 20 min
    jornadaLimpiaRip('2026-07-20');
    jornadaLimpiaRip('2026-07-27');

    $mes = julioRip();

    expect($mes['acumulado']['atrasoSegundos'])->toBe(45 * 60)
        ->and($mes['diasControlados'])->toBe(4)
        ->and($mes['sanciones'])->toHaveCount(1)
        ->and($mes['sanciones'][0]['tipo'])->toBe(EscalaRip::ATRASO)
        ->and($mes['sanciones'][0]['dias'])->toBe(0.5);
});

it('no cuenta como atraso la llegada dentro de la tolerancia', function () {
    asignarRip(turnoRip());

    foreach (LUNES_RIP as $lunes) {
        marcarRip($lunes, '08:10', '16:05');
    }

    $mes = julioRip();

    expect($mes['acumulado']['atrasoSegundos'])->toBe(0)
        ->and($mes['sanciones'])->toBe([]);
});

it('mide el atraso desde el fin de la tolerancia si así se configura', function () {
    config()->set('rip.atrasoDesde', EscalaRip::DESDE_TOLERANCIA);

    asignarRip(turnoRip());
    marcarRip('2026-07-06', '08:25', '16:05');   // 25 nominales, 15 desde la tolerancia
    jornadaLimpiaRip('2026-07-13');
    jornadaLimpiaRip('2026-07-20');
    jornadaLimpiaRip('2026-07-27');

    expect(julioRip()['acumulado']['atrasoSegundos'])->toBe(15 * 60);
});

it('convierte en inasistencia la llegada posterior al corte de 30 minutos', function () {
    asignarRip(turnoRip());

    // 08:45 está dentro de la ventana de entrada (hasta 09:00) pero pasa el
    // corte del Art. 45.II: deja de ser atraso y es media jornada perdida.
    marcarRip('2026-07-06', '08:45', '16:05');
    jornadaLimpiaRip('2026-07-13');
    jornadaLimpiaRip('2026-07-20');
    jornadaLimpiaRip('2026-07-27');

    $mes = julioRip();

    expect($mes['acumulado']['atrasoSegundos'])->toBe(0)
        ->and($mes['acumulado']['inasistenciaJornadas'])->toBe(1.0)
        ->and($mes['sanciones'][0]['tipo'])->toBe(EscalaRip::INASISTENCIA)
        ->and($mes['sanciones'][0]['articulo'])->toBe('45.II');
});

// ---------------------------------------------------------------------------
// Inasistencias, ausencias y omisiones
// ---------------------------------------------------------------------------

it('cuenta como inasistencia el día sin ninguna marca', function () {
    asignarRip(turnoRip());

    jornadaLimpiaRip('2026-07-06');
    jornadaLimpiaRip('2026-07-13');
    jornadaLimpiaRip('2026-07-20');
    // El 27 no marcó nada.

    $mes = julioRip();

    expect($mes['acumulado']['inasistenciaJornadas'])->toBe(1.0)
        ->and($mes['acumulado']['inasistenciaDias'])->toBe(1)
        ->and($mes['sanciones'][0]['dias'])->toBe(2.0);
});

it('cuenta como omisión el día en que no marcó la salida', function () {
    asignarRip(turnoRip());

    marcarRip('2026-07-06', '08:00');   // entró y no marcó salida
    jornadaLimpiaRip('2026-07-13');
    jornadaLimpiaRip('2026-07-20');
    jornadaLimpiaRip('2026-07-27');

    $mes = julioRip();

    expect($mes['acumulado']['omisiones'])->toBe(1)
        ->and($mes['acumulado']['inasistenciaJornadas'])->toBe(0.0)
        ->and($mes['sanciones'][0]['tipo'])->toBe(EscalaRip::OMISION)
        ->and($mes['sanciones'][0]['dias'])->toBe(0.5);
});

it('cuenta como ausencia en el puesto el retiro antes de hora', function () {
    asignarRip(turnoRip());

    marcarRip('2026-07-06', '08:00', '15:40');   // antes de sMinima 16:00
    jornadaLimpiaRip('2026-07-13');
    jornadaLimpiaRip('2026-07-20');
    jornadaLimpiaRip('2026-07-27');

    $mes = julioRip();

    expect($mes['acumulado']['ausenciaJornadas'])->toBe(1.0)
        ->and($mes['sanciones'][0]['tipo'])->toBe(EscalaRip::AUSENCIA)
        ->and($mes['sanciones'][0]['articulo'])->toBe('45.II');
});

it('mide la racha de días continuos sobre días controlados, no del calendario', function () {
    asignarRip(turnoRip());

    // Faltó tres lunes seguidos: entre ellos hay dos fines de semana enteros,
    // pero para el reglamento son tres días hábiles consecutivos (Art. 48).
    jornadaLimpiaRip('2026-07-06');

    $mes = julioRip();

    expect($mes['acumulado']['inasistenciaDias'])->toBe(3)
        ->and($mes['acumulado']['inasistenciaContinuos'])->toBe(3)
        ->and($mes['sanciones'][0]['articulo'])->toBe('48')
        ->and($mes['sanciones'][0]['procesoInterno'])->toBeTrue()
        ->and($mes['totalDias'])->toBeNull();
});

// ---------------------------------------------------------------------------
// Días que no se califican
// ---------------------------------------------------------------------------

it('no sanciona los días excepcionales', function () {
    asignarRip(turnoRip());

    DiaExcepcional::factory()->create([
        'fecha' => '2026-07-06',
        'motivoInasistencia' => 'PARO CIVICO',
    ]);

    jornadaLimpiaRip('2026-07-13');
    jornadaLimpiaRip('2026-07-20');
    jornadaLimpiaRip('2026-07-27');

    $mes = julioRip();

    expect($mes['sanciones'])->toBe([])
        ->and($mes['diasControlados'])->toBe(3);
});

it('aparta los días con el turno mal configurado en vez de calificarlos', function () {
    $hora = fn (string $hm): string => "1899-12-30 {$hm}:00";

    // Ventana de entrada invertida: el procesador lo marca «turno inválido» y
    // un turno roto no puede fundar un descuento.
    asignarRip(turnoRip([
        'eMinima' => $hora('09:00'),
        'eMaxima' => $hora('07:00'),
    ]));

    $mes = julioRip();

    expect($mes['diasApartados'])->toBe(4)
        ->and($mes['diasControlados'])->toBe(0)
        ->and($mes['sanciones'])->toBe([]);
});

// ---------------------------------------------------------------------------
// Turno partido
// ---------------------------------------------------------------------------

it('reparte la jornada entre los dos turnos del día partido', function () {
    $hora = fn (string $hm): string => "1899-12-30 {$hm}:00";

    asignarRip(turnoRip([
        'nombreTurno' => 'LUN: 08:00 - 12:00',
        'eMaxima' => $hora('10:00'),
        'hSalida' => $hora('12:00'),
        'sTolerancia' => $hora('12:00'),
        'sMinima' => $hora('12:00'),
        'sMaxima' => $hora('13:00'),
        'hTrabajadas' => 4,
    ]));

    asignarRip(turnoRip([
        'nombreTurno' => 'LUN: 14:00 - 18:00',
        'hEntrada' => $hora('14:00'),
        'hTolerancia' => $hora('14:10'),
        'eMinima' => $hora('13:15'),
        'eMaxima' => $hora('15:00'),
        'hSalida' => $hora('18:00'),
        'sTolerancia' => $hora('18:00'),
        'sMinima' => $hora('18:00'),
        'sMaxima' => $hora('20:00'),
        'hTrabajadas' => 4,
    ]));

    // El 6 cumple la mañana y falta a la tarde: media jornada, no una entera.
    marcarRip('2026-07-06', '08:00', '12:10');

    foreach (['2026-07-13', '2026-07-20', '2026-07-27'] as $lunes) {
        marcarRip($lunes, '08:00', '12:10', '14:00', '18:05');
    }

    $mes = julioRip();

    expect($mes['acumulado']['inasistenciaJornadas'])->toBe(0.5)
        ->and($mes['sanciones'][0]['dias'])->toBe(1.0)
        ->and($mes['sanciones'][0]['articulo'])->toBe('45.II');
});

// ---------------------------------------------------------------------------
// Mes en curso
// ---------------------------------------------------------------------------

it('no cuenta como falta los días del mes que todavía no llegaron', function () {
    // Miércoles 19 de agosto de 2026: quedan ocho días con turno para terminar
    // el mes (20, 21, 24, 25, 26, 27, 28 y 31). Sin el corte se calificaban como
    // ocho inasistencias seguidas y disparaban el abandono de funciones.
    $this->travelTo('2026-08-19 15:00:00');

    $hora = fn (string $hm): string => "1899-12-30 {$hm}:00";

    // Turno para los cinco días hábiles, no solo el lunes.
    foreach ([2, 3, 4, 5, 6] as $dia) {
        asignarRip(turnoRip(['dia' => (string) $dia, 'nombreTurno' => "DIA {$dia}: 08:00 - 16:00"]));
    }

    // Marca puntual todos los días hábiles del 3 al 18 de agosto.
    for ($fecha = Carbon::parse('2026-08-03'); $fecha->lessThanOrEqualTo(Carbon::parse('2026-08-18')); $fecha->addDay()) {
        if ($fecha->isWeekend()) {
            continue;
        }

        marcarRip($fecha->toDateString(), '08:00', '16:05');
    }

    $mes = app(CalificadorRip::class)->mes(CI_RIP, 2026, 8);

    expect($mes['enCurso'])->toBeTrue()
        ->and($mes['hasta']->toDateString())->toBe('2026-08-18')
        ->and($mes['acumulado']['inasistenciaJornadas'])->toBe(0.0)
        ->and($mes['sanciones'])->toBe([]);
});

it('califica el mes entero cuando ya terminó', function () {
    $this->travelTo('2026-08-19 15:00:00');

    asignarRip(turnoRip());
    // Julio ya pasó: sus cuatro lunes se califican completos, sin marcas.

    $mes = app(CalificadorRip::class)->mes(CI_RIP, 2026, 7);

    expect($mes['enCurso'])->toBeFalse()
        ->and($mes['hasta']->toDateString())->toBe('2026-07-31')
        ->and($mes['diasControlados'])->toBe(4)
        ->and($mes['acumulado']['inasistenciaJornadas'])->toBe(4.0);
});

it('no califica nada si el mes recién arranca hoy', function () {
    $this->travelTo('2026-08-01 09:00:00');

    asignarRip(turnoRip());

    $mes = app(CalificadorRip::class)->mes(CI_RIP, 2026, 8);

    expect($mes['diasControlados'])->toBe(0)
        ->and($mes['sanciones'])->toBe([]);
});

// ---------------------------------------------------------------------------
// Dos contratos en el mismo mes, con sueldos distintos
// ---------------------------------------------------------------------------
//
// El caso real: se le vence un contrato el 10 de agosto y se le firma otro el
// 20, con otro sueldo. Del 11 al 19 no tiene ninguno.
//
// Agosto de 2026 tiene cinco lunes —3, 10, 17, 24 y 31—, así que quedan dos
// días controlados bajo el contrato viejo, uno en el hueco y dos bajo el nuevo.

const LUNES_AGOSTO_RIP = ['2026-08-03', '2026-08-10', '2026-08-17', '2026-08-24', '2026-08-31'];

/**
 * Mamoré responde con los dos contratos del mes.
 */
function conDosContratosRip(): void
{
    fakeMamore([CI_RIP => [
        'nombre' => 'IGNACIO MOLINA GUZMAN',
        'cargo' => 'TECNICO II',
        'contratos' => [
            ['salary' => 5000, 'bonus' => null, 'start' => '2026-08-20', 'finish' => '2026-09-30'],
            ['salary' => 4500, 'bonus' => null, 'start' => '2026-05-01', 'finish' => '2026-08-10'],
        ],
    ]]);
}

/**
 * Califica agosto de 2026 con el mes ya cerrado.
 *
 * @return array<string, mixed>
 */
function agostoRip(): array
{
    // El mes en curso se corta en ayer; se congela el reloj en septiembre para
    // calificar agosto entero.
    Carbon::setTestNow(Carbon::create(2026, 9, 15, 8));

    return app(CalificadorRip::class)->mes(CI_RIP, 2026, 8);
}

it('cobra cada atraso al sueldo del contrato que regía ese día', function () {
    asignarRip(turnoRip());
    conDosContratosRip();

    marcarRip('2026-08-03', '08:25', '16:05');   // 25 min · contrato de 4.500
    marcarRip('2026-08-10', '08:20', '16:05');   // 20 min · contrato de 4.500
    jornadaLimpiaRip('2026-08-24');
    marcarRip('2026-08-31', '08:20', '16:05');   // 20 min · contrato de 5.000

    $mes = agostoRip();

    // 65 minutos en el mes → Art. 45.I, tramo 61-90 → 2 días.
    expect($mes['acumulado']['atrasoSegundos'])->toBe(65 * 60)
        ->and($mes['sanciones'][0]['dias'])->toBe(2.0);

    // 45 min bajo el contrato de 4.500 (150 Bs el día) y 20 bajo el de 5.000
    // (166,67 Bs el día). Con un solo sueldo habrían sido 2 × 166,67 = 333,33.
    expect($mes['sanciones'][0]['monto'])->toBe(310.26)
        ->and($mes['totalMonto'])->toBe(310.26);
});

it('no califica los días en que la persona no tenía contrato', function () {
    asignarRip(turnoRip());
    conDosContratosRip();

    jornadaLimpiaRip('2026-08-03');
    jornadaLimpiaRip('2026-08-10');
    // El 17 cae en el hueco entre contratos y no se marca nada.
    jornadaLimpiaRip('2026-08-24');
    jornadaLimpiaRip('2026-08-31');

    $mes = agostoRip();

    // Sin contrato no hay deber de asistencia que incumplir.
    expect($mes['sanciones'])->toBe([])
        ->and($mes['diasControlados'])->toBe(4)
        ->and($mes['diasApartados'])->toBe(1)
        ->and($mes['apartados'][0]['fecha']->toDateString())->toBe('2026-08-17')
        ->and($mes['apartados'][0]['motivo'])->toBe('Sin contrato vigente');
});

it('el hueco entre contratos no pega la racha de inasistencias', function () {
    asignarRip(turnoRip());
    conDosContratosRip();

    // Falta el 3 y el 10 (contrato viejo), el 17 no tiene contrato, y vuelve a
    // faltar el 24 (contrato nuevo).
    jornadaLimpiaRip('2026-08-31');

    $mes = agostoRip();

    $inasistencia = collect($mes['sanciones'])->firstWhere('tipo', EscalaRip::INASISTENCIA);

    // Tres jornadas, pero la racha más larga es de dos: el día sin contrato
    // corta la continuidad en vez de unir el 10 con el 24. Si uniera, serían
    // tres días continuos y el Art. 48 abriría proceso interno por abandono de
    // funciones contra alguien que estaba entre contrataciones.
    expect($mes['acumulado']['inasistenciaJornadas'])->toBe(3.0)
        ->and($mes['acumulado']['inasistenciaContinuos'])->toBe(2)
        ->and($inasistencia['procesoInterno'])->toBeFalse()
        ->and($inasistencia['articulo'])->toBe('46.IV')
        ->and($inasistencia['dias'])->toBe(6.0);

    // Dos jornadas al contrato de 4.500 y una al de 5.000:
    // 6 × 2/3 × 150 + 6 × 1/3 × 166,67 = 600 + 333,33.
    expect($inasistencia['monto'])->toBe(933.33);
});

it('un día apartado por turno roto tampoco pega la racha', function () {
    // Mismo principio que el hueco de contrato: un día demasiado roto para
    // calificarse no puede ser lo bastante bueno para unir dos inasistencias.
    asignarRip(turnoRip());

    // Solo el 17 queda con un turno de ventana de entrada invertida, que el
    // procesador marca «turno inválido».
    $hora = fn (string $hm): string => "1899-12-30 {$hm}:00";
    $roto = turnoRip(['eMinima' => $hora('09:00'), 'eMaxima' => $hora('07:00')]);

    AsignacionTurno::factory()->create([
        'ci' => CI_RIP,
        'turno_id' => $roto->id,
        'desde' => '2026-08-15 00:00:00',
        'hasta' => '2026-08-18 00:00:00',
    ]);

    jornadaLimpiaRip('2026-08-31');

    $mes = agostoRip();

    expect($mes['acumulado']['inasistenciaContinuos'])->toBeLessThan(3);
});

/**
 * Mamoré responde con un único contrato que cubre todo agosto.
 */
function conUnSoloContratoRip(float $sueldo = 5000): void
{
    fakeMamore([CI_RIP => [
        'nombre' => 'IGNACIO MOLINA GUZMAN',
        'cargo' => 'TECNICO II',
        'sueldo' => $sueldo,
        'bono' => null,
        'start' => '2026-01-01',
        'finish' => '2026-12-31',
    ]]);
}

it('con un solo contrato cobra los días de la escala al valor de ese sueldo', function () {
    asignarRip(turnoRip());
    conUnSoloContratoRip(5000);

    marcarRip('2026-08-03', '08:25', '16:05');   // 25 min
    marcarRip('2026-08-10', '08:20', '16:05');   // 20 min
    marcarRip('2026-08-17', '08:20', '16:05');   // 20 min
    jornadaLimpiaRip('2026-08-24');
    jornadaLimpiaRip('2026-08-31');

    $mes = agostoRip();

    // 65 minutos → Art. 45.I, tramo 61-90 → 2 días.
    // Un día son 5.000 ÷ 30 = 166,67 Bs, así que 2 días son 333,33.
    expect($mes['acumulado']['atrasoSegundos'])->toBe(65 * 60)
        ->and($mes['sanciones'][0]['dias'])->toBe(2.0)
        ->and($mes['sanciones'][0]['monto'])->toBe(333.33)
        ->and($mes['haber']['tramos'])->toHaveCount(1)
        ->and($mes['haber']['tramos'][0]['valorDia'])->toBe(5000 / 30);
});

it('medio día de descuento es la mitad de lo que gana en un día', function () {
    asignarRip(turnoRip());
    conUnSoloContratoRip(5000);

    marcarRip('2026-08-03', '08:25', '16:05');   // 25 min
    marcarRip('2026-08-10', '08:20', '16:05');   // 20 min
    jornadaLimpiaRip('2026-08-17');
    jornadaLimpiaRip('2026-08-24');
    jornadaLimpiaRip('2026-08-31');

    $mes = agostoRip();

    // 45 minutos → tramo 31-45 → medio día. La mitad de 166,67 es 83,33.
    expect($mes['acumulado']['atrasoSegundos'])->toBe(45 * 60)
        ->and($mes['sanciones'][0]['dias'])->toBe(0.5)
        ->and($mes['sanciones'][0]['monto'])->toBe(83.33)
        ->and($mes['totalMonto'])->toBe(83.33);
});

it('el valor del día no cambia porque el contrato cubra solo parte del mes', function () {
    asignarRip(turnoRip());

    // Contrato de 5.000 que se corta el 10 de agosto: cubre 10 días.
    fakeMamore([CI_RIP => [
        'nombre' => 'IGNACIO MOLINA GUZMAN',
        'cargo' => 'TECNICO II',
        'contratos' => [
            ['salary' => 5000, 'bonus' => null, 'start' => '2026-01-01', 'finish' => '2026-08-10'],
        ],
    ]]);

    marcarRip('2026-08-03', '08:25', '16:05');   // 25 min
    marcarRip('2026-08-10', '08:20', '16:05');   // 20 min

    $mes = agostoRip();

    // Cubre 10 días, pero el día sigue valiendo 5.000 ÷ 30 = 166,67 y no
    // 5.000 ÷ 10 = 500. Medio día son 83,33.
    expect($mes['haber']['tramos'][0]['dias'])->toBe(10)
        ->and($mes['haber']['tramos'][0]['valorDia'])->toBe(5000 / 30)
        ->and($mes['sanciones'][0]['dias'])->toBe(0.5)
        ->and($mes['sanciones'][0]['monto'])->toBe(83.33);

    // Del 11 en adelante no tiene contrato: esos días no se califican.
    expect($mes['diasControlados'])->toBe(2)
        ->and($mes['diasApartados'])->toBe(3);
});
