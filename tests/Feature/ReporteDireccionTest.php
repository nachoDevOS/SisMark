<?php

use App\Models\AsignacionTurno;
use App\Models\Asistencia;
use App\Models\DiaExcepcional;
use App\Models\Licencia;
use App\Models\Persona;
use App\Models\Turno;
use App\Services\ProcesadorAsistencia as P;
use App\Services\ReporteDireccion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Reporte de asistencia por dirección administrativa
|--------------------------------------------------------------------------
|
| Una fila por funcionario de la dirección con sus totales del rango. El
| reporte individual obliga a saber de antemano a quién mirar, así que el
| atraso de quien nadie pensó en consultar no aparecía nunca.
|
| Lo que decide quién entra es el **contrato dentro del rango**, no la planilla
| de hoy: quien se fue a mitad de mes trabajó esos días y marcó, y dejarlo
| afuera sería perderlo en silencio. Una persona puede tener más de un contrato
| en el mismo rango —una renovación, o un pase de dirección—, y el hueco entre
| dos no se controla.
|
*/

/** Id de la dirección que se reporta en estas pruebas. */
const DIRECCION = 7;

/**
 * Turno de lunes a viernes, 08:00 a 16:00 con diez minutos de tolerancia.
 */
function turnoDeOficina(int $dia): Turno
{
    $hora = fn (string $hm): string => "1899-12-30 {$hm}:00";

    return Turno::factory()->create([
        'dia' => (string) $dia,
        'nombreTurno' => '08:00 - 16:00',
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
    ]);
}

/**
 * Asigna el turno de oficina de toda la semana a una cédula, para julio de 2026.
 */
function conTurnoDeOficina(string $ci): void
{
    conTurnoDeOficinaHasta($ci, '2026-07-31');
}

/**
 * Lo mismo, pero eligiendo hasta cuándo rige: los casos de corte mensual
 * necesitan que el turno cubra más de un mes.
 */
function conTurnoDeOficinaHasta(string $ci, string $hasta): void
{
    conTurnoDeOficinaDesde($ci, '2026-07-01', $hasta);
}

/**
 * Turno con las dos puntas elegidas, para los casos que necesitan cubrir varios
 * meses y comprobar cuáles quedan afuera.
 */
function conTurnoDeOficinaDesde(string $ci, string $desde, string $hasta): void
{
    Persona::factory()->create(['ci' => $ci]);

    // `dia` va de 2 (lunes) a 6 (viernes): el domingo es 1.
    foreach (range(2, 6) as $dia) {
        AsignacionTurno::factory()->create([
            'ci' => $ci,
            'turno_id' => turnoDeOficina($dia)->id,
            'desde' => $desde.' 00:00:00',
            'hasta' => $hasta.' 00:00:00',
        ]);
    }
}

/**
 * El HTML sin espacios, para poder afirmar sobre una celda sin depender de cómo
 * quedó indentada la plantilla.
 */
function sinEspacios(string $html): string
{
    return (string) preg_replace('/\s+/', '', $html);
}

/**
 * Marca entrada y salida de una fecha.
 */
function marcaDe(string $ci, string $fecha, string $entrada, string $salida): void
{
    foreach ([[$entrada, 'E'], [$salida, 'S']] as [$hora, $tipo]) {
        Asistencia::factory()->create([
            'ci' => $ci,
            'fecha' => $fecha,
            'hora' => "1899-12-30 {$hora}:00",
            'tipo' => $tipo,
        ]);
    }
}

/**
 * @return Collection<int, array<string, mixed>>
 */
function filasDeJulio(): Collection
{
    return app(ReporteDireccion::class)->filas(
        DIRECCION,
        Carbon::parse('2026-07-01'),
        Carbon::parse('2026-07-31'),
    );
}

test('trae una fila por funcionario de la dirección, ordenadas por apellido', function () {
    fakeMamore([
        '111' => ['nombre' => 'ZUAZO PEREZ ANA', 'cargo' => 'TECNICO', 'direccion' => 'RRHH', 'direccion_id' => DIRECCION],
        '222' => ['nombre' => 'ARIAS LOPEZ JUAN', 'cargo' => 'TECNICO', 'direccion' => 'RRHH', 'direccion_id' => DIRECCION],
        // De otra dirección: no tiene que salir en el reporte.
        '333' => ['nombre' => 'CRUZ MAMANI LUZ', 'cargo' => 'TECNICO', 'direccion' => 'FIN', 'direccion_id' => 99],
    ]);

    conTurnoDeOficina('111');
    conTurnoDeOficina('222');
    conTurnoDeOficina('333');

    $filas = filasDeJulio();

    expect($filas)->toHaveCount(2)
        ->and($filas->pluck('persona.ci')->all())->toBe(['222', '111']);
});

