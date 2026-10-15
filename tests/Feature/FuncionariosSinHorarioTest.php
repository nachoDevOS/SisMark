<?php

use App\Models\AsignacionHorario;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-15');
    config(['services.mamore.url' => 'https://mamore.test/api', 'services.mamore.token' => 'token']);

    // 111 tiene horario hoy; a 222 se le venció.
    AsignacionHorario::factory()->create(['ci' => '111']);
    AsignacionHorario::factory()->vencida()->create(['ci' => '222', 'hasta' => '2026-09-30']);
});

/**
 * Una persona de Mamoré, con contrato firmado.
 *
 * @return array<string, mixed>
 */
function personaMamore(string $ci): array
{
    return [
        'id' => (int) $ci,
        'ci' => $ci,
        'full_name' => "PERSONA {$ci}",
        'contrato' => ['tipo' => 'eventual'],
    ];
}

/**
 * Respuesta de Mamoré con esas personas y los totales del combo.
 *
 * @param  list<string>  $cis
 */
function respuestaMamore(array $cis, ?int $conHorario = null, ?int $sinHorario = null): void
{
    Http::fake([
        'mamore.test/*' => Http::response([
            'data' => array_map(personaMamore(...), $cis),
            'meta' => [
                'total' => count($cis), 'per_page' => 10, 'current_page' => 1,
                'total_con_contrato' => 3, 'total_sin_contrato' => 5, 'tipos_contrato' => [],
                'total_con_horario' => $conHorario, 'total_sin_horario' => $sinHorario,
            ],
        ]),
    ]);
}

test('sin turnos/horarios se combina con el tipo y manda las cédulas con horario vigente', function (): void {
    respuestaMamore(['222', '333'], conHorario: 1, sinHorario: 2);

    $this->actingAs(superAdmin())
        ->get(route('funcionarios.list', ['contrato' => 'con', 'tipo' => 5, 'horario' => 'sin', 'por_pagina' => 10]))
        ->assertOk()
        ->assertSee('data-con-horario="1"', false)
        ->assertSee('data-sin-horario="2"', false)
        ->assertSee('Último horario')
        ->assertSee('Venció el 30/09/2026')
        ->assertSee('Nunca tuvo')
        ->assertSee(route('turnos-asignados.create', ['ci' => '333']), false);

    // El horario vencido no cuenta: solo va quien lo tiene vigente.
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request['contrato'] === 'con'
        && $request['tipo'] === 5
        && $request['horario'] === 'sin'
        && $request['con_horario_ci'] === ['111']);
});

test('con turnos/horarios pide solo a quien tiene horario, sin la columna de último horario', function (): void {
    respuestaMamore(['111'], conHorario: 1, sinHorario: 2);

    $this->actingAs(superAdmin())
        ->get(route('funcionarios.list', ['contrato' => 'con', 'horario' => 'con', 'por_pagina' => 10]))
        ->assertOk()
        ->assertDontSee('Último horario');

    Http::assertSent(fn (Request $request): bool => $request['horario'] === 'con'
        && $request['con_horario_ci'] === ['111']);
});

test('con contrato y «Todos» manda las cédulas para contar, pero no filtra', function (): void {
    respuestaMamore(['111', '222', '333'], conHorario: 1, sinHorario: 2);

    $this->actingAs(superAdmin())
        ->get(route('funcionarios.list', ['contrato' => 'con', 'por_pagina' => 10]))
        ->assertOk()
        ->assertSee('data-con-horario="1"', false);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request['con_horario_ci'] === ['111']
        && ! isset($request['horario']));
});

test('sin contrato el filtro de turnos/horarios se ignora y la consulta va por GET', function (string $contrato): void {
    respuestaMamore(['444']);

    $this->actingAs(superAdmin())
        ->get(route('funcionarios.list', ['contrato' => $contrato, 'horario' => 'sin', 'por_pagina' => 10]))
        ->assertOk()
        ->assertDontSee('Último horario');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && ! str_contains($request->url(), 'con_horario_ci')
        && ! str_contains($request->url(), 'horario='));
})->with(['todos', 'sin']);

test('ya no existe la opción suelta «con contrato sin horario vigente»', function (): void {
    respuestaMamore(['111']);

    $this->actingAs(superAdmin())
        ->get(route('funcionarios.index'))
        ->assertOk()
        ->assertDontSee('sin_horario')
        // Textos cortos: el combo de contrato, al lado, ya dice de quiénes se trata.
        ->assertSee('Con turnos')
        ->assertSee('Sin turnos')
        ->assertDontSee('turnos/horarios');

    // El valor viejo cae en «Todos»: no filtra ni manda cédulas.
    $this->get(route('funcionarios.list', ['contrato' => 'sin_horario', 'por_pagina' => 10]))->assertOk();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET');
});
