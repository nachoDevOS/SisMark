<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

test('muestra el formulario de login', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Entrar');
});

test('inicia sesión con credenciales correctas', function () {
    $usuario = User::factory()->create(['password' => Hash::make('clave-correcta')]);

    $this->post(route('login'), [
        'email' => $usuario->email,
        'password' => 'clave-correcta',
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($usuario);
});

test('rechaza credenciales incorrectas', function () {
    $usuario = User::factory()->create(['password' => Hash::make('clave-correcta')]);

    $this->post(route('login'), [
        'email' => $usuario->email,
        'password' => 'clave-mala',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('cierra sesión', function () {
    $usuario = User::factory()->create();

    $this->actingAs($usuario)
        ->post(route('logout'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

test('un usuario logueado no puede ver el formulario de login', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('login'))->assertRedirect();
});

test('el ingreso se presenta como pantalla institucional del Gobierno del Beni', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Gobierno Autónomo Departamental del Beni')
        ->assertSee('Trinidad · Beni · Bolivia')
        ->assertSee('Sistema de sincronización de')
        // La paleta petróleo es la misma que la del sitio ya adentro.
        ->assertSee('--sidebar: #0d3b3e', escape: false)
        ->assertSee('--sidebar-header: #082628', escape: false);
});

test('el ingreso enlaza los otros sistemas del Gobierno Departamental', function () {
    $response = $this->get(route('login'))->assertOk();

    $portales = [
        'https://beni.gob.bo/' => 'Portal del Beni',
        'https://mamore.beni.gob.bo/' => 'Mamoré',
        'https://siscor.beni.gob.bo/' => 'SisCor',
        'https://almacen.beni.gob.bo/' => 'Almacenes',
        'https://gaceta.beni.gob.bo/' => 'Gaceta',
    ];

    foreach ($portales as $url => $nombre) {
        $response->assertSee('href="'.$url.'"', escape: false);
        $response->assertSee($nombre);
    }

    // Salen a otro sitio: se abren aparte y sin arrastrar la sesión de acá.
    $response->assertSee('target="_blank" rel="noopener noreferrer"', escape: false);
});