test('quien no tuvo ningún contrato dentro del rango no genera fila', function () {
    // Pertenece a la dirección, pero su contrato terminó antes de julio: no hubo
    // jornada que controlar. Una fila en cero se leería como si hubiera cumplido
    // sin marcar nunca.
    fakeMamore([
        '111' => [
            'nombre' => 'VIEJO CONTRATO PEDRO', 'cargo' => 'TECNICO',
            'direccion' => 'RRHH', 'direccion_id' => DIRECCION,
            'contratos' => [['start' => '2025-01-01', 'finish' => '2025-12-31']],
        ],
        '222' => ['nombre' => 'ARIAS LOPEZ JUAN', 'cargo' => 'TECNICO', 'direccion' => 'RRHH', 'direccion_id' => DIRECCION],
    ]);

    conTurnoDeOficina('111');
    conTurnoDeOficina('222');

    expect(filasDeJulio()->pluck('persona.ci')->all())->toBe(['222']);
});

test('una persona con dos contratos en el rango los muestra los dos y no controla el hueco', function () {
    // Renovación con una semana de corte en el medio: del 13 al 17 de julio no
    // era funcionaria, así que esos días no pueden imputarse como falta.
    fakeMamore(['111' => [
        'nombre' => 'HIPAMO CHOLIMA MILTON', 'cargo' => 'TECNICO',
        'direccion' => 'RRHH', 'direccion_id' => DIRECCION,
        'contratos' => [
            ['start' => '2026-07-01', 'finish' => '2026-07-12'],
            ['start' => '2026-07-18', 'finish' => '2026-12-31'],
        ],
    ]]);

    conTurnoDeOficina('111');

    $fila = filasDeJulio()->first();

    expect($fila['tramos'])->toHaveCount(2)
        // Lunes 13, martes 14, miércoles 15, jueves 16 y viernes 17: cinco días
        // hábiles adentro del hueco, más nada de fin de semana.
        ->and($fila['totales']['porEstado'][P::SIN_CONTRATO] ?? 0)->toBe(5)
        // Y esos cinco no se cuentan como falta.
        ->and($fila['totales']['porEstado'][P::FALTA] ?? 0)->toBe(18);
});

test('los totales de la fila cuentan atrasos y horas de quien marcó', function () {
    fakeMamore(['111' => [
        'nombre' => 'ARIAS LOPEZ JUAN', 'cargo' => 'TECNICO',
        'direccion' => 'RRHH', 'direccion_id' => DIRECCION,
        'contratos' => [['start' => '2026-07-01', 'finish' => '2026-12-31']],
    ]]);

    conTurnoDeOficina('111');

    // Miércoles 1: dentro de la tolerancia, cumple. Jueves 2: once minutos tarde.
    marcaDe('111', '2026-07-01', '08:09', '16:05');
    marcaDe('111', '2026-07-02', '08:11', '16:05');

    $totales = filasDeJulio()->first()['totales'];

    expect($totales['porEstado'][P::CUMPLE] ?? 0)->toBe(1)
        ->and($totales['porEstado'][P::ATRASO] ?? 0)->toBe(1)
        ->and($totales['atraso'])->toBe(11 * 60)
        // Las horas se acotan al turno: llegar dentro de la tolerancia cuenta
        // como llegar a la hora, y quedarse de más no suma.
        ->and($totales['computado'])->toBe(8 * 3600 + (8 * 3600 - 11 * 60));
});

test('el cierre de la dirección suma las filas', function () {
    fakeMamore([
        '111' => ['nombre' => 'ARIAS LOPEZ JUAN', 'cargo' => 'TECNICO', 'direccion' => 'RRHH', 'direccion_id' => DIRECCION],
        '222' => ['nombre' => 'BENITEZ SOSA EVA', 'cargo' => 'TECNICO', 'direccion' => 'RRHH', 'direccion_id' => DIRECCION],
    ]);

    conTurnoDeOficina('111');
    conTurnoDeOficina('222');

    marcaDe('111', '2026-07-01', '08:11', '16:05');
    marcaDe('222', '2026-07-01', '08:20', '16:05');

    $reporte = app(ReporteDireccion::class);
    $filas = filasDeJulio();
    $totales = $reporte->totales($filas);

    expect($totales['funcionarios'])->toBe(2)
        ->and($totales['porEstado'][P::ATRASO])->toBe(2)
        ->and($totales['atraso'])->toBe((11 + 20) * 60)
        ->and($totales['dias'])->toBe($filas->sum(fn (array $fila): int => $fila['totales']['dias']));
});

