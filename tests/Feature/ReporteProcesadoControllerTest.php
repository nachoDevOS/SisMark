<?php

use App\Models\AsignacionTurno;
use App\Models\Asistencia;
use App\Models\Licencia;
use App\Models\Persona;
use App\Models\Turno;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(asSuperAdmin());
});

/**
 * Funcionario con el turno LUN 08:00–16:00 asignado y un lunes marcado con
 * atraso: entra 08:25 (tolerancia 08:10) y sale 16:50.
 */
function funcionarioProcesado(): Persona
{
    $hora = fn (string $hm): string => "1899-12-30 {$hm}:00";

    $persona = Persona::factory()->create([
        'ci' => '7633685',
        'paterno' => 'Molina',
        'materno' => 'Guzman',
        'nombres' => 'Ignacio',
        'pinReloj' => '7633685',
    ]);

    $turno = Turno::factory()->create([
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
    ]);

    AsignacionTurno::factory()->create([
        'ci' => $persona->ci,
        'turno_id' => $turno->id,
        'desde' => '2026-01-01 00:00:00',
        'hasta' => '2026-12-31 00:00:00',
    ]);

    foreach (['08:25:00', '16:50:00'] as $marca) {
        Asistencia::factory()->create([
            'ci' => $persona->ci,
            'fecha' => '2026-07-27',
            'hora' => "1899-12-30 {$marca}",
        ]);
    }

    return $persona;
}

