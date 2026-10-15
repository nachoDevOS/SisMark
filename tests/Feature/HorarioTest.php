<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

test('horarios es solo de consulta: se cargan al crear el turno', function (string $ruta): void {
    expect(Route::has($ruta))->toBeFalse();
})->with(['horarios.create', 'horarios.store', 'horarios.edit', 'horarios.update', 'horarios.destroy']);

test('el listado muestra el turno de cada horario y marca los heredados', function (): void {
    $turno = turnoLunesAViernes();
    horario(7, '09:00', '13:00');

    $this->actingAs(superAdmin())
        ->get(route('horarios.list'))
        ->assertSuccessful()
        ->assertSee($turno->nombre)
        ->assertSee('Sin turno');
});

test('la ficha muestra el turno dueño del horario', function (): void {
    $turno = turnoLunesAViernes();

    $this->actingAs(superAdmin());

    $this->get(route('horarios.index'))->assertSuccessful();
    $this->get(route('horarios.show', $turno->horarios->first()))
        ->assertSuccessful()
        ->assertSee($turno->nombre);
    $this->get(route('horarios.show', horario(7)))
        ->assertSuccessful()
        ->assertSee('Sin turno');
});

test('el horario ya no tiene la marca de sugerido', function (): void {
    expect(Schema::hasColumn('horarios', 'sugerido'))->toBeFalse();

    $this->actingAs(superAdmin())
        ->get(route('horarios.list'))
        ->assertSuccessful()
        ->assertDontSee('Sugerido');
});