/**
 * Padrón de una sola persona dentro de la dirección que se reporta. `$extra`
 * pisa lo que haga falta —los contratos, sobre todo—.
 *
 * @param  array<string, mixed>  $extra
 */
function padronDeRrhh(array $extra = []): void
{
    fakeMamore(['111' => $extra + [
        'nombre' => 'ARIAS LOPEZ JUAN',
        'cargo' => 'TECNICO',
        'direccion' => 'RRHH',
        'direccion_id' => DIRECCION,
    ]]);
}

/**
 * El turno que le toca a una cédula ese día de la semana (1 = domingo), para
 * colgarle una licencia: la columna `turno_id` no acepta nulos.
 */
function turnoDelDia(string $ci, int $dia): Turno
{
    return AsignacionTurno::query()
        ->with('turno')
        ->where('ci', $ci)
        ->get()
        ->pluck('turno')
        ->first(fn (Turno $turno): bool => (int) $turno->dia === $dia);
}

/**
 * Los totales de la misma persona por los dos caminos: la fila del reporte por
 * dirección y el reporte individual.
 *
 * @return array{0: ?array<string, mixed>, 1: array<string, mixed>}
 */
function losDosCaminos(string $ci, string $desde, string $hasta): array
{
    $fila = app(ReporteDireccion::class)
        ->filas(DIRECCION, Carbon::parse($desde), Carbon::parse($hasta))
        ->firstWhere('persona.ci', $ci);

    $procesador = app(P::class);

    return [
        $fila === null ? null : $fila['totales'],
        $procesador->totales($procesador->procesar($ci, Carbon::parse($desde), Carbon::parse($hasta))),
    ];
}

