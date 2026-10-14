<?php

use App\Models\DiaExcepcional;

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
