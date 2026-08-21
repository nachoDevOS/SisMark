<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Sesión vencida durante un AJAX
|--------------------------------------------------------------------------
|
| Todas las tablas del sistema se cargan por `fetch` y se inyectan con
| `innerHTML`. Si al vencer la sesión el servidor contesta con la redirección
| al login, `fetch` la sigue sin avisar y la pantalla de ingreso queda dibujada
| adentro de la tabla: el formulario aparece donde iban los datos, con el
| sidebar y el nombre del usuario todavía en pantalla.
|
| La respuesta a un AJAX sin sesión tiene que ser un 401 para que el navegador
| pueda enterarse y recargar hacia el login.
|
*/

test('un AJAX sin sesión responde 401 y no la pantalla de login', function () {
    $this->getJson(route('funcionarios.marcaciones.list', ['ci' => '7633685']))
        ->assertUnauthorized()
        // Lo que nunca puede volver: el formulario de ingreso servido como si
        // fuera el contenido de la tabla.
        ->assertDontSee('Correo electrónico')
        ->assertDontSee('name="password"', escape: false);
});

test('todas las tablas por AJAX de la ficha responden 401 sin sesión', function (string $ruta) {
    $this->withHeader('X-Requested-With', 'XMLHttpRequest')
        ->get(route($ruta, ['ci' => '7633685']))
        ->assertUnauthorized();
})->with([
    'funcionarios.marcaciones.list',
    'funcionarios.licencias.list',
    'funcionarios.turnos.list',
    'funcionarios.list',
]);

test('las tres formas en que el sistema pide por AJAX reciben 401', function (array $cabeceras) {
    // Las diecisiete llamadas del sistema no se anuncian todas igual, y la que
    // trae una tabla ya armada pide `text/html`, que sola es indistinguible de
    // una visita: ahí el que la delata es el `Sec-Fetch-Dest` del navegador.
    $this->withHeaders($cabeceras)
        ->get(route('reportes.marcaciones.procesado.generar', ['persona' => '7633685']))
        ->assertUnauthorized();
})->with([
    'X-Requested-With' => [['X-Requested-With' => 'XMLHttpRequest']],
    'Accept: application/json' => [['Accept' => 'application/json']],
    'Accept: text/html desde un script' => [['Accept' => 'text/html', 'Sec-Fetch-Dest' => 'empty']],
]);

test('una visita normal del navegador sigue yendo al login', function () {
    // La redirección de toda la vida es lo correcto acá, y es la que guarda a
    // dónde quería ir el usuario para devolverlo apenas entra.
    $this->withHeader('Sec-Fetch-Dest', 'document')
        ->get(route('funcionarios.index'))
        ->assertRedirect(route('login'));

    expect(session('url.intended'))->toBe(route('funcionarios.index'));
});

test('un navegador que no manda Sec-Fetch sigue yendo al login', function () {
    // Sin la cabecera no se puede distinguir el origen, y ahí la redirección es
    // el comportamiento seguro: se pierde el arreglo, no la posibilidad de
    // entrar.
    $this->get(route('funcionarios.index'))
        ->assertRedirect(route('login'));
});

test('un error que no es de sesión sigue devolviendo su página HTML', function () {
    // El callback de `shouldRenderJsonWhen` reemplaza al `expectsJson()` de
    // Laravel, así que se comprueba que solo cambió la autenticación: un 404
    // en un AJAX del sitio no se convierte en JSON.
    $this->actingAs(asSuperAdmin())
        ->withHeader('X-Requested-With', 'XMLHttpRequest')
        ->get('/una-ruta-que-no-existe')
        ->assertNotFound()
        ->assertHeader('content-type', 'text/html; charset=UTF-8');
});

test('la API sigue contestando JSON sin la clave compartida', function () {
    // `api/*` ya devolvía JSON antes de este arreglo y tiene que seguir
    // haciéndolo: es lo que consume Mamoré.
    $this->getJson('/api/v1/funcionarios/7633685/asistencia')
        ->assertUnauthorized()
        ->assertHeader('content-type', 'application/json');
});

test('el layout instala el aviso de sesión vencida sobre fetch', function () {
    $this->actingAs(asSuperAdmin())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('window.fetch = async function', escape: false)
        // 401 por sesión vencida, 419 por token de CSRF viejo.
        ->assertSee('resp.status !== 401 && resp.status !== 419', escape: false)
        ->assertSee('window.location.reload()', escape: false)
        ->assertSee('La sesión expiró. Redirigiendo al ingreso…', escape: false);
});
