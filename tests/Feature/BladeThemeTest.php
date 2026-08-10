<?php

use App\Models\Equipo;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(asSuperAdmin());
});

test('el sitio Blade usa la paleta petróleo institucional', function () {
    $response = $this->get(route('equipos.index'));

    $response->assertSuccessful();
    $response->assertSee('--sidebar: #0d3b3e', escape: false);
    $response->assertSee('--sidebar-header: #082628', escape: false);
    $response->assertSee('--sidebar-hover: #164e52', escape: false);
    $response->assertSee('--sidebar-activo: #1c6266', escape: false);
    $response->assertSee('--thead: #0d3b3e', escape: false);
});

test('la topbar trae el botón para mostrar u ocultar el menú', function () {
    $this->get(route('equipos.index'))
        ->assertSuccessful()
        ->assertSee('class="topbar__toggle" x-on:click="alternarMenu()"', escape: false)
        ->assertSee('aria-label="Mostrar u ocultar el menú"', escape: false)
        // Visible también en escritorio: antes solo aparecía por debajo de 960px.
        ->assertSee('.topbar__toggle { display: inline-flex;', escape: false);
});

test('plegado, el menú queda en una franja de iconos', function () {
    $this->get(route('equipos.index'))
        ->assertSuccessful()
        ->assertSee('--sidebar-rail: 3.5rem', escape: false)
        ->assertSee('body.sidebar-plegado .sidebar { width: var(--sidebar-rail); }', escape: false)
        ->assertSee('body.sidebar-plegado .zona { margin-left: var(--sidebar-rail); }', escape: false)
        // El texto de cada opción se oculta; el icono y su `title` se quedan.
        ->assertSee('body.sidebar-plegado .sidebar__texto,', escape: false)
        ->assertSee('<span class="sidebar__texto">Funcionarios</span>', escape: false)
        ->assertSee('title="Funcionarios"', escape: false);
});

test('el menú plegado se recuerda entre pantallas', function () {
    $this->get(route('equipos.index'))
        ->assertSuccessful()
        ->assertSee("sidebarPlegado: localStorage.getItem('sidebarPlegado') === '1'", escape: false)
        ->assertSee("localStorage.setItem('sidebarPlegado', this.sidebarPlegado ? '1' : '0')", escape: false);
});

test('la topbar trae el menú de la cuenta con el usuario y el cierre de sesión', function () {
    $usuario = asSuperAdmin();
    $usuario->update(['name' => 'Ignacio Molina', 'email' => 'ignacio@beni.gob.bo']);
    $this->actingAs($usuario);

    $this->get(route('equipos.index'))
        ->assertSuccessful()
        ->assertSee('Ignacio Molina')
        ->assertSee('ignacio@beni.gob.bo')
        ->assertSee('super_admin')
        // Sin foto de perfil, el avatar son las iniciales.
        ->assertSee('>IM<', escape: false)
        ->assertSee('Cerrar sesión')
        ->assertSee('action="'.route('logout').'"', escape: false);
});

test('la topbar ya no ofrece volver al panel de Filament', function () {
    $this->get(route('equipos.index'))
        ->assertSuccessful()
        ->assertDontSee('Volver al panel')
        ->assertDontSee('href="/admin"', escape: false);
});

test('el menú marca la opción de la pantalla actual', function () {
    $this->get(route('equipos.index'))
        ->assertSuccessful()
        ->assertSee('class="sidebar__link activo" title="Biométricos" aria-current="page"', escape: false);
});

test('la marca del menú se mantiene en las pantallas internas del módulo', function () {
    // Ficha, alta y edición son otras rutas del mismo módulo: la opción del
    // menú tiene que seguir marcada.
    $equipo = Equipo::factory()->create();

    foreach ([route('equipos.create'), route('equipos.show', $equipo), route('equipos.edit', $equipo)] as $url) {
        $this->get($url)
            ->assertSuccessful()
            ->assertSee('aria-current="page"', escape: false);
    }
});

test('el grupo Parámetros se despliega y se marca en cualquiera de sus opciones', function () {
    foreach ([route('dias-excepcionales.index'), route('horarios.index'), route('licencias.index')] as $url) {
        $this->get($url)
            ->assertSuccessful()
            ->assertSee('x-data="{ abierto: true }"', escape: false)
            ->assertSee('class="sidebar__grouptoggle activo"', escape: false)
            ->assertSee('class="sidebar__sublink activo" title=', escape: false)
            ->assertSee('aria-current="page"', escape: false);
    }
});

test('el grupo Parámetros queda plegado y sin marcar fuera de sus opciones', function () {
    $this->get(route('equipos.index'))
        ->assertSuccessful()
        ->assertSee('x-data="{ abierto: false }"', escape: false)
        ->assertDontSee('sidebar__grouptoggle activo', escape: false);
});
