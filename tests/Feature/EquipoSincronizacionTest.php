<?php

use App\Models\Equipo;

test('con el permiso de editar el equipo se abre y se guarda la sincronización automática', function (): void {
    $equipo = Equipo::factory()->create();

    $this->actingAs(usuarioCon('ViewAny:Equipo', 'Update:Equipo'))
        ->get(route('equipos.index'))
        ->assertSee(route('equipos.sincronizacion.edit', $equipo), false);

    $this->get(route('equipos.sincronizacion.edit', $equipo))->assertOk();

    $this->put(route('equipos.sincronizacion.update', $equipo), [
        'sync_automatica' => '1',
        'sync_horarios' => ['08:30'],
        'sync_dias' => ['1'],
    ])->assertSessionHasNoErrors();

    expect($equipo->fresh()->sync_automatica)->toBeTrue();
});

test('el permiso de sincronizar solo ya no abre la sincronización automática', function (): void {
    $equipo = Equipo::factory()->create();

    $this->actingAs(usuarioCon('ViewAny:Equipo', 'Sync:Equipo'))
        ->get(route('equipos.index'))
        ->assertDontSee(route('equipos.sincronizacion.edit', $equipo), false);

    $this->get(route('equipos.sincronizacion.edit', $equipo))->assertForbidden();
    $this->put(route('equipos.sincronizacion.update', $equipo), ['sync_automatica' => '0'])->assertForbidden();
});
