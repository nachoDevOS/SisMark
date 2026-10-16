<?php

use App\Models\AsignacionHorario;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\IOFactory;

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-15');
    config(['services.mamore.url' => 'https://mamore.test/api', 'services.mamore.token' => 'token']);

    // 111 tiene horario hoy; a 222 se le venció.
    AsignacionHorario::factory()->create(['ci' => '111']);
    AsignacionHorario::factory()->vencida()->create(['ci' => '222', 'hasta' => '2026-09-30']);
});

/**
 * Respuesta de Mamoré con esas cédulas, todas con contrato en RRHH.
 *
 * @param  list<string>  $cis
 */
function mamoreSinTurno(array $cis): void
{
    Http::fake([
        'mamore.test/*' => Http::response([
            'data' => array_map(fn (string $ci): array => [
                'id' => (int) $ci,
                'ci' => $ci,
                'full_name' => "PERSONA {$ci}",
                'has_contract' => true,
                'contrato' => [
                    'procedure_type' => 'eventual',
                    'cargo' => 'TÉCNICO',
                    'direccion_administrativa' => ['sigla' => 'RRHH', 'nombre' => 'Dirección de Recursos Humanos'],
                    'unidad_administrativa' => ['sigla' => 'UPER', 'nombre' => 'Unidad de Personal'],
                    'start' => '2026-01-02',
                    // 333 es permanente: su contrato no tiene fin.
                    'finish' => $ci === '333' ? null : '2026-12-31',
                ],
            ], $cis),
            'meta' => [
                'total' => count($cis), 'per_page' => 50, 'current_page' => 1,
                'tipos_contrato' => [['id' => 5, 'nombre' => 'eventual', 'total' => 3]],
            ],
        ]),
    ]);
}

test('la pantalla ofrece los tipos de contrato de Mamoré', function (): void {
    mamoreSinTurno([]);

    $this->actingAs(superAdmin())
        ->get(route('reportes.funcionarios.sin-turno'))
        ->assertOk()
        ->assertSee('<option value="5">Eventual</option>', false);
});

test('filtra por dirección, unidad y tipo y manda las cédulas con horario vigente', function (): void {
    mamoreSinTurno(['222', '333']);

    $this->actingAs(superAdmin())
        ->get(route('reportes.funcionarios.sin-turno.generar', [
            'direccion' => 7, 'nombreDireccion' => 'RRHH — Recursos Humanos',
            'unidad' => 9, 'nombreUnidad' => 'UPER — Personal',
            'tipo' => 5, 'nombreTipo' => 'Eventual',
        ]))
        ->assertOk()
        ->assertSee('RRHH — Recursos Humanos')
        ->assertSee('2 funcionarios sin turno')
        ->assertSee('Dirección de Recursos Humanos')
        ->assertSee('Unidad de Personal')
        ->assertSee('Inicio: 02/01/2026')
        ->assertSee('Fin: 31/12/2026')
        ->assertSee('Fin: Sin fecha de fin')
        ->assertDontSee('Último horario')
        ->assertDontSee(route('turnos-asignados.create', ['ci' => '333']), false)
        ->assertSee('href="'.route('funcionarios.mamore', ['ci' => '333']).'" target="_blank"', false);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request['contrato'] === 'con'
        && $request['horario'] === 'sin'
        && $request['direccion'] === 7
        && $request['unidad'] === 9
        && $request['tipo'] === 5
        && $request['con_horario_ci'] === ['111']);
});

test('sin dirección la unidad se ignora y entra la Gobernación entera', function (): void {
    mamoreSinTurno(['333']);

    $this->actingAs(superAdmin())
        ->get(route('reportes.funcionarios.sin-turno.generar', ['unidad' => 9]))
        ->assertOk()
        ->assertSee('Todas las direcciones');

    Http::assertSent(fn (Request $request): bool => ! isset($request['direccion'])
        && ! isset($request['unidad']));
});

test('el imprimible lista a todos con los filtros en la cabecera', function (): void {
    mamoreSinTurno(['222', '333']);

    $this->actingAs(superAdmin())
        ->get(route('reportes.funcionarios.sin-turno.generar', ['tipo' => 5, 'nombreTipo' => 'Eventual', 'print' => 1]))
        ->assertOk()
        ->assertSee('FUNCIONARIOS SIN TURNO NI HORARIO ASIGNADO')
        ->assertSee('Eventual')
        ->assertSee('PERSONA 333')
        ->assertSeeInOrder(['Inicio contrato', 'Fin contrato', '02/01/2026', '31/12/2026']);
});