test('la fila de la dirección dice lo mismo que el reporte individual de esa persona', function (Closure $escenario) {
    // Es la pregunta de fondo del reporte: mirar la dirección entera no puede dar
    // un número distinto que mirar a esa persona sola. Los dos caminos consultan
    // los contratos por endpoints distintos —uno por cédula, el otro por lote—,
    // así que la coincidencia hay que probarla caso por caso y no suponerla del
    // hecho de que abajo compartan el procesador.
    [$desde, $hasta] = $escenario();

    [$porDireccion, $individual] = losDosCaminos('111', $desde, $hasta);

    expect($porDireccion)->toBe($individual);
})->with([
    'sin ninguna marca en todo el mes' => [function (): array {
        padronDeRrhh();
        conTurnoDeOficina('111');

        return ['2026-07-01', '2026-07-31'];
    }],

    'cumple, atraso y salida anticipada' => [function (): array {
        padronDeRrhh();
        conTurnoDeOficina('111');
        marcaDe('111', '2026-07-01', '08:09', '16:05');
        marcaDe('111', '2026-07-02', '08:11', '16:05');
        marcaDe('111', '2026-07-03', '08:05', '15:30');

        return ['2026-07-01', '2026-07-31'];
    }],

    'una sola punta: marcó la entrada y no la salida' => [function (): array {
        padronDeRrhh();
        conTurnoDeOficina('111');
        Asistencia::factory()->create([
            'ci' => '111', 'fecha' => '2026-07-01', 'hora' => '1899-12-30 08:02:00', 'tipo' => 'E',
        ]);

        return ['2026-07-01', '2026-07-31'];
    }],

    'rebotes del reloj: la misma marca repetida' => [function (): array {
        padronDeRrhh();
        conTurnoDeOficina('111');

        foreach ([['08:11:00', 'E'], ['08:11:30', 'E'], ['08:12:10', 'E'], ['16:05:00', 'S']] as [$hora, $tipo]) {
            Asistencia::factory()->create([
                'ci' => '111', 'fecha' => '2026-07-01', 'hora' => "1899-12-30 {$hora}", 'tipo' => $tipo,
            ]);
        }

        return ['2026-07-01', '2026-07-31'];
    }],

    'licencia de turno completo' => [function (): array {
        padronDeRrhh();
        conTurnoDeOficina('111');
        Licencia::factory()->create([
            'ci' => '111', 'fecha' => '2026-07-06 00:00:00',
            'turno_id' => turnoDelDia('111', 2)->id,
            'tCompleto' => true, 'motivo' => 'VACACIÓN',
        ]);

        return ['2026-07-01', '2026-07-31'];
    }],

    'licencia por horas que tapa la entrada' => [function (): array {
        padronDeRrhh();
        conTurnoDeOficina('111');
        Licencia::factory()->porHoras('08:00', '11:00')->create([
            'ci' => '111', 'fecha' => '2026-07-06 00:00:00',
            'turno_id' => turnoDelDia('111', 2)->id,
        ]);
        marcaDe('111', '2026-07-06', '11:05', '16:05');

        return ['2026-07-01', '2026-07-31'];
    }],

    'licencia por horas que deja un hueco sin marcar' => [function (): array {
        padronDeRrhh();
        conTurnoDeOficina('111');
        Licencia::factory()->porHoras('08:05', '11:00')->create([
            'ci' => '111', 'fecha' => '2026-07-06 00:00:00',
            'turno_id' => turnoDelDia('111', 2)->id,
        ]);
        Asistencia::factory()->create([
            'ci' => '111', 'fecha' => '2026-07-06', 'hora' => '1899-12-30 16:25:00', 'tipo' => 'S',
        ]);

        return ['2026-07-01', '2026-07-31'];
    }],

    'día excepcional en medio del mes' => [function (): array {
        padronDeRrhh();
        conTurnoDeOficina('111');
        DiaExcepcional::factory()->create([
            'fecha' => '2026-07-07 00:00:00', 'motivoInasistencia' => 'CARNAVAL',
        ]);
        marcaDe('111', '2026-07-07', '08:30', '16:05');

        return ['2026-07-01', '2026-07-31'];
    }],

    'dos contratos con un hueco en el medio' => [function (): array {
        padronDeRrhh(['contratos' => [
            ['start' => '2026-07-01', 'finish' => '2026-07-12'],
            ['start' => '2026-07-18', 'finish' => '2026-12-31'],
        ]]);
        conTurnoDeOficina('111');
        marcaDe('111', '2026-07-15', '08:30', '16:05');

        return ['2026-07-01', '2026-07-31'];
    }],

    'contrato que empieza después del inicio del rango' => [function (): array {
        padronDeRrhh(['contratos' => [['start' => '2026-07-15', 'finish' => '2026-12-31']]]);
        conTurnoDeOficina('111');
        marcaDe('111', '2026-07-20', '08:11', '16:05');

        return ['2026-07-01', '2026-07-31'];
    }],

    'rango que cruza dos meses' => [function (): array {
        padronDeRrhh(['contratos' => [['start' => '2026-01-01', 'finish' => '2026-12-31']]]);
        conTurnoDeOficinaHasta('111', '2026-08-31');
        marcaDe('111', '2026-07-02', '08:11', '16:05');
        marcaDe('111', '2026-08-04', '08:20', '16:05');

        return ['2026-07-01', '2026-08-31'];
    }],

    'dos turnos el mismo día, uno cumplido y el otro no' => [function (): array {
        padronDeRrhh();
        conTurnoDeOficina('111');

        // Turno de la tarde pegado al de oficina: el lunes queda partido.
        $hora = fn (string $hm): string => "1899-12-30 {$hm}:00";
        $tarde = Turno::factory()->create([
            'dia' => '2', 'nombreTurno' => '18:00 - 22:00',
            'hEntrada' => $hora('18:00'), 'hTolerancia' => $hora('18:05'),
            'eMinima' => $hora('17:30'), 'eMaxima' => $hora('19:00'),
            'hSalida' => $hora('22:00'), 'sTolerancia' => $hora('22:00'),
            'sMinima' => $hora('22:00'), 'sMaxima' => $hora('23:30'),
            'hTrabajadas' => 4, 'siguienteDia' => false,
        ]);
        AsignacionTurno::factory()->create([
            'ci' => '111', 'turno_id' => $tarde->id,
            'desde' => '2026-07-01 00:00:00', 'hasta' => '2026-07-31 00:00:00',
        ]);

        // Llega tarde a la mañana y no marca nada del turno de la tarde.
        marcaDe('111', '2026-07-06', '08:15', '16:05');

        return ['2026-07-01', '2026-07-31'];
    }],
]);

test('la pantalla lista a los funcionarios de la dirección elegida', function () {
    fakeMamore(['111' => [
        'nombre' => 'ARIAS LOPEZ JUAN', 'cargo' => 'TECNICO',
        'direccion' => 'RRHH', 'direccion_id' => DIRECCION,
    ]]);

    conTurnoDeOficina('111');

    $this->actingAs(usuarioCon(['ViewAny:Reporte']))
        ->get(route('reportes.marcaciones.direccion.generar', [
            'direccion' => DIRECCION,
            'nombre' => 'RRHH — Recursos Humanos',
            'desde' => '2026-07-01',
            'hasta' => '2026-07-31',
        ]))
        ->assertOk()
        ->assertSee('ARIAS LOPEZ JUAN')
        ->assertSee('RRHH — Recursos Humanos');
});

