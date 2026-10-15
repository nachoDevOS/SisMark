<?php

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