test('la lista sale en orden alfabético por apellidos y nombres', function (): void {
    $persona = fn (string $ci, string $nombre, string $paterno): array => [
        'id' => (int) $ci, 'ci' => $ci, 'first_name' => $nombre,
        'paternal_surname' => $paterno, 'maternal_surname' => 'GUZMAN', 'has_contract' => true,
    ];

    Http::fake([
        'mamore.test/*' => Http::response([
            // Mamoré ordena por apellidos pero no por nombre: los dos MOLINA llegan al revés.
            'data' => [$persona('3', 'ZOE', 'ÁLVAREZ'), $persona('4', 'PEDRO', 'MOLINA'), $persona('5', 'ANA', 'MOLINA')],
            'meta' => ['total' => 3, 'per_page' => 50, 'current_page' => 1],
        ]),
    ]);

    $this->actingAs(superAdmin())
        ->get(route('reportes.funcionarios.sin-turno.generar'))
        ->assertOk()
        ->assertSeeInOrder(['ÁLVAREZ GUZMAN ZOE', 'MOLINA GUZMAN ANA', 'MOLINA GUZMAN PEDRO']);

    $this->get(route('reportes.funcionarios.sin-turno.generar', ['print' => 1]))
        ->assertOk()
        ->assertSeeInOrder(['ÁLVAREZ GUZMAN ZOE', 'MOLINA GUZMAN ANA', 'MOLINA GUZMAN PEDRO']);
});

test('el Excel trae a todos con contrato, dirección y unidad completas', function (): void {
    mamoreSinTurno(['222', '333']);

    $respuesta = $this->actingAs(superAdmin())
        ->get(route('reportes.funcionarios.sin-turno.generar', [
            'direccion' => 7, 'nombreDireccion' => 'RRHH — Recursos Humanos', 'print' => 2,
        ]))
        ->assertOk()
        ->assertDownload('funcionarios-sin-turno-rrhh-2026-10-15.xlsx');

    $hoja = IOFactory::load($respuesta->baseResponse->getFile()->getPathname())->getActiveSheet();

    expect($hoja->getCell('A6')->getValue())->toBe('Dirección: RRHH — Recursos Humanos')
        ->and($hoja->getCell('A9')->getValue())->toBe('Funcionarios: 2')
        ->and($hoja->rangeToArray('A11:I13'))->toBe([
            ['N°', 'Funcionario', 'CI', 'Tipo de contrato', 'Cargo', 'Dirección', 'Unidad', 'Inicio contrato', 'Fin contrato'],
            ['1', 'PERSONA 222', '222', 'Eventual', 'TÉCNICO', 'Dirección de Recursos Humanos', 'Unidad de Personal', '02/01/2026', '31/12/2026'],
            ['2', 'PERSONA 333', '333', 'Eventual', 'TÉCNICO', 'Dirección de Recursos Humanos', 'Unidad de Personal', '02/01/2026', 'Sin fecha de fin'],
        ]);
});

test('sin el permiso de exportar no baja el Excel ni ve el botón', function (): void {
    mamoreSinTurno(['333']);

    $this->actingAs(usuarioCon('ViewAny:Reporte'))
        ->get(route('reportes.funcionarios.sin-turno.generar'))
        ->assertOk()
        ->assertSee('Imprimir')
        ->assertDontSee('print=2', false);

    $this->get(route('reportes.funcionarios.sin-turno.generar', ['print' => 2]))->assertForbidden();
});

test('si Mamoré falla la tabla avisa en vez de romperse', function (): void {
    Http::fake(['mamore.test/*' => Http::response([], 500)]);

    $this->actingAs(superAdmin())
        ->get(route('reportes.funcionarios.sin-turno.generar'))
        ->assertOk()
        ->assertSee('el reporte no se generó');
});

test('sin el permiso de reportes no entra', function (): void {
    $this->actingAs(usuarioCon('ViewAny:Persona'))
        ->get(route('reportes.funcionarios.sin-turno'))
        ->assertForbidden();
});