test('la tabla muestra los minutos acumulados de atraso y ya no horas ni saldo', function () {
    // Lo que se mira de una dirección son los incumplimientos acumulados por
    // persona. «Días» y «Cumple» decían cuántos días salieron bien, que es el
    // caso normal y no el que se revisa; «Computado» y «Saldo» son lectura de
    // una jornada, no de un mes de una dirección entera.
    fakeMamore(['111' => [
        'nombre' => 'ARIAS LOPEZ JUAN', 'cargo' => 'TECNICO',
        'direccion' => 'RRHH', 'direccion_id' => DIRECCION,
    ]]);

    conTurnoDeOficina('111');

    // Once y veinte minutos tarde: dos atrasos, treinta y un minutos.
    marcaDe('111', '2026-07-01', '08:11', '16:05');
    marcaDe('111', '2026-07-02', '08:20', '16:05');

    $tabla = $this->actingAs(usuarioCon(['ViewAny:Reporte']))
        ->get(route('reportes.marcaciones.direccion.generar', [
            'direccion' => DIRECCION,
            'nombre' => 'RRHH',
            'desde' => '2026-07-01',
            'hasta' => '2026-07-31',
        ]))
        ->assertOk()
        ->assertSee('Minutos acumulados')
        ->assertDontSee('Computado')
        ->assertDontSee('Saldo')
        ->assertDontSee('Cumple');

    // El total de minutos sale como número, no como «31 min» ni como «0h 31m».
    expect(sinEspacios($tabla->getContent()))->toContain('>31<');
});

test('los minutos no se suman entre meses: el rango se abre por mes', function () {
    // La tolerancia y la escala se miden sobre el mes calendario. Doce minutos en
    // julio y diez en agosto **no son veintidós**, así que un total del rango no
    // significaría nada y encima se leería como si significara algo.
    fakeMamore(['111' => [
        'nombre' => 'ARIAS LOPEZ JUAN', 'cargo' => 'TECNICO',
        'direccion' => 'RRHH', 'direccion_id' => DIRECCION,
    ]]);

    conTurnoDeOficinaHasta('111', '2026-08-31');

    // Doce minutos en julio, quince en agosto. No sirve «08:10»: ese minuto es
    // la tolerancia y queda entero adentro, así que no hay atraso.
    marcaDe('111', '2026-07-01', '08:12', '16:05');
    marcaDe('111', '2026-08-03', '08:15', '16:05');

    $contenido = sinEspacios($this->actingAs(usuarioCon(['ViewAny:Reporte']))
        ->get(route('reportes.marcaciones.direccion.generar', [
            'direccion' => DIRECCION,
            'nombre' => 'RRHH',
            'desde' => '2026-07-01',
            'hasta' => '2026-08-31',
        ]))
        ->assertOk()
        ->assertSee('Jul 2026')
        ->assertSee('Ago 2026')
        ->getContent());

    expect($contenido)
        // Cada mes con lo suyo…
        ->toContain('>12<')
        ->toContain('>15<')
        // …y en ningún lado la suma.
        ->not->toContain('>27<');
});

test('solo salen los meses que algún contrato cubre', function () {
    // Trabajó de enero a abril: mayo no está en cero, no existió para esa
    // persona. Una fila vacía se lee como un mes sin novedad.
    fakeMamore(['111' => [
        'nombre' => 'BARBA NOE ANDONI', 'cargo' => 'TECNICO',
        'direccion' => 'RRHH', 'direccion_id' => DIRECCION,
        'contratos' => [['start' => '2026-01-12', 'finish' => '2026-04-30']],
    ]]);

    conTurnoDeOficinaDesde('111', '2026-01-01', '2026-09-30');

    $meses = app(ReporteDireccion::class)->filas(
        DIRECCION,
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-09-09'),
    )->first()['meses'];

    expect(collect($meses)->pluck('etiqueta')->all())
        ->toBe(['Ene 2026', 'Feb 2026', 'Mar 2026', 'Abr 2026']);
});

test('el hueco entre dos contratos tampoco genera mes', function () {
    // Dos contratos con mayo entero en el medio: ese mes no se controla, así que
    // no tiene fila.
    fakeMamore(['111' => [
        'nombre' => 'GORIANZ GUTIERREZ MILTON', 'cargo' => 'TECNICO',
        'direccion' => 'RRHH', 'direccion_id' => DIRECCION,
        'contratos' => [
            ['start' => '2026-03-01', 'finish' => '2026-04-30'],
            ['start' => '2026-06-01', 'finish' => '2026-07-31'],
        ],
    ]]);

    conTurnoDeOficinaDesde('111', '2026-01-01', '2026-09-30');

    $meses = app(ReporteDireccion::class)->filas(
        DIRECCION,
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-09-09'),
    )->first()['meses'];

    expect(collect($meses)->pluck('etiqueta')->all())
        ->toBe(['Mar 2026', 'Abr 2026', 'Jun 2026', 'Jul 2026']);
});

