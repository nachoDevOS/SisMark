<?php

use App\Services\ExcelReporteDireccion;
use App\Services\ProcesadorAsistencia as P;
use App\Services\ReporteDireccion;
use App\Services\ReporteDireccion as R;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Totales con la forma de {@see P::totales()}.
 *
 * @param  array<string, int>  $porEstado
 * @return array<string, mixed>
 */
function totalesDeDireccion(int $minutosDeAtraso, array $porEstado): array
{
    return ['dias' => 10, 'atraso' => $minutosDeAtraso * 60, 'anticipo' => 0, 'computado' => 0, 'esperado' => 0, 'saldo' => 0, 'porEstado' => $porEstado];
}

/**
 * Una fila con la forma de {@see ReporteDireccion::filas()}.
 *
 * @param  list<array<string, mixed>>  $meses
 * @return array<string, mixed>
 */
function filaDeDireccion(string $ci, string $nombre, array $totales, array $meses = []): array
{
    return [
        'persona' => ['ci' => $ci, 'nombre' => $nombre, 'nombreFormal' => $nombre],
        'tramos' => [['desde' => Carbon::parse('2026-01-05'), 'hasta' => Carbon::parse('2026-11-30'), 'direccion' => 'SDAF', 'cargo' => null]],
        'totales' => $totales,
        'meses' => $meses,
    ];
}

function hojaDeDireccion(bool $cruzaMeses): Worksheet
{
    $excel = app(ExcelReporteDireccion::class);

    $filas = collect([
        filaDeDireccion('10830573', 'MEJIA MUIBA AGUSTIN', totalesDeDireccion(14, [P::ATRASO => 1, P::FALTA => 1, P::LICENCIA => 1]), [
            ['clave' => '2026-09', 'etiqueta' => 'Septiembre 2026', 'totales' => totalesDeDireccion(5, [P::ATRASO => 1])],
            ['clave' => '2026-10', 'etiqueta' => 'Octubre 2026', 'totales' => totalesDeDireccion(9, [P::FALTA => 1, P::LICENCIA => 1])],
        ]),
        filaDeDireccion('7635051', 'ALCOCER MALUE FERNANDO', totalesDeDireccion(0, [P::SIN_SALIDA => 2])),
    ]);

    $totales = ['funcionarios' => 2, 'atraso' => 14 * 60, 'porEstado' => [P::ATRASO => 1, P::FALTA => 1, P::SIN_SALIDA => 2, P::LICENCIA => 1]];

    $ruta = $excel->generar($filas, $totales, 'SDAF — Secretaria Departamental de Administracion y Finanzas', 'Todas las unidades', '2026-10-01', '2026-10-15', $cruzaMeses, R::alcance(12, 2, 'falta'));

    expect($excel->nombreArchivo('SDAF — Secretaria Departamental'))->toStartWith('marcaciones-direccion-sdaf-');

    $hoja = IOFactory::load($ruta)->getActiveSheet();
    unlink($ruta);

    return $hoja;
}

test('el excel por dirección trae el encabezado, una fila por funcionario y el total', function (): void {
    $hoja = hojaDeDireccion(false);

    expect($hoja->getCell('A6')->getValue())->toContain('SDAF — Secretaria Departamental de Administracion y Finanzas')
        ->and($hoja->getCell('A7')->getValue())->toBe('Unidad: Todas las unidades')
        ->and($hoja->getCell('A8')->getValue())->toBe('12 funcionario(s) con contrato en el rango · se muestran 2 con falta')
        ->and($hoja->rangeToArray('A10:I10')[0])->toBe(['N°', 'Funcionario', 'CI', 'Contrato(s) en el rango', 'Minutos acumulados', 'Atrasos', 'Abandonos', 'Faltas', 'Licencia'])
        // El CI va como texto; los conteos, como números para poder sumarlos.
        ->and($hoja->rangeToArray('B11:I11', null, false, false)[0])->toBe(['MEJIA MUIBA AGUSTIN', '10830573', '05/01/2026 – 30/11/2026 (SDAF)', 14, 1, null, 1, 1])
        // «Faltas» junta las de una sola punta, como la pantalla.
        ->and($hoja->getCell('H12')->getValue())->toBe(2)
        ->and($hoja->rangeToArray('A13:I13', null, false, false)[0])->toBe(['Total:', null, null, null, 14, 1, 0, 3, 1]);
});

