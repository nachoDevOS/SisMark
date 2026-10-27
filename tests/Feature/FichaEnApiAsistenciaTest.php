<?php

use App\Models\SistemaExterno;
use Illuminate\Support\Facades\Http;

/*
| La ficha del funcionario en `meta` de la API de asistencia: no viaja salvo
| que se pida con `conFicha=1`, porque armarla es preguntarle el nombre a
| Mamoré, que es el mismo que está consultando.
*/

beforeEach(function (): void {
    config(['services.mamore.url' => 'https://mamore.test/api', 'services.mamore.token' => 'token']);
    Http::fake(['mamore.test/api/people/ci/7633685*' => Http::response(['data' => [
        'ci' => '7633685', 'full_name' => 'IGNACIO MOLINA GUZMAN',
    ]])]);

    $mamore = SistemaExterno::create(['slug' => 'mamore', 'nombre' => 'Mamoré', 'activo' => true]);
    $this->withToken($mamore->createToken('mamore', ['asistencia:read', 'licencias:read'])->plainTextToken);
});

test('sin pedirla, la ficha no viaja y no se le pregunta nada a Mamoré', function (string $endpoint): void {
    $this->getJson("/api/v1/funcionarios/7633685/{$endpoint}?desde=2026-10-01&hasta=2026-10-27")
        ->assertSuccessful()
        ->assertJsonMissingPath('meta.funcionario')
        ->assertJsonPath('meta.rango.desde', '2026-10-01');

    Http::assertNothingSent();
})->with(['marcaciones', 'licencias']);

test('con conFicha=1 viaja con el nombre', function (): void {
    $this->getJson('/api/v1/funcionarios/7633685/marcaciones?desde=2026-10-01&hasta=2026-10-27&conFicha=1')
        ->assertSuccessful()
        ->assertJsonPath('meta.funcionario.ci', '7633685')
        ->assertJsonPath('meta.funcionario.nombre', 'IGNACIO MOLINA GUZMAN');
});

test('un funcionario=0 de antes se ignora sin error', function (): void {
    $this->getJson('/api/v1/funcionarios/7633685/marcaciones?desde=2026-10-01&hasta=2026-10-27&funcionario=0')
        ->assertSuccessful()
        ->assertJsonMissingPath('meta.funcionario');
});
