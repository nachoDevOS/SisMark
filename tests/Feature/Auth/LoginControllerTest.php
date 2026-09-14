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

test('la contraseña trae el ojo para verla y volver a ocultarla', function () {
    $response = $this->get(route('login'))->assertOk();

    // El botón queda atado al campo y anuncia su estado apagado.
    $response->assertSee('id="verClave"', escape: false);
    $response->assertSee('aria-controls="password"', escape: false);
    $response->assertSee('aria-pressed="false"', escape: false);
    $response->assertSee('aria-label="Mostrar la contraseña"', escape: false);

    // Los dos iconos viajan en el HTML; el de ocultar arranca escondido.
    $response->assertSee('data-ojo="mostrar"', escape: false);
    $response->assertSee('data-ojo="ocultar"', escape: false);

    // El de ocultar es el ojo tachado: sin la raja se confunde con el otro.
    $response->assertSee('<line x1="3.5" y1="3.5" x2="20.5" y2="20.5"/>', escape: false);

    // El campo sigue naciendo tapado: el ojo lo destapa, no al revés.
    $response->assertSee('<input type="password" id="password"', escape: false);

    // Es un botón suelto, no envía el formulario al tocarlo.
    $response->assertSee('<button type="button" class="clave__ojo"', escape: false);

    // El cambio es del navegador: la contraseña no sale a ningún lado.
    $response->assertSee("campo.type = visible ? 'password' : 'text';", escape: false);

    // Los <svg> no heredan `.hidden` de HTMLElement: asignarla no toca el DOM
    // y el icono se queda clavado en el ojo abierto. Se cambia el atributo.
    $response->assertSee("iconoMostrar.toggleAttribute('hidden', !visible);", escape: false);
    $response->assertSee("iconoOcultar.toggleAttribute('hidden', visible);", escape: false);
    $response->assertDontSee('iconoMostrar.hidden =', escape: false);
    $response->assertDontSee('iconoOcultar.hidden =', escape: false);
});

test('el ingreso enlaza los otros sistemas del Gobierno Departamental', function () {
    $response = $this->get(route('login'))->assertOk();

    $portales = [
        'https://beni.gob.bo/' => 'Portal del Beni',
        'https://mamore.beni.gob.bo/' => 'Mamoré',
        'https://siscor.beni.gob.bo/' => 'SisCor',
        'https://almacen.beni.gob.bo/' => 'Almacenes',
        'https://mineria.beni.gob.bo/' => 'Minería',
        'https://auditoria.beni.gob.bo/' => 'Auditoría',
        'https://gaceta.beni.gob.bo/' => 'Gaceta',
    ];

    foreach ($portales as $url => $nombre) {
        $response->assertSee('href="'.$url.'"', escape: false);
        $response->assertSee($nombre);
    }

    // Salen a otro sitio: se abren aparte y sin arrastrar la sesión de acá.
    $response->assertSee('target="_blank" rel="noopener noreferrer"', escape: false);
});
