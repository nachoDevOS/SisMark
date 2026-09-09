<?php

use App\Services\ResolutorNombres;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('resuelve todas las cédulas de la página en un solo pool', function () {
    fakeMamore([
        '111' => 'Ana Perez',
        '222' => 'Luis Roca',
        '333' => 'Eva Diaz',
    ]);

    $fichas = app(ResolutorNombres::class)->fichasPorCi(['111', '222', '333']);

    expect(array_column($fichas, 'nombre'))->toBe(['Ana Perez', 'Luis Roca', 'Eva Diaz']);

    // Una consulta por cédula, pero todas en el mismo pool: en serie, con la
    // caché fría, eran ~250 ms cada una una atrás de la otra.
    Http::assertSentCount(3);
});

test('no vuelve a preguntar por las cédulas que ya están en caché', function () {
    fakeMamore(['111' => 'Ana Perez', '222' => 'Luis Roca']);

    $resolutor = app(ResolutorNombres::class);
    $resolutor->fichasPorCi(['111']);

    Http::assertSentCount(1);

    // La segunda vuelta solo pregunta por la cédula nueva.
    $fichas = $resolutor->fichasPorCi(['111', '222']);

    expect(array_column($fichas, 'nombre'))->toBe(['Ana Perez', 'Luis Roca']);

    Http::assertSentCount(2);
});

test('una cédula que Mamoré no tiene cae en la base local y no se vuelve a pedir', function () {
    DB::table('personas')->insert([
        'ci' => '444', 'paterno' => 'Solo', 'materno' => null, 'nombres' => 'Local', 'pinReloj' => null, 'marcaDirecta' => false,
    ]);

    fakeMamore(['111' => 'Ana Perez']);

    $resolutor = app(ResolutorNombres::class);

    expect($resolutor->fichasPorCi(['444'])['444']['origen'])->toBe('siat');

    Http::assertSentCount(1);

    // El 404 queda cacheado: la segunda vuelta no sale a la red.
    $resolutor->fichasPorCi(['444']);

    Http::assertSentCount(1);
});

test('un fallo de la API no se cachea y se reintenta en la carga siguiente', function () {
    // La API se configura a mano y no con fakeMamore(): los stubs se acumulan y
    // gana el primero que matchea, así que el padrón falso taparía la caída.
    config()->set('services.mamore.url', 'http://mamore.test/api/externo/personal');
    config()->set('services.mamore.token', 'secreta');

    Http::fake(['mamore.test/*' => Http::response('', 500)]);

    $resolutor = app(ResolutorNombres::class);

    expect($resolutor->fichasPorCi(['111'])['111'])->toBeNull()
        ->and(Cache::has('mamore.ficha.111'))->toBeFalse();

    $resolutor->fichasPorCi(['111']);

    Http::assertSentCount(2);
});

test('sin Mamoré configurada resuelve todo desde la base local sin salir a la red', function () {
    DB::table('personas')->insert([
        'ci' => '444', 'paterno' => 'Solo', 'materno' => null, 'nombres' => 'Local', 'pinReloj' => null, 'marcaDirecta' => false,
    ]);

    Http::fake();

    $fichas = app(ResolutorNombres::class)->fichasPorCi(['444']);

    expect($fichas['444']['origen'])->toBe('siat');

    Http::assertNothingSent();
});
