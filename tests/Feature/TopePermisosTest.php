<?php

use App\Models\Configuracion;
use App\Models\Licencia;
use App\Services\TopePermisos;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-15');

    // Octubre: 2 h por mes. Noviembre: se baja a 1 h.
    Configuracion::guardar(Configuracion::TOPE_PERMISO_MENSUAL, '120', Carbon::parse('2026-10-01'), 'inicial');
    Configuracion::guardar(Configuracion::TOPE_PERMISO_ALCANCE, Configuracion::TOPE_POR_MES, Carbon::parse('2026-10-01'), 'inicial');
    Configuracion::guardar(Configuracion::TOPE_PERMISO_MENSUAL, '60', Carbon::parse('2026-11-01'), 'baja');

    // 1 h 30 de permiso personal aprobado en octubre.
    Licencia::factory()->porHoras('08:00', '09:30')->create([
        'ci' => '111', 'fecha' => '2026-10-10', 'tipo' => Licencia::TIPO_PERSONAL, 'estado' => Licencia::APROBADO,
    ]);

    $this->tope = app(TopePermisos::class);
});

test('cada mes se cuenta con el tope que regía ese mes', function (): void {
    $saldo = $this->tope->saldo('111', Carbon::parse('2026-10-01'), Carbon::parse('2026-11-30'), [], null, null, []);

    expect($saldo['bolsas'])->toHaveCount(2)
        ->and($saldo['bolsas'][0])->toMatchArray(['tope' => 120, 'usado' => 90, 'queda' => 30])
        ->and($saldo['bolsas'][1])->toMatchArray(['tope' => 60, 'usado' => 0, 'queda' => 60])
        // El encabezado muestra el del último mes del rango.
        ->and($saldo['tope'])->toBe(60);
});

test('bajar el tope en noviembre no deja pasado a octubre', function (): void {
    $octubre = $this->tope->saldo('111', Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'), [], null, null, []);

    expect($octubre['bolsas'][0]['usado'])->toBeLessThanOrEqual($octubre['bolsas'][0]['tope']);
});

test('frena lo que se pasa del tope de su mes', function (string $fecha, bool $sePasa): void {
    $excesos = $this->tope->excesos(['111' => [$fecha]], '08:00', '08:45', ['111' => []]);

    expect($excesos !== [])->toBe($sePasa);
})->with([
    'octubre: quedan 30 min y pide 45' => ['2026-10-20', true],
    'noviembre: queda 1 h y pide 45' => ['2026-11-20', false],
]);

test('el mensaje dice el tope de ese mes', function (): void {
    $excesos = $this->tope->excesos(['111' => ['2026-10-20']], '08:00', '08:45', ['111' => []]);

    expect($this->tope->mensajeDeExcesos($excesos, false))
        ->toContain('octubre de 2026')
        ->toContain('tope 2h 00m por mes')
        ->toContain('le queda 0h 30m');
});

test('un mes sin tope no frena ni muestra saldo', function (): void {
    expect($this->tope->excesos(['111' => ['2026-09-20']], '08:00', '18:00', ['111' => []]))->toBe([])
        ->and($this->tope->saldo('111', Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'), [], null, null, []))->toBeNull();
});

test('las licencias institucionales y las de horario completo no descuentan', function (): void {
    Licencia::factory()->porHoras('10:00', '12:00')->create([
        'ci' => '111', 'fecha' => '2026-10-11', 'tipo' => Licencia::TIPO_INSTITUCIONAL, 'estado' => Licencia::APROBADO,
    ]);
    Licencia::factory()->create([
        'ci' => '111', 'fecha' => '2026-10-12', 'tipo' => Licencia::TIPO_PERSONAL, 'estado' => Licencia::APROBADO,
    ]);

    $saldo = $this->tope->saldo('111', Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'), [], null, null, []);

    expect($saldo['bolsas'][0]['usado'])->toBe(90);
});