/**
 * Parámetros del lunes de referencia.
 *
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function rangoProcesado(array $extra = []): array
{
    return array_merge([
        'persona' => '7633685',
        'desde' => '2026-07-27',
        'hasta' => '2026-07-27',
    ], $extra);
}

test('el formulario de selección carga', function () {
    $this->get(route('reportes.marcaciones.procesado'))
        ->assertOk()
        ->assertSee('Marcaciones procesadas');
});

test('generar muestra el día procesado con el atraso y las horas computadas', function () {
    funcionarioProcesado();

    $this->get(route('reportes.marcaciones.procesado.generar', rangoProcesado()))
        ->assertOk()
        // Columnas del reporte del escritorio viejo, con «Día» sumado adelante.
        ->assertSeeInOrder(['Fecha', 'Día', 'Turno', 'Entró', 'Salió', 'Atraso', 'Abandono', 'Falta',
            'Entrada lic.', 'Salida lic.', 'T.C.', 'C.G.H.', 'Motivo licencia'])
        ->assertSee('LUN: 08:00 - 16:00')
        ->assertSee('08:25:00')
        ->assertSee('16:50:00')
        ->assertSee('25 min')
        // Las horas del período no se muestran en pantalla: el cierre del
        // rango vive en el imprimible y en el Excel.
        ->assertDontSee('Horas computadas')
        ->assertDontSee('Saldo')
        ->assertDontSee('Salida anticipada');
});

test('la licencia por horas llena las columnas de licencia', function () {
    $persona = funcionarioProcesado();

    Licencia::factory()->porHoras('08:00', '11:00')->create([
        'ci' => $persona->ci,
        'fecha' => '2026-07-27',
        'turno_id' => Turno::query()->value('id'),
        'goceHaberes' => false,
        'motivo' => 'BAJA MEDICA',
    ]);

    $this->get(route('reportes.marcaciones.procesado.generar', rangoProcesado()))
        ->assertOk()
        ->assertSee('08:00')
        ->assertSee('11:00')
        ->assertSee('BAJA MEDICA')
        // T.C. = No (es por horas), C.G.H. = No (sin goce).
        ->assertSee('No');
});

test('la licencia de turno completo muestra el turno con T.C. en sí', function () {
    $persona = funcionarioProcesado();

    Licencia::factory()->create([
        'ci' => $persona->ci,
        'fecha' => '2026-07-27',
        'turno_id' => Turno::query()->value('id'),
        'tCompleto' => true,
        'goceHaberes' => true,
        'motivo' => 'VACACION',
    ]);

    $this->get(route('reportes.marcaciones.procesado.generar', rangoProcesado()))
        ->assertOk()
        ->assertSee('LUN: 08:00 - 16:00')
        ->assertSee('VACACION')
        ->assertSee('Sí')
        // La licencia manda: no se evalúan las marcas del día.
        ->assertDontSee('25 min');
});

test('el rango invertido se da vuelta en vez de salir vacío', function () {
    funcionarioProcesado();

    $this->get(route('reportes.marcaciones.procesado.generar', [
        'persona' => '7633685',
        'desde' => '2026-07-31',
        'hasta' => '2026-07-27',
    ]))
        ->assertOk()
        ->assertSee('LUN: 08:00 - 16:00');
});

test('el día sin turno asignado no aparece por ningún lado en pantalla', function () {
    funcionarioProcesado();

    // Domingo: el turno es de los lunes.
    $this->get(route('reportes.marcaciones.procesado.generar', rangoProcesado([
        'desde' => '2026-07-26', 'hasta' => '2026-07-26',
    ])))
        ->assertOk()
        ->assertSee('Sin días con turno asignado en el rango seleccionado.')
        // Ni fila ni conteo: el día que no se controla no aporta nada a la
        // pantalla. El imprimible sí lo conserva.
        ->assertDontSee('No laborable');
});

test('la tabla en pantalla lista solo los días con turno asignado', function () {
    funcionarioProcesado();

    // Sábado 25, domingo 26 y lunes 27: solo el lunes tiene turno.
    $this->get(route('reportes.marcaciones.procesado.generar', rangoProcesado([
        'desde' => '2026-07-25', 'hasta' => '2026-07-27',
    ])))
        ->assertOk()
        ->assertSee('27/07/2026')
        ->assertDontSee('25/07/2026')
        ->assertDontSee('26/07/2026')
        ->assertDontSee('No laborable');
});

test('el imprimible conserva los días no laborables', function () {
    funcionarioProcesado();

    $this->get(route('reportes.marcaciones.procesado.generar', rangoProcesado([
        'desde' => '2026-07-25', 'hasta' => '2026-07-27', 'print' => 1,
    ])))
        ->assertOk()
        // El imprimible formatea la fecha con `d/n/Y`, sin cero en el mes.
        ->assertSee('25/7/2026')
        ->assertSee('26/7/2026')
        ->assertSee('27/7/2026')
        ->assertSee('No laborable');
});

test('la lista en pantalla no repite los avisos de configuración del turno', function () {
    // El turno de la referencia tiene sMinima == sTolerancia (16:00), así que el
    // procesador levanta ese aviso en todos sus bloques.
    funcionarioProcesado();

    $this->get(route('reportes.marcaciones.procesado.generar', rangoProcesado()))
        ->assertOk()
        // El día se sigue viendo completo…
        ->assertSee('LUN: 08:00 - 16:00')
        ->assertSee('08:25:00')
        // …pero sin la advertencia repetida fila por fila.
        ->assertDontSee('Mínima hora de salida igual a la tolerancia');
});

test('generar con print=1 devuelve la versión imprimible', function () {
    funcionarioProcesado();

    $this->get(route('reportes.marcaciones.procesado.generar', rangoProcesado(['print' => 1])))
        ->assertOk()
        ->assertSee('REPORTE DE MARCACIONES')
        ->assertSee('Marcaciones procesadas')
        ->assertSeeText('PIN Reloj: 7633685')
        ->assertSee('Totales del rango:')
        ->assertSee('T.C.')
        ->assertSee('C.G.H.')
        ->assertSee('7h 35m');
});

test('generar con print=2 descarga un xlsx con la maqueta del imprimible', function () {
    funcionarioProcesado();

    $this->get(route('reportes.marcaciones.procesado.generar', rangoProcesado(['print' => 2])))
        ->assertOk()
        ->assertDownload('marcaciones-procesadas-7633685-'.now()->format('Y-m-d').'.xlsx');
});

/**
 * Lee el xlsx descargado y devuelve la hoja ya cargada, para poder afirmar
 * sobre celdas concretas en vez de sobre el binario.
 *
 * @param  array<string, mixed>  $extra
 */
function hojaDescargada(array $extra = []): Worksheet
{
    $response = test()->get(route('reportes.marcaciones.procesado.generar', rangoProcesado($extra + ['print' => 2])));

    $ruta = tempnam(sys_get_temp_dir(), 'sismark-test-').'.xlsx';
    file_put_contents($ruta, $response->streamedContent());

    $hoja = IOFactory::load($ruta)->getActiveSheet();

    unlink($ruta);

    return $hoja;
}

