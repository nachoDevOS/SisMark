<?php

use App\Models\AsignacionTurno;
use App\Models\Licencia;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-16');
});

test('licencias: la pantalla propone el mes en curso completo', function (): void {
    $this->actingAs(superAdmin())
        ->get(route('licencias.index'))
        ->assertOk()
        ->assertSee('value="2026-10-01"', false)
        ->assertSee('value="2026-10-31"', false);
});

test('licencias: el listado trae solo las solicitudes con días en el rango', function (): void {
    Licencia::factory()->create(['ci' => '1111111', 'fecha' => '2026-10-10', 'tipo' => Licencia::TIPO_PERSONAL]);
    Licencia::factory()->create(['ci' => '2222222', 'fecha' => '2026-09-10', 'tipo' => Licencia::TIPO_PERSONAL]);

    $this->actingAs(superAdmin())
        ->get(route('licencias.list', ['desde' => '2026-10-01', 'hasta' => '2026-10-31']))
        ->assertOk()
        ->assertSee('1111111')
        ->assertDontSee('2222222');
});

test('turnos asignados: la pantalla propone el mes en curso completo', function (): void {
    $this->actingAs(superAdmin())
        ->get(route('turnos-asignados.index'))
        ->assertOk()
        ->assertSee('value="2026-10-01"', false)
        ->assertSee('value="2026-10-31"', false);
});

test('turnos asignados: entra la vigencia que se cruza con el rango', function (): void {
    AsignacionTurno::factory()->create(['ci' => '1111111', 'desde' => '2026-09-01', 'hasta' => '2026-10-05']);
    AsignacionTurno::factory()->create(['ci' => '2222222', 'desde' => '2026-11-01', 'hasta' => '2026-12-31']);
    AsignacionTurno::factory()->create(['ci' => '3333333', 'desde' => '2026-01-01', 'hasta' => '2026-09-30']);

    $this->actingAs(superAdmin())
        ->get(route('turnos-asignados.list', ['desde' => '2026-10-01', 'hasta' => '2026-10-31']))
        ->assertOk()
        ->assertSee('1111111')
        ->assertDontSee('2222222')
        ->assertDontSee('3333333');
});
