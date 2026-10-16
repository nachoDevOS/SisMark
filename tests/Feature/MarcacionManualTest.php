<?php

use App\Models\Asistencia;
use App\Models\Persona;
use App\Models\User;

test('el motivo de la marcación manual pide al menos 15 caracteres', function (): void {
    $persona = Persona::factory()->create();

    $this->actingAs(superAdmin())
        ->post(route('marcaciones.store'), [
            'ci' => $persona->ci,
            'fecha' => '2026-10-15',
            'hora' => '08:00',
            'observacion' => 'Muy corto',
        ])
        ->assertSessionHasErrors('observacion');

    expect(Asistencia::query()->count())->toBe(0);
});

test('la marcación manual guarda quién la cargó y su motivo', function (): void {
    $persona = Persona::factory()->create();
    $usuario = superAdmin();

    $this->actingAs($usuario)
        ->post(route('marcaciones.store'), [
            'ci' => $persona->ci,
            'fecha' => '2026-10-15',
            'hora' => '08:00',
            'observacion' => 'Papeleta de salida firmada por el jefe',
        ])
        ->assertSessionHasNoErrors();

    $marcacion = Asistencia::query()->sole();

    expect($marcacion->tipo)->toBe(Asistencia::TIPO_MANUAL)
        ->and($marcacion->registerUser_id)->toBe($usuario->id);
});

test('el listado muestra autor y motivo solo en las marcaciones manuales', function (): void {
    $autor = User::factory()->create(['name' => 'Ana Cargadora']);

    $this->actingAs($autor);
    Asistencia::factory()->create([
        'tipo' => Asistencia::TIPO_MANUAL,
        'observacion' => 'Equipo caído toda la mañana',
    ]);
    Asistencia::factory()->create([
        'tipo' => Asistencia::TIPO_RELOJ,
        'observacion' => 'Nota que no debe verse',
    ]);

    $this->actingAs(superAdmin())
        ->get(route('marcaciones.list'))
        ->assertOk()
        ->assertSee('Ana Cargadora')
        ->assertSee('Equipo caído toda la mañana')
        ->assertDontSee('Nota que no debe verse');
});
