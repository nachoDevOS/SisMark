<?php

use App\Services\DirectorioMamore;
use Illuminate\Support\Facades\Http;

/*
| Ficha de una persona de Mamoré. En SisMark la CI es solo el número: si en
| Mamoré el campo `ci` trae el complemento pegado, la ficha lo avisa. La foto
| solo se usa si es una URL completa: la ruta suelta daría 404 contra SisMark.
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
        'mamore.test/api/people/ci/111*' => Http::response(['data' => [
            'ci' => '111', 'full_name' => 'FOTO VIEJA', 'image' => 'people/June2024/RuDQ91wrZUn0F6XvRClH.png',
        ]]),
        'mamore.test/api/people/ci/222*' => Http::response(['data' => [
            'ci' => '222', 'full_name' => 'FOTO EN S3', 'image' => 'https://s3.test/prod/people/June2026/abc.png',
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

test('una foto guardada como ruta suelta no se pide: va el ícono', function (): void {
    $this->actingAs(superAdmin())
        ->get(route('funcionarios.mamore', ['ci' => '111']))
        ->assertOk()
        ->assertDontSee('people/June2024/RuDQ91wrZUn0F6XvRClH.png', false);
});

test('una foto con URL completa se muestra', function (): void {
    $this->actingAs(superAdmin())
        ->get(route('funcionarios.mamore', ['ci' => '222']))
        ->assertOk()
        ->assertSee('src="https://s3.test/prod/people/June2026/abc.png"', false);
});

test('en los listados la ruta suelta queda sin foto y la URL completa lleva su miniatura', function (): void {
    $directorio = app(DirectorioMamore::class);

    $vieja = $directorio->normalizarPersona(['ci' => '111', 'image' => 'people/June2024/RuDQ91wrZUn0F6XvRClH.png']);
    $nueva = $directorio->normalizarPersona(['ci' => '222', 'image' => 'https://s3.test/prod/people/June2026/abc.png']);

    expect($vieja['image'])->toBeNull()
        ->and($vieja['imageThumb'])->toBeNull()
        ->and($nueva['image'])->toBe('https://s3.test/prod/people/June2026/abc.png')
        ->and($nueva['imageThumb'])->toBe('https://s3.test/prod/people/June2026/abc-cropped.png');
});
