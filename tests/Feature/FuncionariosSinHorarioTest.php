<?php

use App\Models\AsignacionHorario;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-15');
    config(['services.mamore.url' => 'https://mamore.test/api', 'services.mamore.token' => 'token']);
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

test('sin horario vigente pide a Mamoré los con contrato menos quienes ya tienen horario', function (): void {
    AsignacionHorario::factory()->create(['ci' => '111']);
    AsignacionHorario::factory()->vencida()->create(['ci' => '222', 'hasta' => '2026-09-30']);

    Http::fake([
        'mamore.test/*' => Http::response([
            'data' => [personaMamore('222'), personaMamore('333')],
            'meta' => [
                'total' => 2, 'per_page' => 10, 'current_page' => 1,
                'total_con_contrato' => 3, 'total_sin_contrato' => 5,
                'total_con_contrato_sin_excluidos' => 2, 'tipos_contrato' => [],
            ],
        ]),
    ]);

    $this->actingAs(superAdmin())
        ->get(route('funcionarios.list', ['contrato' => 'sin_horario', 'por_pagina' => 10]))
        ->assertOk()
        ->assertSee('data-sin-horario="2"', false)
        ->assertSee('Último horario')
        ->assertSee('Venció el 30/09/2026')
        ->assertSee('Nunca tuvo')
        ->assertSee(route('turnos-asignados.create', ['ci' => '333']), false);

    // El horario vencido no cuenta: solo se excluye a quien lo tiene vigente.
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request['contrato'] === 'con'
        && $request['excluir_ci'] === ['111']);
});

test('los demás filtros siguen pidiendo por GET y sin columna de último horario', function (): void {
    AsignacionHorario::factory()->create(['ci' => '111']);

    Http::fake([
        'mamore.test/*' => Http::response([
            'data' => [personaMamore('111')],
            'meta' => ['total' => 1, 'per_page' => 10, 'current_page' => 1, 'total_con_contrato' => 1, 'total_sin_contrato' => 0],
        ]),
    ]);

    $this->actingAs(superAdmin())
        ->get(route('funcionarios.list', ['contrato' => 'con', 'por_pagina' => 10]))
        ->assertOk()
        ->assertDontSee('Último horario');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && ! str_contains($request->url(), 'excluir_ci'));
});
