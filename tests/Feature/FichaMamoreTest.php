<?php

use Illuminate\Support\Facades\Http;

/*
| Ficha de una persona de Mamoré. En SisMark la CI es solo el número: si en
| Mamoré el campo `ci` trae el complemento pegado, la ficha lo avisa.
*/
beforeEach(function (): void {
    config(['services.mamore.url' => 'https://mamore.test/api', 'services.mamore.token' => 'token']);

    Http::fake([
        'mamore.test/api/people/ci/10811148%20-%201D*' => Http::response(['data' => [
            'ci' => '10811148 - 1D', 'full_ci' => '10811148 - 1D', 'full_name' => 'GABRIELA CLAURE MONTERO',
        ]]),
        'mamore.test/api/people/ci/7633685*' => Http::response(['data' => [
            'ci' => '7633685', 'full_ci' => '7633685 BE', 'full_name' => 'IGNACIO MOLINA GUZMAN',
        ]]),
        '*' => Http::response(['data' => []]),
    ]);
});

test('marca la CI cargada con letras o espacios en Mamoré', function (): void {
    $this->actingAs(superAdmin())
        ->get(route('funcionarios.mamore', ['ci' => '10811148 - 1D']))
        ->assertOk()
        ->assertSee('CI mal cargada en Mamoré')
        ->assertSee('En Mamoré la CI está cargada como «10811148 - 1D»', false);
});

test('una CI de solo números no lleva la marca', function (): void {
    $this->actingAs(superAdmin())
        ->get(route('funcionarios.mamore', ['ci' => '7633685']))
        ->assertOk()
        ->assertDontSee('CI mal cargada en Mamoré');
});
