<?php

use App\Models\DiaExcepcional;
use App\Services\AsignadorTurnos;
use App\Services\ProcesadorAsistencia;
use Illuminate\Support\Carbon;

test('el alta guarda la fecha sin hora aunque llegue con hora', function (): void {
    $this->actingAs(superAdmin())
        ->post(route('dias-excepcionales.store'), [
            'fecha' => '2026-11-02 15:30:00',
            'motivoInasistencia' => 'DÍA DE DIFUNTOS',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dias-excepcionales.index'));

    $dia = DiaExcepcional::query()->sole();

    expect($dia->getRawOriginal('fecha'))->toBe('2026-11-02')
        ->and($dia->fecha->toDateString())->toBe('2026-11-02');
});

test('no se repite el mismo día, venga con la hora que venga', function (): void {
    DiaExcepcional::factory()->create(['fecha' => '2026-11-02']);

    $this->actingAs(superAdmin())
        ->post(route('dias-excepcionales.store'), [
            'fecha' => '2026-11-02 08:00',
            'motivoInasistencia' => 'DÍA DE DIFUNTOS',
        ])
        ->assertSessionHasErrors('fecha');

    expect(DiaExcepcional::query()->count())->toBe(1);
});

test('la edición guarda la fecha sin hora y se puede dejar el mismo día', function (): void {
    $dia = DiaExcepcional::factory()->create(['fecha' => '2026-11-02']);

    $this->actingAs(superAdmin())
        ->put(route('dias-excepcionales.update', $dia), [
            'fecha' => '2026-11-02',
            'motivoInasistencia' => 'TOLERANCIA',
        ])
        ->assertSessionHasNoErrors();

    $this->actingAs(superAdmin())
        ->put(route('dias-excepcionales.update', $dia), [
            'fecha' => '2026-11-03 10:00:00',
            'motivoInasistencia' => 'TOLERANCIA',
        ])
        ->assertSessionHasNoErrors();

    expect($dia->refresh()->getRawOriginal('fecha'))->toBe('2026-11-03');
});

test('la fábrica deja la fecha sin hora', function (): void {
    expect(DiaExcepcional::factory()->create()->getRawOriginal('fecha'))
        ->toMatch('/^\d{4}-\d{2}-\d{2}$/');
});

test('el alta completa la fila vacía del calendario en vez de rechazar la fecha', function (): void {
    $vacia = DiaExcepcional::factory()->create([
        'fecha' => '2026-11-02',
        'motivoInasistencia' => null,
        'estado' => DiaExcepcional::INACTIVO,
    ]);

    $this->actingAs(superAdmin())
        ->post(route('dias-excepcionales.store'), [
            'fecha' => '2026-11-02',
            'motivoInasistencia' => 'DÍA DE DIFUNTOS',
        ])
        ->assertSessionHasNoErrors();

    expect(DiaExcepcional::query()->count())->toBe(1)
        ->and($vacia->refresh()->motivoInasistencia)->toBe('DÍA DE DIFUNTOS')
        ->and($vacia->activo)->toBeTrue();
});

test('una fecha eliminada se puede volver a cargar', function (): void {
    DiaExcepcional::factory()->create(['fecha' => '2026-11-02'])->delete();

    $this->actingAs(superAdmin())
        ->post(route('dias-excepcionales.store'), [
            'fecha' => '2026-11-02',
            'motivoInasistencia' => 'DÍA DE DIFUNTOS',
        ])
        ->assertSessionHasNoErrors();

    expect(DiaExcepcional::query()->count())->toBe(1);
});

test('dar de baja y activar alterna el estado', function (): void {
    $dia = DiaExcepcional::factory()->create(['fecha' => '2026-11-02']);

    $this->actingAs(superAdmin())
        ->patch(route('dias-excepcionales.estado', $dia))
        ->assertSessionHas('estado');

    expect($dia->refresh()->activo)->toBeFalse();

    $this->actingAs(superAdmin())->patch(route('dias-excepcionales.estado', $dia));

    expect($dia->refresh()->activo)->toBeTrue();
});

test('dar de baja pide el permiso de editar', function (): void {
    $dia = DiaExcepcional::factory()->create();

    $this->actingAs(usuarioCon('ViewAny:DiaExcepcional'))
        ->patch(route('dias-excepcionales.estado', $dia))
        ->assertForbidden();

    expect($dia->refresh()->activo)->toBeTrue();
});

test('el listado filtra por estado y «Todos» incluye las filas sin motivo', function (): void {
    DiaExcepcional::factory()->create(['fecha' => '2026-11-02', 'motivoInasistencia' => 'DÍA DE DIFUNTOS']);
    DiaExcepcional::factory()->create(['fecha' => '2026-12-25', 'motivoInasistencia' => 'NAVIDAD', 'estado' => DiaExcepcional::INACTIVO]);
    DiaExcepcional::factory()->create(['fecha' => '2026-11-03', 'motivoInasistencia' => null, 'estado' => DiaExcepcional::INACTIVO]);

    $listar = fn (string $estado) => $this->actingAs(superAdmin())
        ->get(route('dias-excepcionales.list', ['estado' => $estado]));

    $listar('todos')->assertSee('DÍA DE DIFUNTOS')->assertSee('NAVIDAD')->assertSee('03/11/2026');
    $listar('activos')->assertSee('DÍA DE DIFUNTOS')->assertDontSee('NAVIDAD')->assertDontSee('03/11/2026');
    $listar('inactivos')->assertSee('NAVIDAD')->assertSee('03/11/2026')->assertDontSee('DÍA DE DIFUNTOS');
});

test('el reporte procesado ignora los días dados de baja', function (): void {
    app(AsignadorTurnos::class)->asignar('222', turnoLunesAViernes(), Carbon::parse('2026-11-02'), Carbon::parse('2026-11-03'));
    DiaExcepcional::factory()->create(['fecha' => '2026-11-02', 'motivoInasistencia' => 'DÍA DE DIFUNTOS']);
    DiaExcepcional::factory()->create(['fecha' => '2026-11-03', 'motivoInasistencia' => 'TOLERANCIA', 'estado' => DiaExcepcional::INACTIVO]);

    $estados = app(ProcesadorAsistencia::class)
        ->procesarConTramos('222', Carbon::parse('2026-11-02'), Carbon::parse('2026-11-03'), null)
        ->mapWithKeys(fn (array $dia): array => [$dia['fecha']->toDateString() => $dia['estado']])
        ->all();

    expect($estados)->toBe([
        '2026-11-02' => ProcesadorAsistencia::EXCEPCIONAL,
        '2026-11-03' => ProcesadorAsistencia::FALTA,
    ]);
});
