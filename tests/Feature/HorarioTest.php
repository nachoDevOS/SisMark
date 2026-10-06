<?php

use App\Models\Horario;
use Illuminate\Support\Facades\Schema;

test('no elimina un horario que está dentro de un turno', function (): void {
    $turno = turnoLunesAViernes();
    $horario = $turno->horarios->first();

    $this->actingAs(superAdmin())
        ->delete(route('horarios.destroy', $horario))
        ->assertSessionHas('error', fn (string $mensaje): bool => str_contains($mensaje, $turno->nombre));

    expect($horario->fresh()->trashed())->toBeFalse();
});

test('tampoco si el turno que lo usa ya se eliminó', function (): void {
    $turno = turnoLunesAViernes();
    $horario = $turno->horarios->first();
    $turno->delete();

    $this->actingAs(superAdmin())
        ->delete(route('horarios.destroy', $horario))
        ->assertSessionHas('error');

    expect($horario->fresh()->trashed())->toBeFalse();
});

test('elimina con baja lógica un horario que no está en ningún turno', function (): void {
    $horario = horario(2);

    $this->actingAs(superAdmin())
        ->delete(route('horarios.destroy', $horario))
        ->assertSessionHas('estado');

    expect(Horario::withTrashed()->find($horario->id)->trashed())->toBeTrue();
});

test('el horario ya no tiene la marca de sugerido', function (): void {
    expect(Schema::hasColumn('horarios', 'sugerido'))->toBeFalse();

    $this->actingAs(superAdmin())
        ->get(route('horarios.list'))
        ->assertSuccessful()
        ->assertDontSee('Sugerido');
});

test('crea un horario', function (): void {
    $this->actingAs(superAdmin())
        ->post(route('horarios.store'), [
            'Dia' => '2',
            'nombreHorario' => 'LUN: 08:00 - 16:00',
            'HEntrada' => '08:00',
            'HTolerancia' => '08:10',
            'EMinima' => '07:00',
            'EMaxima' => '10:00',
            'HSalida' => '16:00',
            'STolerancia' => '16:00',
            'SMinima' => '16:00',
            'SMaxima' => '23:59',
            'HTrabajadas' => '8',
        ])
        ->assertSessionHasNoErrors();

    $horario = Horario::query()->sole();

    expect($horario->nombreHorario)->toBe('LUN: 08:00 - 16:00')
        ->and($horario->hEntrada->format('H:i'))->toBe('08:00')
        ->and(strlen(trim($horario->idHorario)))->toBe(3);
});