test('un mes con contrato sale aunque no tenga nada que reportar', function () {
    // Acá el cero sí es información: estuvo y no tuvo incumplimientos. Es lo que
    // lo distingue del mes que no existió.
    fakeMamore(['111' => [
        'nombre' => 'ARIAS LOPEZ JUAN', 'cargo' => 'TECNICO',
        'direccion' => 'RRHH', 'direccion_id' => DIRECCION,
        'contratos' => [['start' => '2026-07-01', 'finish' => '2026-08-31']],
    ]]);

    conTurnoDeOficinaHasta('111', '2026-08-31');

    $meses = app(ReporteDireccion::class)->filas(
        DIRECCION,
        Carbon::parse('2026-07-01'),
        Carbon::parse('2026-08-31'),
    )->first()['meses'];

    expect(collect($meses)->pluck('etiqueta')->all())->toBe(['Jul 2026', 'Ago 2026']);
});

test('dentro de un mismo mes la tabla no se abre por mes', function () {
    // El total del rango ya es mensual: una fila por persona alcanza y agregar
    // una fila «Jul 2026» que repite los mismos números solo estorba.
    fakeMamore(['111' => [
        'nombre' => 'ARIAS LOPEZ JUAN', 'cargo' => 'TECNICO',
        'direccion' => 'RRHH', 'direccion_id' => DIRECCION,
    ]]);

    conTurnoDeOficina('111');
    marcaDe('111', '2026-07-01', '08:12', '16:05');

    $this->actingAs(usuarioCon(['ViewAny:Reporte']))
        ->get(route('reportes.marcaciones.direccion.generar', [
            'direccion' => DIRECCION,
            'nombre' => 'RRHH',
            'desde' => '2026-07-01',
            'hasta' => '2026-07-31',
        ]))
        ->assertOk()
        ->assertDontSee('Jul 2026')
        ->assertSee('Minutos acumulados');
});

test('el servicio devuelve los totales de cada mes por separado', function () {
    fakeMamore(['111' => [
        'nombre' => 'ARIAS LOPEZ JUAN', 'cargo' => 'TECNICO',
        'direccion' => 'RRHH', 'direccion_id' => DIRECCION,
    ]]);

    conTurnoDeOficinaHasta('111', '2026-08-31');

    marcaDe('111', '2026-07-01', '08:12', '16:05');
    marcaDe('111', '2026-08-03', '08:15', '16:05');

    $meses = app(ReporteDireccion::class)->filas(
        DIRECCION,
        Carbon::parse('2026-07-01'),
        Carbon::parse('2026-08-31'),
    )->first()['meses'];

    expect($meses)->toHaveCount(2)
        ->and($meses[0]['etiqueta'])->toBe('Jul 2026')
        ->and($meses[0]['totales']['atraso'])->toBe(12 * 60)
        ->and($meses[1]['etiqueta'])->toBe('Ago 2026')
        ->and($meses[1]['totales']['atraso'])->toBe(15 * 60);
});

test('el día con una sola marca cuenta como falta y no tiene columna propia', function () {
    // «Sin marca» se retiró a pedido: quien marcó una sola punta cuenta igual
    // que quien no vino. Se deja fijado para que el día con media marca no se
    // pierda de la tabla al no tener columna donde caer.
    fakeMamore(['111' => [
        'nombre' => 'ARIAS LOPEZ JUAN', 'cargo' => 'TECNICO',
        'direccion' => 'RRHH', 'direccion_id' => DIRECCION,
    ]]);

    conTurnoDeOficina('111');

    // Miércoles 1: entra y sale, cumple. Jueves 2: entra y nunca marca salida.
    marcaDe('111', '2026-07-01', '08:00', '16:05');
    Asistencia::factory()->create([
        'ci' => '111', 'fecha' => '2026-07-02',
        'hora' => '1899-12-30 08:00:00', 'tipo' => 'E',
    ]);

    $fila = filasDeJulio()->first();
    $porEstado = $fila['totales']['porEstado'];

    // El procesador los sigue distinguiendo: es la tabla la que los junta.
    expect($porEstado[P::SIN_SALIDA] ?? 0)->toBe(1)
        ->and($porEstado[P::CUMPLE] ?? 0)->toBe(1);

    $this->actingAs(usuarioCon(['ViewAny:Reporte']))
        ->get(route('reportes.marcaciones.direccion.generar', [
            'direccion' => DIRECCION,
            'nombre' => 'RRHH',
            'desde' => '2026-07-01',
            'hasta' => '2026-07-31',
        ]))
        ->assertOk()
        ->assertDontSee('Sin marca')
        // 21 días hábiles menos el que cumplió: 19 sin marcar nada más el de la
        // media marca.
        ->assertSee('Faltas');

    expect(ReporteDireccion::contar($porEstado, [
        P::FALTA, P::SIN_ENTRADA, P::SIN_SALIDA, P::TURNO_INVALIDO,
    ]))->toBe(($porEstado[P::FALTA] ?? 0) + 1);
});

