<?php

use App\Models\Persona;

/*
 * Los listados con filtros de combo o fecha los aplican recién con el botón
 * «Buscar», no al cambiar el combo.
 */

test('el listado tiene su botón Buscar junto a los filtros', function (string $ruta): void {
    $this->actingAs(superAdmin())
        ->get(route($ruta))
        ->assertOk()
        ->assertSee('id="btn-buscar"', false);
})->with([
    'días excepcionales' => 'dias-excepcionales.index',
    'turnos' => 'turnos.index',
    'turnos asignados' => 'turnos-asignados.index',
    'licencias' => 'licencias.index',
    'marcaciones' => 'marcaciones.index',
    'funcionarios' => 'funcionarios.index',
]);

test('el origen A de las marcaciones es la importación USB', function (): void {
    $this->actingAs(superAdmin())
        ->get(route('marcaciones.index'))
        ->assertSee('A · Importación USB')
        ->assertDontSee('Sin identificar');
});

test('la fuente local de funcionarios se llama SIA y lista la base local', function (): void {
    Persona::factory()->create(['ci' => '7633685', 'paterno' => 'MOLINA', 'materno' => 'GUZMAN', 'nombres' => 'IGNACIO']);

    $this->actingAs(superAdmin())
        ->get(route('funcionarios.index'))
        ->assertSee('<option value="sia">SIA</option>', false)
        ->assertDontSee('SIAT');

    $this->get(route('funcionarios.list', ['fuente' => 'sia']))
        ->assertOk()
        ->assertSee('7633685');
});

test('en la ficha, cada solapa aplica sus filtros con su botón Buscar', function (): void {
    $persona = Persona::factory()->create(['ci' => '7633685']);

    $respuesta = $this->actingAs(superAdmin())
        ->get(route('funcionarios.show', $persona))
        ->assertOk()
        ->assertSeeInOrder(['id="m-tipo"', 'id="m-buscar"'], false)
        ->assertSeeInOrder(['id="l-mes"', 'id="l-buscar"'], false)
        ->assertSeeInOrder(['id="t-situacion"', 'id="t-buscar"'], false)
        ->assertSeeInOrder(['id="p-mes"', 'id="p-buscar"'], false);

    // Al cambiar solo recarga «Mostrar N registros»; el resto espera el botón.
    foreach (["controles: ['m-paginate']", "controles: ['l-paginate']", "controles: ['t-paginate']", 'controles: []'] as $controles) {
        $respuesta->assertSee($controles, false);
    }
});