test('si el rango cruza meses agrega la columna Mes y una fila por mes', function (): void {
    $hoja = hojaDeDireccion(true);

    expect($hoja->getCell('E10')->getValue())->toBe('Mes')
        ->and($hoja->rangeToArray('E11:F11', null, false, false)[0])->toBe(['Total', '—'])
        ->and($hoja->rangeToArray('E12:G12', null, false, false)[0])->toBe(['Septiembre 2026', 5, 1])
        ->and($hoja->rangeToArray('E13:F13', null, false, false)[0])->toBe(['Octubre 2026', 9])
        ->and($hoja->getCell('A15')->getValue())->toBe('Total:')
        ->and($hoja->getCell('F15')->getValue())->toBe('—');
});

test('exportar el reporte por dirección pide el permiso de exportar', function (): void {
    $this->actingAs(usuarioCon('ViewAny:Reporte'))
        ->get(route('reportes.marcaciones.direccion.generar', ['direccion' => 16, 'print' => 2]))
        ->assertForbidden();
});

test('el botón Excel solo aparece con el permiso de exportar', function (): void {
    $vista = fn (bool $puedeExportar) => view('reportes.marcaciones.procesado.direccion-lista', [
        'filas' => collect(), 'totales' => ['funcionarios' => 0, 'atraso' => 0, 'porEstado' => []],
        'direccion' => 16, 'nombreDireccion' => 'SDAF', 'unidad' => null, 'nombreUnidad' => 'Todas las unidades',
        'desde' => '2026-10-01', 'hasta' => '2026-10-15', 'cruzaMeses' => false, 'filtro' => null, 'alcance' => R::alcance(0, 0, null),
    ])->render();

    $this->actingAs(usuarioCon('ViewAny:Reporte', 'Export:Reporte'));
    expect($vista(true))->toContain('print=2');

    $this->actingAs(usuarioCon('ViewAny:Reporte'));
    expect($vista(false))->not->toContain('print=2');
});

test('el filtro Mostrar deja solo a quien tuvo algo en esa columna', function (?string $filtro, array $quedan): void {
    $filas = collect([
        filaDeDireccion('1', 'CON ATRASO', totalesDeDireccion(10, [P::ATRASO => 2, P::CUMPLE => 8])),
        filaDeDireccion('2', 'CON UNA SOLA PUNTA', totalesDeDireccion(0, [P::SIN_SALIDA => 1])),
        filaDeDireccion('3', 'CON LICENCIA Y ABANDONO', totalesDeDireccion(0, [P::LICENCIA => 3, P::ABANDONO => 1])),
        filaDeDireccion('4', 'SIN NOVEDAD', totalesDeDireccion(0, [P::CUMPLE => 10])),
    ]);

    expect(R::filtrar($filas, $filtro)->pluck('persona.ci')->all())->toBe($quedan);
})->with([
    'sin filtro' => [null, ['1', '2', '3', '4']],
    'filtro desconocido' => ['otra', ['1', '2', '3', '4']],
    'con minutos acumulados' => ['minutos', ['1']],
    'con atraso' => ['atraso', ['1']],
    'con abandono' => ['abandono', ['3']],
    // «Faltas» junta la de una sola punta, igual que la columna.
    'con falta' => ['falta', ['2']],
    'con licencia' => ['licencia', ['3']],
]);

test('el alcance avisa cuando el filtro recortó la lista', function (): void {
    expect(R::alcance(12, 12, null))->toBe('12 funcionario(s) con contrato en el rango')
        ->and(R::alcance(12, 3, 'abandono'))->toBe('12 funcionario(s) con contrato en el rango · se muestran 3 con abandono');
});

test('el combo Mostrar ofrece las cinco columnas', function (): void {
    $this->actingAs(superAdmin())
        ->get(route('reportes.marcaciones.direccion'))
        ->assertOk()
        ->assertSeeInOrder(['Mostrar', 'Todos', 'Con minutos acumulados', 'Con atraso', 'Con abandono', 'Con falta', 'Con licencia']);
});

test('la lista no lleva botones por fila: la fila despliega el detalle, que trae Imprimir', function (): void {
    $this->actingAs(superAdmin());

    $html = view('reportes.marcaciones.procesado.direccion-lista', [
        'filas' => collect([filaDeDireccion('10830573', 'MEJIA MUIBA AGUSTIN', totalesDeDireccion(14, [P::ATRASO => 1]))]),
        'totales' => ['funcionarios' => 1, 'atraso' => 14 * 60, 'porEstado' => [P::ATRASO => 1]],
        'direccion' => 16, 'nombreDireccion' => 'SDAF', 'unidad' => null, 'nombreUnidad' => 'Todas las unidades',
        'desde' => '2026-10-01', 'hasta' => '2026-10-15', 'cruzaMeses' => false, 'filtro' => null, 'alcance' => R::alcance(1, 1, null),
    ])->render();

    expect($html)->toContain('pestana-detalle')
        ->not->toContain('>Detalle<')
        ->not->toContain('Imprimible individual');
});