test('el imprimible sale con la hoja entera y el cierre de la dirección', function () {
    fakeMamore(['111' => [
        'nombre' => 'ARIAS LOPEZ JUAN', 'cargo' => 'TECNICO',
        'direccion' => 'RRHH', 'direccion_id' => DIRECCION,
    ]]);

    conTurnoDeOficina('111');
    marcaDe('111', '2026-07-01', '08:11', '16:05');

    $this->actingAs(usuarioCon(['ViewAny:Reporte']))
        ->get(route('reportes.marcaciones.direccion.generar', [
            'direccion' => DIRECCION,
            'nombre' => 'RRHH — Recursos Humanos',
            'desde' => '2026-07-01',
            'hasta' => '2026-07-31',
            'print' => 1,
        ]))
        ->assertOk()
        ->assertSee('GOBIERNO AUTONOMO DEPARTAMENTAL DEL BENI')
        ->assertSee('RRHH — Recursos Humanos')
        ->assertSee('ARIAS LOPEZ JUAN')
        ->assertSee('Totales de la dirección', false);
});

test('el reporte por dirección exige el permiso de reportes', function () {
    fakeMamore();

    $ruta = route('reportes.marcaciones.direccion');

    $this->actingAs(usuarioCon(['ViewAny:Asistencia']))->get($ruta)->assertForbidden();
    $this->actingAs(usuarioCon(['ViewAny:Reporte']))->get($ruta)->assertOk();
});

test('sin dirección elegida avisa en vez de procesar la Gobernación entera', function () {
    fakeMamore();

    $this->actingAs(usuarioCon(['ViewAny:Reporte']))
        ->get(route('reportes.marcaciones.direccion.generar', ['desde' => '2026-07-01', 'hasta' => '2026-07-31']))
        ->assertOk()
        ->assertSee('Elegí una dirección');
});

test('si Mamoré no responde, el reporte no se emite', function () {
    // Igual que el individual: sin poder verificar los contratos no se sanciona
    // a ciegas. Acá pesa más todavía, porque los contratos deciden además quién
    // entra en la lista.
    //
    // La API se configura a mano en vez de con `fakeMamore()`: sus stubs se
    // registran primero y ganan, así que un `Http::fake()` posterior no llega a
    // atender ningún pedido.
    config()->set('services.mamore.url', 'http://mamore.test/api/externo/personal');
    config()->set('services.mamore.token', 'secreta');
    Http::fake(['mamore.test/*' => Http::response('', 500)]);

    $this->actingAs(usuarioCon(['ViewAny:Reporte']))
        ->get(route('reportes.marcaciones.direccion.generar', [
            'direccion' => DIRECCION,
            'desde' => '2026-07-01',
            'hasta' => '2026-07-31',
        ]))
        ->assertOk()
        ->assertSee('no se generó');
});

test('el combo de direcciones sale con cuánta gente tiene cada una', function () {
    fakeMamore([
        '111' => ['nombre' => 'ARIAS LOPEZ JUAN', 'cargo' => 'TECNICO', 'direccion' => 'RRHH', 'direccion_id' => DIRECCION],
        '222' => ['nombre' => 'BENITEZ SOSA EVA', 'cargo' => 'TECNICO', 'direccion' => 'RRHH', 'direccion_id' => DIRECCION],
        '333' => ['nombre' => 'CRUZ MAMANI LUZ', 'cargo' => 'TECNICO', 'direccion' => 'FIN', 'direccion_id' => 99],
    ]);

    $respuesta = $this->actingAs(usuarioCon(['ViewAny:Reporte']))
        ->getJson(route('reportes.marcaciones.direcciones', ['desde' => '2026-07-01', 'hasta' => '2026-07-31']))
        ->assertOk()
        ->json();

    expect(collect($respuesta['direcciones'])->firstWhere('id', DIRECCION))
        ->toMatchArray(['texto' => 'RRHH — RRHH', 'funcionarios' => 2]);
});