test('el xlsx trae la cabecera institucional y los datos del funcionario', function () {
    funcionarioProcesado();

    $hoja = hojaDescargada();

    expect($hoja->getCell('C1')->getValue())->toBe('GOBIERNO AUTONOMO DEPARTAMENTAL DEL BENI')
        ->and($hoja->getCell('C2')->getValue())->toBe('REPORTE DE MARCACIONES')
        ->and($hoja->getCell('C3')->getValue())->toBe('TRINIDAD')
        ->and($hoja->getCell('C4')->getValue())->toBe('Marcaciones procesadas')
        ->and($hoja->getCell('A6')->getValue())->toContain('PIN Reloj: 7633685')
        ->and($hoja->getCell('A6')->getValue())->toContain('desde el 27/7/2026');
});

test('el xlsx repite las 13 columnas del imprimible', function () {
    funcionarioProcesado();

    $hoja = hojaDescargada();
    $cabeceras = [];

    foreach (range('A', 'M') as $columna) {
        $cabeceras[] = $hoja->getCell($columna.'8')->getValue();
    }

    expect($cabeceras)->toBe(['Fecha', 'Día', 'Turno', 'Entró', 'Salió', 'Atraso', 'Abandono', 'Falta',
        'Entrada lic.', 'Salida lic.', 'T.C.', 'C.G.H.', 'Motivo licencia']);
});

test('el xlsx guarda las horas como texto, no como hora de Excel', function () {
    funcionarioProcesado();

    $hoja = hojaDescargada();

    // Fila 9: primera del cuerpo. Si Excel las tomara como hora, el valor
    // llegaría como fracción de día (0.35…) en vez del texto del reporte.
    expect($hoja->getCell('A9')->getValue())->toBe('27/7/2026')
        ->and($hoja->getCell('C9')->getValue())->toBe('LUN: 08:00 - 16:00')
        ->and($hoja->getCell('D9')->getValue())->toBe('08:25:00')
        ->and($hoja->getCell('E9')->getValue())->toBe('16:50:00')
        ->and($hoja->getCell('F9')->getValue())->toBe('25 min');
});

test('el xlsx cierra con los totales, el resumen y las firmas', function () {
    funcionarioProcesado();

    $hoja = hojaDescargada();
    $texto = [];

    foreach ($hoja->getRowIterator() as $fila) {
        foreach ($fila->getCellIterator() as $celda) {
            $texto[] = (string) $celda->getValue();
        }
    }

    $todo = implode("\n", $texto);

    expect($todo)->toContain('Totales del rango:')
        ->toContain('Horas computadas: 7h 35m de 8h 00m')
        ->toContain('Referencias:')
        ->toContain('Firma Responsable')
        ->toContain('Firma RR. HH.');
});

test('el xlsx combina fecha y día cuando el día no tiene turno', function () {
    funcionarioProcesado();

    // Sábado 25, domingo 26 y lunes 27: los dos primeros son «No laborable» y
    // ocupan las columnas del turno de punta a punta, como el colspan del print.
    $hoja = hojaDescargada(['desde' => '2026-07-25', 'hasta' => '2026-07-27']);

    expect($hoja->getCell('A9')->getValue())->toBe('25/7/2026')
        ->and($hoja->getCell('B9')->getValue())->toBe('Sábado')
        ->and($hoja->getCell('C9')->getValue())->toBe('No laborable')
        ->and($hoja->getMergeCells())->toHaveKey('C9:L9');
});

test('generar sin funcionario avisa dentro de la tabla', function () {
    // La tabla se pide por AJAX: el aviso vuelve como parcial. Redirigir haría
    // que `fetch` siguiera la redirección y dibujara la pantalla de selección
    // entera adentro del recuadro de la tabla.
    $this->get(route('reportes.marcaciones.procesado.generar'))
        ->assertOk()
        ->assertSee('Elegí un funcionario para generar el reporte.')
        ->assertDontSee('<!DOCTYPE html>', escape: false);
});

test('un usuario sin permiso no puede entrar', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('reportes.marcaciones.procesado'))->assertForbidden();
});

test('el reporte procesado oculta el encabezado del funcionario si se lo piden', function () {
    funcionarioProcesado();

    // Pantalla general: la tabla es lo único que hay, así que se identifica.
    $this->get(route('reportes.marcaciones.procesado.generar', rangoProcesado()))
        ->assertOk()
        ->assertSee('PIN reloj')
        ->assertSee('Rango:');

    // Dentro de la ficha del funcionario, quién es ya está arriba con foto y
    // datos completos: solo queda el rango y los botones.
    $this->get(route('reportes.marcaciones.procesado.generar', rangoProcesado(['encabezado' => 0])))
        ->assertOk()
        ->assertDontSee('PIN reloj')
        ->assertSee('Rango:')
        ->assertSee('Imprimir');
});
