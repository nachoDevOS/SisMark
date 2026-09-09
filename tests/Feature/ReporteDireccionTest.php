<?php

use App\Models\AsignacionTurno;
use App\Models\Asistencia;
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
    Persona::factory()->create(['ci' => $ci]);

    // `dia` va de 2 (lunes) a 6 (viernes): el domingo es 1.
    foreach (range(2, 6) as $dia) {
        AsignacionTurno::factory()->create([
            'ci' => $ci,
            'turno_id' => turnoDeOficina($dia)->id,
            'desde' => '2026-07-01 00:00:00',
            'hasta' => '2026-07-31 00:00:00',
        ]);
    }
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