test('el combo trae las unidades colgadas de su dirección', function () {
    fakeMamore([
        '111' => [
            'nombre' => 'ARIAS LOPEZ JUAN', 'cargo' => 'TECNICO',
            'direccion' => 'RRHH', 'direccion_id' => DIRECCION,
            'unidad' => 'PLANILLAS', 'unidad_id' => 41,
        ],
        // De otra dirección: su unidad no puede ofrecerse cuando se elige RRHH.
        '222' => [
            'nombre' => 'CRUZ MAMANI LUZ', 'cargo' => 'TECNICO',
            'direccion' => 'FIN', 'direccion_id' => 99,
            'unidad' => 'TESORERIA', 'unidad_id' => 88,
        ],
    ]);

    $combo = $this->actingAs(usuarioCon(['ViewAny:Reporte']))
        ->getJson(route('reportes.marcaciones.direcciones', ['desde' => '2026-07-01', 'hasta' => '2026-07-31']))
        ->assertOk()
        ->json();

    $deLaDireccion = collect($combo['unidades'])->where('direccionId', DIRECCION)->values();

    expect($deLaDireccion)->toHaveCount(1)
        ->and($deLaDireccion[0])->toMatchArray([
            'id' => 41,
            'texto' => 'PLANILLAS — PLANILLAS',
            'funcionarios' => 1,
        ]);
});

test('elegir una unidad acota el reporte a esa unidad', function () {
    fakeMamore([
        '111' => [
            'nombre' => 'ARIAS LOPEZ JUAN', 'cargo' => 'TECNICO',
            'direccion' => 'RRHH', 'direccion_id' => DIRECCION,
            'unidad' => 'PLANILLAS', 'unidad_id' => 41,
        ],
        '222' => [
            'nombre' => 'BENITEZ SOSA EVA', 'cargo' => 'TECNICO',
            'direccion' => 'RRHH', 'direccion_id' => DIRECCION,
            'unidad' => 'SELECCION', 'unidad_id' => 42,
        ],
    ]);

    conTurnoDeOficina('111');
    conTurnoDeOficina('222');

    $reporte = app(ReporteDireccion::class);
    $desde = Carbon::parse('2026-07-01');
    $hasta = Carbon::parse('2026-07-31');

    // Sin unidad entra la dirección entera; con una, solo la suya.
    expect($reporte->filas(DIRECCION, $desde, $hasta)->pluck('persona.ci')->all())
        ->toBe(['111', '222'])
        ->and($reporte->filas(DIRECCION, $desde, $hasta, 41)->pluck('persona.ci')->all())
        ->toBe(['111']);
});

test('la pantalla dice si es la dirección entera o una unidad', function () {
    // Un encabezado que solo nombra la dirección deja sin saber si la hoja cubre
    // toda la dirección o una parte.
    fakeMamore(['111' => [
        'nombre' => 'ARIAS LOPEZ JUAN', 'cargo' => 'TECNICO',
        'direccion' => 'RRHH', 'direccion_id' => DIRECCION,
        'unidad' => 'PLANILLAS', 'unidad_id' => 41,
    ]]);

    conTurnoDeOficina('111');

    $usuario = usuarioCon(['ViewAny:Reporte']);
    $comun = ['direccion' => DIRECCION, 'nombre' => 'RRHH', 'desde' => '2026-07-01', 'hasta' => '2026-07-31'];

    $this->actingAs($usuario)
        ->get(route('reportes.marcaciones.direccion.generar', $comun))
        ->assertOk()
        ->assertSee('Todas las unidades');

    $this->actingAs($usuario)
        ->get(route('reportes.marcaciones.direccion.generar', $comun + [
            'unidad' => 41,
            'nombreUnidad' => 'PLANILLAS — Unidad de Planillas',
        ]))
        ->assertOk()
        ->assertSee('PLANILLAS — Unidad de Planillas')
        ->assertDontSee('Todas las unidades');
});

test('el combo cuenta funcionarios y no contratos', function () {
    // Una renovación a mitad del rango son dos contratos de una sola persona.
    // Contando contratos, el combo decía «(2)» arriba de una tabla de una fila.
    fakeMamore(['111' => [
        'nombre' => 'ARTEAGA VARGAS FABIANNA', 'cargo' => 'TECNICO',
        'direccion' => 'RRHH', 'direccion_id' => DIRECCION,
        'contratos' => [
            ['start' => '2026-03-04', 'finish' => '2026-07-02'],
            ['start' => '2026-07-13', 'finish' => '2026-11-18'],
        ],
    ]]);

    conTurnoDeOficina('111');

    $combo = $this->actingAs(usuarioCon(['ViewAny:Reporte']))
        ->getJson(route('reportes.marcaciones.direcciones', ['desde' => '2026-07-01', 'hasta' => '2026-07-31']))
        ->assertOk()
        ->json();

    expect(collect($combo['direcciones'])->firstWhere('id', DIRECCION)['funcionarios'])
        ->toBe(1)
        ->toBe(filasDeJulio()->count());
});
