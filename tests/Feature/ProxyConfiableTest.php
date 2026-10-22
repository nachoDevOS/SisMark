<?php

use Illuminate\Support\Facades\Route;

/*
| El HTTPS lo termina el proxy de adelante (Traefik en Coolify, desde la red
| privada de Docker; un túnel local en desarrollo). Laravel tiene que creerle el
| `X-Forwarded-Proto` a esos orígenes —si no, arma las URL en http y el
| navegador bloquea los AJAX— y a ningún otro.
*/
beforeEach(function (): void {
    Route::get('/_prueba-esquema', fn () => url('/'));
});

test('confía en el proxy de la red privada y arma las URL en https', function (string $ip): void {
    $this->withServerVariables(['REMOTE_ADDR' => $ip])
        ->withHeader('X-Forwarded-Proto', 'https')
        ->get('/_prueba-esquema')
        ->assertSee('https://', false);
})->with([
    'Traefik en la red de Docker' => '172.18.0.5',
    'red 10.x' => '10.0.1.20',
    'red 192.168.x' => '192.168.1.10',
    'túnel local' => '127.0.0.1',
]);

test('no le cree el X-Forwarded-Proto a una IP de internet', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '200.87.10.1'])
        ->withHeader('X-Forwarded-Proto', 'https')
        ->get('/_prueba-esquema')
        ->assertDontSee('https://', false);
});
