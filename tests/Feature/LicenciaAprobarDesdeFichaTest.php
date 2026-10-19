<?php

use App\Models\Licencia;

function licenciaDeFicha(string $estado): Licencia
{
    return Licencia::factory()->create([
        'ci' => '555', 'fecha' => '2026-10-12', 'estado' => $estado, 'tipo' => Licencia::TIPO_INSTITUCIONAL,
    ]);
}

test('en la ficha, solo la licencia pendiente trae el modal para aprobar o rechazar', function (): void {
    $pendiente = licenciaDeFicha(Licencia::PENDIENTE);
    $aprobada = licenciaDeFicha(Licencia::APROBADO);

    $this->actingAs(superAdmin())
        ->get(route('funcionarios.licencias.list', ['ci' => '555']))
        ->assertOk()
        ->assertSee(route('licencias.aprobar', $pendiente))
        ->assertSee(route('licencias.rechazar', $pendiente))
        ->assertSee('name="desde_ficha"', false)
        ->assertDontSee(route('licencias.aprobar', $aprobada));
});

test('aprobar desde la ficha vuelve a la ficha en la solapa de licencias', function (): void {
    $licencia = licenciaDeFicha(Licencia::PENDIENTE);

    $this->actingAs(superAdmin())
        ->patch(route('licencias.aprobar', $licencia), ['desde_ficha' => 1])
        ->assertRedirect(route('funcionarios.mamore', ['ci' => '555']).'#licencias')
        ->assertSessionHas('estado');

    expect($licencia->fresh()->estado)->toBe(Licencia::APROBADO);
});

test('rechazar desde la ficha exige el motivo', function (): void {
    $licencia = licenciaDeFicha(Licencia::PENDIENTE);
    $this->actingAs(superAdmin());

    $this->patch(route('licencias.rechazar', $licencia), ['desde_ficha' => 1])
        ->assertSessionHasErrors('observacion');
    expect($licencia->fresh()->estado)->toBe(Licencia::PENDIENTE);

    $this->patch(route('licencias.rechazar', $licencia), ['desde_ficha' => 1, 'observacion' => 'Falta el memorándum.'])
        ->assertRedirect(route('funcionarios.mamore', ['ci' => '555']).'#licencias');
    expect($licencia->fresh()->estado)->toBe(Licencia::RECHAZADO);
});

test('la aprobada trae solo el rechazo, con motivo obligatorio', function (): void {
    $aprobada = licenciaDeFicha(Licencia::APROBADO);

    $this->actingAs(superAdmin())
        ->get(route('funcionarios.licencias.list', ['ci' => '555']))
        ->assertSee('Rechazar licencia aprobada')
        ->assertSee(route('licencias.rechazar', $aprobada))
        ->assertDontSee(route('licencias.aprobar', $aprobada));

    $this->patch(route('licencias.rechazar', $aprobada), ['desde_ficha' => 1])
        ->assertSessionHasErrors('observacion');
    expect($aprobada->fresh()->estado)->toBe(Licencia::APROBADO);

    $this->patch(route('licencias.rechazar', $aprobada), ['desde_ficha' => 1, 'observacion' => 'Se anuló el viaje.'])
        ->assertRedirect(route('funcionarios.mamore', ['ci' => '555']).'#licencias');
    expect($aprobada->fresh())
        ->estado->toBe(Licencia::RECHAZADO)
        ->observacion->toBe('Se anuló el viaje.');
});

test('una aprobada no se vuelve a aprobar', function (): void {
    $aprobada = licenciaDeFicha(Licencia::APROBADO);

    $this->actingAs(superAdmin())
        ->patch(route('licencias.aprobar', $aprobada))
        ->assertForbidden();
});

test('lo rechazado no se revierte ni se elimina', function (): void {
    $rechazada = licenciaDeFicha(Licencia::RECHAZADO);
    $this->actingAs(superAdmin());

    $this->patch(route('licencias.aprobar', $rechazada))->assertForbidden();
    $this->patch(route('licencias.rechazar', $rechazada), ['observacion' => 'Otra vez.'])->assertForbidden();

    expect($rechazada->fresh()->estado)->toBe(Licencia::RECHAZADO)
        ->and($rechazada->fresh()->esEliminable)->toBeFalse();

    $this->get(route('funcionarios.licencias.list', ['ci' => '555']))
        ->assertDontSee(route('licencias.rechazar', $rechazada))
        // Ni el botón de baja ni el de rechazo: los dos son rojos. Ver sí queda.
        ->assertDontSee('btn-icon--peligro', false)
        ->assertSee('Ver solicitud');
});
