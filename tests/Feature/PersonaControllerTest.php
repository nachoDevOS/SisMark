<?php

use App\Models\AsignacionTurno;
use App\Models\Asistencia;
use App\Models\Licencia;
use App\Models\Persona;
use App\Models\Profesion;
use App\Models\Role;
use App\Models\Turno;
use App\Models\User;
use App\Services\DirectorioMamore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(asSuperAdmin());
});

test('el listado SIAT muestra los funcionarios locales', function () {
    Persona::factory()->create([
        'ci' => '12345678',
        'paterno' => 'Perez',
        'materno' => 'Gomez',
        'nombres' => 'Juan',
    ]);

    $this->get(route('funcionarios.list', ['fuente' => 'siat']))
        ->assertOk()
        ->assertSee('Perez')
        ->assertSee('12345678');
});

test('la búsqueda SIAT filtra por nombre', function () {
    Persona::factory()->create(['ci' => '1', 'paterno' => 'Alfa', 'nombres' => 'Ana']);
    Persona::factory()->create(['ci' => '2', 'paterno' => 'Beta', 'nombres' => 'Beto']);

    $this->get(route('funcionarios.list', ['fuente' => 'siat', 'q' => 'Alfa']))
        ->assertOk()
        ->assertSee('Alfa')
        ->assertDontSee('Beta');
});

test('la búsqueda SIAT por varias palabras cruza nombre y apellido', function () {
    Persona::factory()->create(['ci' => '10', 'paterno' => 'Molina', 'materno' => 'Guzman', 'nombres' => 'Ignacio']);
    Persona::factory()->create(['ci' => '20', 'paterno' => 'Perez', 'materno' => 'Rojas', 'nombres' => 'Ignacio']);

    // "ignacio m" debe encontrar a Ignacio Molina (nombres + paterno en
    // columnas distintas) y dejar fuera a Ignacio Perez.
    $this->get(route('funcionarios.list', ['fuente' => 'siat', 'q' => 'ignacio m']))
        ->assertOk()
        ->assertSee('Molina')
        ->assertDontSee('Perez');
});

test('el listado por defecto usa Mamoré y muestra sus personas', function () {
    config()->set('services.mamore.url', 'http://mamore.test/api/personal');
    config()->set('services.mamore.key', 'secreta');

    Http::fake([
        'mamore.test/api/personal/people*' => Http::response([
            'data' => [
                ['id' => 25, 'ci' => '7654321', 'paternal_surname' => 'Perez', 'maternal_surname' => 'Gomez', 'first_name' => 'Juan', 'middle_name' => 'Carlos'],
            ],
            'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 10, 'total' => 1],
        ], 200),
    ]);

    $this->get(route('funcionarios.list'))
        ->assertOk()
        ->assertSee('Perez')
        ->assertSee('7654321')
        ->assertSee('Juan Carlos');

    Http::assertSent(fn ($request) => $request->hasHeader('X-API-KEY', 'secreta')
        && str_contains($request->url(), '/people'));
});

test('el listado muestra la foto que entrega Mamoré', function () {
    config()->set('services.mamore.url', 'http://mamore.test/api/personal');
    config()->set('services.mamore.key', 'secreta');

    Http::fake([
        'mamore.test/api/personal/people*' => Http::response([
            'data' => [
                ['id' => 25, 'ci' => '7654321', 'paternal_surname' => 'Perez', 'maternal_surname' => 'Gomez',
                    'first_name' => 'Juan', 'middle_name' => 'Carlos',
                    'image' => 'https://cdn.test/people/juan.png'],
            ],
            'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 10, 'total' => 1],
        ], 200),
    ]);

    $this->get(route('funcionarios.list'))
        ->assertOk()
        // El avatar y su zoom usan la miniatura; la original solo es el respaldo.
        ->assertSee('src="https://cdn.test/people/juan-cropped.png"', false)
        ->assertSee('alt="Foto de Juan Carlos Perez Gomez"', false)
        ->assertSee('persona-zoom', false)
        ->assertSee("this.src='https://cdn.test/people/juan.png'", false);
});

test('la miniatura del avatar no toca los puntos del dominio de la URL', function () {
    $directorio = app(DirectorioMamore::class);

    $fila = $directorio->normalizarPersona([
        'ci' => '1', 'first_name' => 'Ana',
        'image' => 'https://gadbeni.sfo3.digitaloceanspaces.com/sysadmin/prod/people/ana.png',
    ]);

    expect($fila['imageThumb'])
        ->toBe('https://gadbeni.sfo3.digitaloceanspaces.com/sysadmin/prod/people/ana-cropped.png');
});

test('una foto sin extensión reconocible se sirve tal cual', function () {
    $directorio = app(DirectorioMamore::class);

    $fila = $directorio->normalizarPersona([
        'ci' => '1', 'first_name' => 'Ana', 'image' => 'https://cdn.test/people/ana',
    ]);

    expect($fila['imageThumb'])->toBe('https://cdn.test/people/ana');
});

test('la ficha de Mamoré muestra la foto original del funcionario', function () {
    config()->set('services.mamore.url', 'http://mamore.test/api/personal');
    config()->set('services.mamore.key', 'secreta');

    Http::fake([
        'mamore.test/api/personal/people/ci/*' => Http::response([
            'data' => ['ci' => '7654321', 'full_name' => 'Juan Perez', 'image' => 'https://cdn.test/people/juan.png'],
        ], 200),
    ]);

    $this->get(route('funcionarios.mamore', ['ci' => '7654321']))
        ->assertOk()
        ->assertSee('ficha-foto', false)
        ->assertSee('src="https://cdn.test/people/juan.png"', false);
});

test('la ficha de Mamoré cae en el ícono genérico si la persona no tiene foto', function () {
    config()->set('services.mamore.url', 'http://mamore.test/api/personal');
    config()->set('services.mamore.key', 'secreta');

    Http::fake([
        'mamore.test/api/personal/people/ci/*' => Http::response([
            'data' => ['ci' => '7654321', 'full_name' => 'Juan Perez', 'image' => null],
        ], 200),
    ]);

    // El layout trae su propio <img> (el avatar de la cuenta), así que se
    // comprueba que no haya foto de la persona, no que no haya imágenes.
    $this->get(route('funcionarios.mamore', ['ci' => '7654321']))
        ->assertOk()
        ->assertSee('ficha-foto', false)
        ->assertDontSee('alt="Foto de', false);
});

test('el listado cae en el ícono genérico cuando la persona de Mamoré no tiene foto', function () {
    config()->set('services.mamore.url', 'http://mamore.test/api/personal');
    config()->set('services.mamore.key', 'secreta');

    Http::fake([
        'mamore.test/api/personal/people*' => Http::response([
            'data' => [
                ['id' => 26, 'ci' => '1111111', 'paternal_surname' => 'Rojas', 'first_name' => 'Ana', 'image' => null],
            ],
            'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 10, 'total' => 1],
        ], 200),
    ]);

    $this->get(route('funcionarios.list'))
        ->assertOk()
        ->assertSee('Rojas')
        ->assertDontSee('<img src=', false);
});

test('el listado SIAT no rompe por la foto: siempre muestra el ícono genérico', function () {
    Persona::factory()->create(['ci' => '999', 'paterno' => 'Vaca', 'nombres' => 'Luis']);

    $this->get(route('funcionarios.list', ['fuente' => 'siat']))
        ->assertOk()
        ->assertSee('Vaca')
        ->assertDontSee('<img src=', false);
});

test('el filtro «sin contrato» le pide a la API el listado de personas sin contrato', function () {
    config()->set('services.mamore.url', 'http://mamore.test/api/personal');
    config()->set('services.mamore.key', 'secreta');

    Http::fake([
        'mamore.test/api/personal/people*' => Http::response([
            'data' => [
                ['id' => 9, 'ci' => '999', 'full_name' => 'PERSONA SIN CONTRATO', 'has_contract' => false],
            ],
            'meta' => ['current_page' => 1, 'per_page' => 10, 'total' => 1, 'contrato' => 'sin'],
        ], 200),
    ]);

    $this->get(route('funcionarios.list', ['contrato' => 'sin']))
        ->assertOk()
        ->assertSee('PERSONA SIN CONTRATO')
        // Sin contrato no hay cargo ni dirección que mostrar.
        ->assertSee('<td>—</td>', false);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/people')
        && str_contains($request->url(), 'contrato=sin'));
});

test('el listado publica los totales por situación de contrato para el select', function () {
    config()->set('services.mamore.url', 'http://mamore.test/api/personal');
    config()->set('services.mamore.key', 'secreta');

    Http::fake([
        'mamore.test/api/personal/people*' => Http::response([
            'data' => [['id' => 1, 'ci' => '111', 'full_name' => 'CUALQUIERA', 'has_contract' => true]],
            'meta' => [
                'current_page' => 1, 'per_page' => 10, 'total' => 4595,
                'total_con_contrato' => 1040, 'total_sin_contrato' => 3555,
            ],
        ], 200),
    ]);

    $this->get(route('funcionarios.list'))
        ->assertOk()
        ->assertSee('data-con="1040"', false)
        ->assertSee('data-sin="3555"', false)
        // «Todos» es la suma de las dos situaciones.
        ->assertSee('data-todos="4595"', false);
});

test('la lista muestra juntas a las personas con contrato y sin contrato', function () {
    config()->set('services.mamore.url', 'http://mamore.test/api/personal');
    config()->set('services.mamore.key', 'secreta');

    // Un único listado: la persona con contrato lo trae embebido, la que no
    // tiene viene con `contrato: null` y aparece igual en la tabla.
    Http::fake([
        'mamore.test/api/personal/people*' => Http::response([
            'data' => [
                [
                    'id' => 4772,
                    'ci' => '7604314',
                    'full_name' => 'LUIS CARLOS ALPIRE DURAN',
                    'has_contract' => true,
                    'contrato' => [
                        'id' => 16437,
                        'code' => 'SDAF-132/2026',
                        'cargo_completo' => 'APOYO ADMINISTRATIVO - (Analista II)',
                        'direccion_administrativa' => ['id' => 16, 'nombre' => 'Secretaria Departamental de Administracion y Finanzas', 'sigla' => 'SDAF'],
                    ],
                ],
                [
                    'id' => 500,
                    'ci' => '1938650',
                    'full_name' => 'CLAUDIA VARGAS',
                    'has_contract' => false,
                    'contrato' => null,
                ],
            ],
            'meta' => ['current_page' => 1, 'per_page' => 10, 'total' => 2, 'total_con_contrato' => 1, 'total_sin_contrato' => 1],
        ], 200),
    ]);

    $this->get(route('funcionarios.list'))
        ->assertOk()
        // La que tiene contrato, con su cargo y su dirección.
        ->assertSee('LUIS CARLOS ALPIRE DURAN')
        ->assertSee('APOYO ADMINISTRATIVO - (Analista II)')
        ->assertSee('SDAF')
        ->assertSee('Con contrato')
        // La que no tiene, igual en la lista.
        ->assertSee('CLAUDIA VARGAS')
        ->assertSee('Sin contrato');

    // Una sola petición, al único listado de la API.
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/people'));
});

test('el filtro «con contrato» le pide a la API solo los que tienen contrato', function () {
    config()->set('services.mamore.url', 'http://mamore.test/api/personal');
    config()->set('services.mamore.key', 'secreta');

    Http::fake([
        'mamore.test/api/personal/people*' => Http::response([
            'data' => [
                [
                    'id' => 4772,
                    'ci' => '7604314',
                    'full_name' => 'LUIS CARLOS ALPIRE DURAN',
                    'has_contract' => true,
                    'contrato' => [
                        'cargo_completo' => 'APOYO ADMINISTRATIVO - (Analista II)',
                        'direccion_administrativa' => ['sigla' => 'SDAF'],
                    ],
                ],
            ],
            'meta' => ['current_page' => 1, 'per_page' => 10, 'total' => 1040, 'total_con_contrato' => 1040, 'total_sin_contrato' => 3555],
        ], 200),
    ]);

    $this->get(route('funcionarios.list', ['contrato' => 'con']))
        ->assertOk()
        ->assertSee('LUIS CARLOS ALPIRE DURAN')
        ->assertSee('APOYO ADMINISTRATIVO - (Analista II)')
        ->assertSee('SDAF');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/people')
        && str_contains($request->url(), 'contrato=con'));
});

test('el filtro por contrato no aplica a la fuente SIAT', function () {
    Persona::factory()->create(['ci' => '333', 'paterno' => 'Local', 'nombres' => 'Sin Contratos']);

    Http::fake();

    $this->get(route('funcionarios.list', ['fuente' => 'siat', 'contrato' => 'con']))
        ->assertOk()
        ->assertSee('Local');

    Http::assertNothingSent();
});

test('un valor raro en el filtro de contrato cae en «todos»', function () {
    config()->set('services.mamore.url', 'http://mamore.test/api/personal');
    config()->set('services.mamore.key', 'secreta');

    Http::fake([
        'mamore.test/api/personal/people*' => Http::response([
            'data' => [['id' => 1, 'ci' => '111', 'full_name' => 'CUALQUIERA']],
            'meta' => ['current_page' => 1, 'per_page' => 10, 'total' => 1],
        ], 200),
    ]);

    $this->get(route('funcionarios.list', ['contrato' => 'inventado']))->assertOk();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/people')
        && ! str_contains($request->url(), 'contrato='));
});

test('la búsqueda Mamoré por varias palabras se delega entera a la API', function () {
    config()->set('services.mamore.url', 'http://mamore.test/api/personal');
    config()->set('services.mamore.key', 'secreta');

    Http::fake([
        'mamore.test/api/personal/people*' => Http::response([
            'data' => [
                ['id' => 1, 'ci' => '111', 'full_name' => 'SERGIO MILTON MORALES FLORES'],
            ],
            'meta' => ['total' => 1, 'per_page' => 10, 'current_page' => 1],
        ], 200),
    ]);

    $this->get(route('funcionarios.list', ['q' => 'milton morales']))
        ->assertOk()
        ->assertSee('SERGIO MILTON MORALES FLORES');

    // Las dos palabras viajan juntas: filtrar de este lado obligaba a traer un
    // lote acotado y perdía a quien cayera fuera de él.
    Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), 'search=milton morales'));
});

test('la búsqueda Mamoré de varias palabras pide una sola página, no un lote grande', function () {
    config()->set('services.mamore.url', 'http://mamore.test/api/personal');
    config()->set('services.mamore.key', 'secreta');

    Http::fake([
        'mamore.test/api/personal/people*' => Http::response([
            'data' => [],
            'meta' => ['total' => 0, 'per_page' => 25, 'current_page' => 1],
        ], 200),
    ]);

    $this->get(route('funcionarios.list', ['q' => 'maria rene', 'por_pagina' => 25]))->assertOk();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'limit=25')
        && ! str_contains($request->url(), 'limit=100'));
});

test('la fuente Mamoré avisa si la API responde con error', function () {
    config()->set('services.mamore.url', 'http://mamore.test/api/personal');
    config()->set('services.mamore.key', 'secreta');

    Http::fake(['mamore.test/*' => Http::response(['message' => 'no'], 401)]);

    $this->get(route('funcionarios.list'))
        ->assertOk()
        ->assertSee('La clave de la API de Mamoré es inválida');
});

test('la fuente Mamoré avisa si no está configurada', function () {
    config()->set('services.mamore.url', null);
    config()->set('services.mamore.key', null);

    $this->get(route('funcionarios.list'))
        ->assertOk()
        ->assertSee('no está configurada');
});

test('la ficha de una persona de Mamoré se ve por cédula', function () {
    config()->set('services.mamore.url', 'http://mamore.test/api/personal');
    config()->set('services.mamore.key', 'secreta');

    Http::fake([
        'mamore.test/api/personal/people/ci/*' => Http::response([
            'data' => [
                'id' => 25, 'full_name' => 'Juan Carlos Perez Gomez', 'ci' => '7654321',
                'full_ci' => '7654321-BE', 'phone' => '70000000', 'email' => 'juan@example.com',
            ],
        ], 200),
    ]);

    $this->get(route('funcionarios.mamore', ['ci' => '7654321']))
        ->assertOk()
        ->assertSee('Juan Carlos Perez Gomez')
        ->assertSee('7654321-BE');
});

test('la ficha de Mamoré muestra el contrato vigente del funcionario', function () {
    config()->set('services.mamore.url', 'http://mamore.test/api/personal');
    config()->set('services.mamore.key', 'secreta');

    Http::fake([
        'mamore.test/api/personal/people/ci/*' => Http::response([
            'data' => [
                'id' => 4772, 'full_name' => 'LUIS CARLOS ALPIRE DURAN', 'ci' => '7604314',
                'has_contract' => true,
                'contrato' => [
                    'code' => 'SDAF-132/2026',
                    'denominacion' => 'APOYO ADMINISTRATIVO',
                    'cargo_completo' => 'APOYO ADMINISTRATIVO - (Analista II)',
                    'direccion_administrativa' => ['nombre' => 'Secretaria Departamental de Administracion y Finanzas', 'sigla' => 'SDAF'],
                    'unidad_administrativa' => ['nombre' => 'DIRECCIÓN DPTAL. DE RECURSOS HUMANOS', 'sigla' => 'DRRHH'],
                    'procedure_type' => 'eventual',
                    'start' => '2026-07-14',
                    'finish' => '2026-12-31',
                ],
            ],
        ], 200),
    ]);

    $this->get(route('funcionarios.mamore', ['ci' => '7604314']))
        ->assertOk()
        ->assertSee('Contrato vigente')
        ->assertSee('APOYO ADMINISTRATIVO - (Analista II)')
        ->assertSee('DRRHH')
        ->assertSee('Con contrato');
});

test('la ficha de Mamoré avisa cuando la persona no tiene contrato', function () {
    config()->set('services.mamore.url', 'http://mamore.test/api/personal');
    config()->set('services.mamore.key', 'secreta');

    Http::fake([
        'mamore.test/api/personal/people/ci/*' => Http::response([
            'data' => ['id' => 500, 'full_name' => 'CLAUDIA VARGAS', 'ci' => '1938650', 'has_contract' => false, 'contrato' => null],
        ], 200),
    ]);

    $this->get(route('funcionarios.mamore', ['ci' => '1938650']))
        ->assertOk()
        ->assertSee('CLAUDIA VARGAS')
        ->assertSee('Sin contrato')
        ->assertSee('no tiene un contrato firmado en Mamoré')
        ->assertDontSee('Contrato vigente');
});

test('la ficha de Mamoré trae el panel AJAX de marcaciones con esa cédula', function () {
    config()->set('services.mamore.url', 'http://mamore.test/api/personal');
    config()->set('services.mamore.key', 'secreta');

    Http::fake([
        'mamore.test/api/personal/people/ci/*' => Http::response([
            'data' => ['id' => 25, 'full_name' => 'IGNACIO MOLINA GUZMAN', 'ci' => '7633685'],
        ], 200),
    ]);

    $this->get(route('funcionarios.mamore', ['ci' => '7633685']))
        ->assertOk()
        ->assertSee('Marcaciones')
        ->assertSee('id="m-results"', false)
        ->assertSee('const ci = "7633685"', false);
});

test('la ficha de Mamoré ofrece el reporte imprimible solo si la cédula está en la base local', function () {
    config()->set('services.mamore.url', 'http://mamore.test/api/personal');
    config()->set('services.mamore.key', 'secreta');

    Http::fake([
        'mamore.test/api/personal/people/ci/*' => Http::response([
            'data' => ['id' => 25, 'full_name' => 'IGNACIO MOLINA GUZMAN', 'ci' => '7633685'],
        ], 200),
    ]);

    $this->get(route('funcionarios.mamore', ['ci' => '7633685']))
        ->assertOk()
        ->assertDontSee('Imprimir reporte');

    Persona::factory()->create(['ci' => '7633685']);

    $this->get(route('funcionarios.mamore', ['ci' => '7633685']))
        ->assertOk()
        ->assertSee('Imprimir reporte');
});

test('la ficha de Mamoré da 404 si la cédula no existe', function () {
    config()->set('services.mamore.url', 'http://mamore.test/api/personal');
    config()->set('services.mamore.key', 'secreta');

    Http::fake(['mamore.test/*' => Http::response(['message' => 'not found'], 404)]);

    $this->get(route('funcionarios.mamore', ['ci' => '000']))->assertNotFound();
});

test('un invitado no puede ver funcionarios', function () {
    auth()->logout();

    $this->get(route('funcionarios.index'))->assertRedirect();
});

test('la pantalla de funcionarios carga el shell del listado', function () {
    $this->get(route('funcionarios.index'))
        ->assertOk()
        ->assertSee('Funcionarios')
        ->assertSee('id="div-results"', false);
});

test('un usuario sin permiso no puede pedir el listado AJAX', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('funcionarios.list'))->assertForbidden();
});

test('muestra la ficha de detalle con datos', function () {
    $profesion = Profesion::factory()->create(['nombreProfesion' => 'CONTADOR GENERAL']);
    $persona = Persona::factory()->create([
        'ci' => '7778888',
        'paterno' => 'Detalle',
        'nombres' => 'Vista Completa',
        'codigoProfesion' => $profesion->codigoProfesion,
    ]);

    $this->get(route('funcionarios.show', $persona))
        ->assertOk()
        ->assertSee('Detalle')
        ->assertSee('Vista Completa')
        ->assertSee('CONTADOR GENERAL');
});

test('un usuario sin permiso no puede entrar al listado', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('funcionarios.index'))->assertForbidden();
});

test('la ficha trae el panel AJAX de marcaciones del funcionario', function () {
    $persona = Persona::factory()->create(['ci' => '7778888']);

    $this->get(route('funcionarios.show', $persona))
        ->assertOk()
        ->assertSee('Marcaciones')
        ->assertSee('id="m-results"', false)
        ->assertSee('const ci = "7778888"', false)
        ->assertSee('Imprimir reporte');
});

test('el listado AJAX muestra las marcaciones de la cédula dentro del rango por defecto', function () {
    Asistencia::factory()->create([
        'ci' => '7778888',
        'fecha' => today(),
        'hora' => '08:15:00',
        'tipo' => Asistencia::TIPO_RELOJ,
    ]);

    $this->get(route('funcionarios.marcaciones.list', ['ci' => '7778888']))
        ->assertOk()
        ->assertSee('08:15:00');
});

test('el listado AJAX no mezcla marcaciones de otra cédula', function () {
    Asistencia::factory()->create(['ci' => '7778888', 'fecha' => today(), 'hora' => '08:00:00']);
    Asistencia::factory()->create(['ci' => '1112222', 'fecha' => today(), 'hora' => '09:00:00']);

    $this->get(route('funcionarios.marcaciones.list', ['ci' => '7778888']))
        ->assertOk()
        ->assertSee('08:00:00')
        ->assertDontSee('09:00:00');
});

test('el listado AJAX de marcaciones filtra por rango de fechas y tipo', function () {
    Asistencia::factory()->create([
        'ci' => '7778888',
        'fecha' => today()->subMonths(3),
        'hora' => '07:00:00',
        'tipo' => Asistencia::TIPO_MANUAL,
    ]);
    Asistencia::factory()->create([
        'ci' => '7778888',
        'fecha' => today(),
        'hora' => '08:00:00',
        'tipo' => Asistencia::TIPO_RELOJ,
    ]);

    $this->get(route('funcionarios.marcaciones.list', ['ci' => '7778888']))
        ->assertOk()
        ->assertSee('08:00:00')
        ->assertDontSee('07:00:00');

    $this->get(route('funcionarios.marcaciones.list', [
        'ci' => '7778888',
        'desde' => today()->subMonths(4)->toDateString(),
        'hasta' => today()->toDateString(),
        'tipo' => Asistencia::TIPO_MANUAL,
    ]))
        ->assertOk()
        ->assertSee('07:00:00')
        ->assertDontSee('08:00:00');
});

test('el listado AJAX de marcaciones respeta el selector de registros por página', function () {
    Asistencia::factory()->count(30)->create(['ci' => '7778888', 'fecha' => today()]);

    // Sin pedir nada, 10 por página como el resto de los listados.
    $this->get(route('funcionarios.marcaciones.list', ['ci' => '7778888']))
        ->assertOk()
        ->assertViewHas('marcaciones', fn ($marcaciones): bool => $marcaciones->count() === 10);

    $this->get(route('funcionarios.marcaciones.list', ['ci' => '7778888', 'por_pagina' => 25]))
        ->assertOk()
        ->assertViewHas('marcaciones', fn ($marcaciones): bool => $marcaciones->count() === 25);
});

test('un usuario sin permiso no puede pedir las marcaciones por AJAX', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('funcionarios.marcaciones.list', ['ci' => '7778888']))->assertForbidden();
});

test('el reporte imprimible lista las marcaciones crudas del rango', function () {
    $persona = Persona::factory()->create([
        'ci' => '7633685',
        'paterno' => 'Molina',
        'materno' => 'Guzman',
        'nombres' => 'Ignacio',
        'pinReloj' => '7633685',
    ]);

    Asistencia::factory()->create([
        'ci' => $persona->ci,
        'fecha' => today(),
        'hora' => '08:15:00',
        'tipo' => Asistencia::TIPO_RELOJ,
    ]);
    Asistencia::factory()->create([
        'ci' => $persona->ci,
        'fecha' => today()->subYear(),
        'hora' => '07:00:00',
        'tipo' => Asistencia::TIPO_RELOJ,
    ]);

    $this->get(route('funcionarios.reporte', [
        'persona' => $persona,
        'desde' => today()->startOfMonth()->toDateString(),
        'hasta' => today()->toDateString(),
    ]))
        ->assertOk()
        ->assertSee('REPORTE DE MARCACIONES')
        ->assertSee('GOBIERNO AUTONOMO DEPARTAMENTAL DEL BENI')
        ->assertSee('Molina Guzman Ignacio')
        ->assertSeeText('PIN Reloj: 7633685')
        ->assertSee('08:15:00')
        ->assertDontSee('07:00:00')
        ->assertSee('Total registros:')
        ->assertSee('descarga directa desde reloj');
});

test('la ficha del funcionario trae las tres solapas del pie', function () {
    $persona = Persona::factory()->create(['ci' => '7633685']);

    $this->get(route('funcionarios.show', $persona))
        ->assertOk()
        ->assertSee('data-tab="marcaciones"', escape: false)
        ->assertSee('data-tab="licencias"', escape: false)
        ->assertSee('data-tab="turnos"', escape: false)
        ->assertSee('id="m-results"', escape: false)
        ->assertSee('id="l-results"', escape: false)
        ->assertSee('id="t-results"', escape: false);
});

test('la ficha de Mamoré trae las mismas solapas', function () {
    config()->set('services.mamore.url', 'http://mamore.test/api/personal');
    config()->set('services.mamore.key', 'secreta');

    Http::fake([
        'mamore.test/api/personal/people/ci/*' => Http::response([
            'data' => ['id' => 25, 'full_name' => 'Juan Carlos Perez Gomez', 'ci' => '7654321'],
        ], 200),
    ]);

    $this->get(route('funcionarios.mamore', ['ci' => '7654321']))
        ->assertOk()
        ->assertSee('data-tab="marcaciones"', escape: false)
        ->assertSee('data-tab="licencias"', escape: false)
        ->assertSee('data-tab="turnos"', escape: false)
        ->assertSee('const ci = "7654321"', escape: false);
});

test('el listado AJAX de turnos de la ficha lista los del funcionario', function () {
    $turno = Turno::factory()->create(['nombreTurno' => 'LUN: 08:00 - 16:00', 'dia' => '2']);
    AsignacionTurno::factory()->create(['ci' => '7633685', 'turno_id' => $turno->id]);

    $otro = Turno::factory()->create(['nombreTurno' => 'TURNO AJENO']);
    AsignacionTurno::factory()->create(['ci' => '1112222', 'turno_id' => $otro->id]);

    $this->get(route('funcionarios.turnos.list', ['ci' => '7633685']))
        ->assertOk()
        ->assertSee('LUN: 08:00 - 16:00')
        ->assertSee('Lunes')
        ->assertSee('Vigente')
        ->assertDontSee('TURNO AJENO');
});

test('el listado AJAX de turnos filtra por situación', function () {
    $vigente = Turno::factory()->create(['nombreTurno' => 'TURNO VIGENTE']);
    $viejo = Turno::factory()->create(['nombreTurno' => 'TURNO VIEJO']);

    AsignacionTurno::factory()->create(['ci' => '7633685', 'turno_id' => $vigente->id]);
    AsignacionTurno::factory()->vencida()->create(['ci' => '7633685', 'turno_id' => $viejo->id]);

    // Por defecto salen los dos, con el vigente primero.
    $this->get(route('funcionarios.turnos.list', ['ci' => '7633685']))
        ->assertOk()
        ->assertSee('TURNO VIGENTE')
        ->assertSee('TURNO VIEJO');

    $this->get(route('funcionarios.turnos.list', ['ci' => '7633685', 'situacion' => 'vigentes']))
        ->assertOk()
        ->assertSee('TURNO VIGENTE')
        ->assertDontSee('TURNO VIEJO');

    $this->get(route('funcionarios.turnos.list', ['ci' => '7633685', 'situacion' => 'vencidas']))
        ->assertOk()
        ->assertSee('TURNO VIEJO')
        ->assertSee('Vencida')
        ->assertDontSee('TURNO VIGENTE');
});

test('la solapa de licencias muestra las migradas del SIA, que se agrupan por id y no por solicitud', function () {
    Licencia::factory()->delSia()->count(3)->create([
        'ci' => '7633685',
        'motivo' => 'BAJA MEDICA DEL SIA',
    ]);

    $respuesta = $this->get(route('funcionarios.licencias.list', ['ci' => '7633685']))
        ->assertOk()
        ->assertDontSee('El funcionario no tiene licencias registradas.');

    // Las tres, no el total contándolas y la tabla vacía.
    expect(substr_count($respuesta->getContent(), 'BAJA MEDICA DEL SIA'))->toBe(3);
});

test('la solapa de licencias junta en una página las del SIA y las que son un pedido', function () {
    $solicitud = (string) Str::ulid();

    foreach (['2026-03-02', '2026-03-03'] as $fecha) {
        Licencia::factory()->create([
            'ci' => '7633685',
            'solicitud' => $solicitud,
            'fecha' => $fecha,
            'motivo' => 'PEDIDO DE VACACION',
        ]);
    }

    Licencia::factory()->delSia()->create([
        'ci' => '7633685',
        'fecha' => '2026-02-10',
        'motivo' => 'DIA SUELTO DEL SIA',
    ]);

    $this->get(route('funcionarios.licencias.list', ['ci' => '7633685']))
        ->assertOk()
        ->assertSee('PEDIDO DE VACACION')
        ->assertSee('DIA SUELTO DEL SIA');
});

test('el resumen de licencias indexa por su clave también las del SIA', function () {
    $delSia = Licencia::factory()->delSia()->create(['ci' => '7633685']);

    $resumen = Licencia::resumenDe([$delSia->clave_agrupadora]);

    // La clave es el id, y un id es numérico: es justo el caso que `merge()`
    // renumeraba y dejaba el período y los días en blanco.
    expect($resumen->has((string) $delSia->getKey()))->toBeTrue()
        ->and((int) $resumen->get((string) $delSia->getKey())->dias)->toBe(1);
});

test('el listado AJAX de turnos ordena por vigencia, de la más reciente a la más vieja', function () {
    $reciente = Turno::factory()->create(['nombreTurno' => 'TURNO RECIENTE', 'dia' => '6']);
    $viejo = Turno::factory()->create(['nombreTurno' => 'TURNO VIEJO', 'dia' => '2']);

    // El viejo cae antes pese a ser lunes: manda el período, no el día.
    AsignacionTurno::factory()->vencida()->create([
        'ci' => '7633685',
        'turno_id' => $reciente->id,
        'desde' => now()->subYear()->startOfDay(),
        'hasta' => now()->subMonths(6)->startOfDay(),
    ]);
    AsignacionTurno::factory()->vencida()->create([
        'ci' => '7633685',
        'turno_id' => $viejo->id,
        'desde' => now()->subYears(5)->startOfDay(),
        'hasta' => now()->subYears(4)->startOfDay(),
    ]);

    $this->get(route('funcionarios.turnos.list', ['ci' => '7633685']))
        ->assertOk()
        ->assertSeeInOrder(['TURNO RECIENTE', 'TURNO VIEJO']);
});

test('el listado AJAX de turnos junta en un bloque los días que comparten vigencia', function () {
    $desde = now()->subMonth()->startOfDay();
    $hasta = now()->addMonth()->startOfDay();

    foreach (['2', '3', '4'] as $dia) {
        AsignacionTurno::factory()->create([
            'ci' => '7633685',
            'turno_id' => Turno::factory()->create(['dia' => $dia])->id,
            'desde' => $desde,
            'hasta' => $hasta,
        ]);
    }

    $respuesta = $this->get(route('funcionarios.turnos.list', ['ci' => '7633685']))
        ->assertOk()
        ->assertSee('3 días')
        ->assertSee($desde->format('d/m/Y'));

    // Un solo bloque, y las fechas escritas una sola vez y no una por día.
    expect(substr_count($respuesta->getContent(), 'fila--periodo'))->toBe(1)
        ->and(substr_count($respuesta->getContent(), $desde->format('d/m/Y')))->toBe(1);
});

test('el listado AJAX de turnos pagina por período de vigencia y no por día asignado', function () {
    $lunes = Turno::factory()->create(['dia' => '2']);
    $martes = Turno::factory()->create(['dia' => '3']);

    // Doce períodos de dos días: 24 asignaciones que son 12 filas de listado.
    foreach (range(1, 12) as $mes) {
        foreach ([$lunes, $martes] as $turno) {
            AsignacionTurno::factory()->create([
                'ci' => '7633685',
                'turno_id' => $turno->id,
                'desde' => now()->subMonths($mes + 1)->startOfDay(),
                'hasta' => now()->subMonths($mes)->startOfDay(),
            ]);
        }
    }

    $masViejo = now()->subMonths(13)->startOfDay()->format('d/m/Y');

    $primera = $this->get(route('funcionarios.turnos.list', ['ci' => '7633685']))
        ->assertOk()
        ->assertDontSee($masViejo);

    expect(substr_count($primera->getContent(), 'fila--periodo'))->toBe(10);

    $this->get(route('funcionarios.turnos.list', ['ci' => '7633685', 'page' => 2]))
        ->assertOk()
        ->assertSee($masViejo);
});

test('el listado AJAX de turnos oculta concluir y eliminar cuando se pide sin acciones', function () {
    $turno = Turno::factory()->create(['nombreTurno' => 'LUN: 08:00 - 16:00']);
    AsignacionTurno::factory()->create(['ci' => '7633685', 'turno_id' => $turno->id]);

    // En la ficha los botones están.
    $this->get(route('funcionarios.turnos.list', ['ci' => '7633685']))
        ->assertOk()
        ->assertSee('aria-label="Concluir"', escape: false)
        ->assertSee('aria-label="Eliminar"', escape: false);

    // En el modal de licencia la tabla es solo de referencia.
    $this->get(route('funcionarios.turnos.list', ['ci' => '7633685', 'acciones' => 0]))
        ->assertOk()
        ->assertSee('LUN: 08:00 - 16:00')
        ->assertDontSee('aria-label="Concluir"', escape: false)
        ->assertDontSee('aria-label="Eliminar"', escape: false);
});

test('el listado AJAX de turnos avisa cuando el funcionario no tiene ninguno', function () {
    $this->get(route('funcionarios.turnos.list', ['ci' => '7633685']))
        ->assertOk()
        ->assertSee('El funcionario no tiene turnos asignados en este filtro.');
});

test('el listado AJAX de licencias trae las de la cédula, paginadas', function () {
    Licencia::factory()->create(['ci' => '7633685', 'motivo' => 'COMISION DE VIAJE']);
    Licencia::factory()->create(['ci' => '1112222', 'motivo' => 'LICENCIA AJENA']);

    $this->get(route('funcionarios.licencias.list', ['ci' => '7633685']))
        ->assertOk()
        ->assertSee('COMISION DE VIAJE')
        ->assertDontSee('LICENCIA AJENA');

    Licencia::factory()->count(12)->create(['ci' => '7633685']);

    $this->get(route('funcionarios.licencias.list', ['ci' => '7633685', 'por_pagina' => 10]))
        ->assertOk()
        ->assertViewHas('licencias', fn ($licencias): bool => $licencias->count() === 10);
});

test('un usuario sin permiso no puede pedir las solapas de licencias ni de turnos', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('funcionarios.licencias.list', ['ci' => '7633685']))->assertForbidden();
    $this->get(route('funcionarios.turnos.list', ['ci' => '7633685']))->assertForbidden();
});

test('sin permiso sobre los turnos, la ficha no muestra el panel', function () {
    $persona = Persona::factory()->create(['ci' => '7633685']);
    $turno = Turno::factory()->create(['nombreTurno' => 'LUN: 08:00 - 16:00']);
    AsignacionTurno::factory()->create(['ci' => $persona->ci, 'turno_id' => $turno->id]);

    foreach (['ViewAny:Persona', 'View:Persona'] as $permiso) {
        Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']);
    }

    $rol = Role::create(['name' => 'solo_funcionarios', 'guard_name' => 'web']);
    $rol->givePermissionTo('ViewAny:Persona', 'View:Persona');

    $this->actingAs(User::factory()->create()->assignRole($rol));

    // «Turnos asignados» a secas también es la opción del menú: lo que no tiene
    // que aparecer es el contenido del panel.
    $this->get(route('funcionarios.show', $persona))
        ->assertOk()
        ->assertDontSee('LUN: 08:00 - 16:00')
        ->assertDontSee(route('turnos-asignados.index', ['buscar' => '7633685']), escape: false);
});

test('la solapa de licencias de la ficha agrupa los días de un mismo pedido', function () {
    $persona = Persona::factory()->create(['ci' => '7633685']);
    $turno = Turno::factory()->create(['dia' => '2']);
    $solicitud = (string) Str::ulid();

    // Tres días del mismo pedido: solo el primero abre la solicitud.
    collect(range(0, 2))->each(fn (int $i) => Licencia::factory()->create([
        'ci' => $persona->ci,
        'turno_id' => $turno->id,
        'fecha' => Carbon::parse('2026-08-03')->addWeeks($i)->toDateString(),
        'solicitud' => $solicitud,
        'motivo' => 'CONSULTA MEDICA',
    ]));

    $contenido = $this->get(route('funcionarios.licencias.list', ['ci' => $persona->ci]))
        ->assertOk()
        ->assertSee('03/08/2026')
        ->assertSee('17/08/2026')
        ->getContent();

    // Una sola fila, no tres.
    expect(substr_count($contenido, 'CONSULTA MEDICA'))->toBe(1);

    // Y el aviso de baja dice cuántos días se van: `destroy` elimina la
    // solicitud entera, así que prometer «la licencia del 03/08» sería mentir.
    expect($contenido)->toContain('Se eliminan los 3 d');
});

test('la ficha del funcionario ofrece la solapa de asistencia procesada', function () {
    $persona = Persona::factory()->create(['ci' => '7633685']);

    $this->get(route('funcionarios.show', $persona))
        ->assertOk()
        ->assertSee('Asistencia procesada')
        // La solapa apunta al reporte que ya existe: no se duplicó la tabla.
        // La URL va dentro de un `@json`, que escapa las barras, así que se
        // busca el panel y no la ruta cruda.
        ->assertSee('data-panel="procesado"', false);
});

test('la solapa de asistencia procesada no aparece sin permiso de reportes', function () {
    $persona = Persona::factory()->create(['ci' => '7633685']);

    $rol = Role::create(['name' => 'solo-funcionarios']);
    $rol->givePermissionTo(
        Permission::firstOrCreate(['name' => 'ViewAny:Persona', 'guard_name' => 'web']),
        Permission::firstOrCreate(['name' => 'View:Persona', 'guard_name' => 'web']),
    );

    $this->actingAs(User::factory()->create()->assignRole($rol));

    $this->get(route('funcionarios.show', $persona))
        ->assertOk()
        ->assertDontSee('Asistencia procesada');
});

test('el reporte procesado se sirve como parcial para la solapa de la ficha', function () {
    $hora = fn (string $hm): string => "1899-12-30 {$hm}:00";
    $persona = Persona::factory()->create(['ci' => '7633685', 'nombres' => 'IGNACIO']);

    $turno = Turno::factory()->create([
        'dia' => '2',
        'nombreTurno' => 'LUN: 08:00 - 16:00',
        'hEntrada' => $hora('08:00'),
        'hTolerancia' => $hora('08:10'),
        'eMinima' => $hora('07:00'),
        'eMaxima' => $hora('09:00'),
        'hSalida' => $hora('16:00'),
        'sTolerancia' => $hora('16:00'),
        'sMinima' => $hora('16:00'),
        'sMaxima' => $hora('20:00'),
        'hTrabajadas' => 8,
        'siguienteDia' => false,
    ]);

    AsignacionTurno::factory()->create([
        'ci' => $persona->ci,
        'turno_id' => $turno->id,
        'desde' => '2026-01-01 00:00:00',
        'hasta' => '2026-12-31 00:00:00',
    ]);

    foreach (['08:25', '16:05'] as $marca) {
        Asistencia::factory()->create([
            'ci' => $persona->ci,
            'fecha' => '2026-07-06',
            'hora' => $hora($marca),
        ]);
    }

    $this->get(route('reportes.marcaciones.procesado.generar', [
        'persona' => $persona->ci,
        'desde' => '2026-07-06',
        'hasta' => '2026-07-06',
    ]))
        ->assertOk()
        // Parcial, no página entera: la solapa lo inyecta en un div.
        ->assertDontSee('<!DOCTYPE html>', false)
        ->assertSee('LUN: 08:00 - 16:00')
        ->assertSee('25 min');
});

test('la ficha local muestra la extensión del carnet que trae Mamoré', function () {
    $persona = Persona::factory()->create(['ci' => '7633685', 'nombres' => 'IGNACIO']);

    fakeMamore(['7633685' => [
        'nombre' => 'IGNACIO MOLINA GUZMAN',
        'cargo' => 'TECNICO II',
        'extension' => 'BE',
    ]]);

    $this->get(route('funcionarios.show', $persona))
        ->assertOk()
        ->assertSee('Extensión')
        ->assertSee('BE');
});

test('la ficha local se muestra igual si Mamoré no responde', function () {
    $persona = Persona::factory()->create(['ci' => '7633685', 'nombres' => 'IGNACIO']);

    // Sin Mamoré configurado no hay de dónde sacar la extensión: la ficha sale
    // entera y ese dato queda en «—».
    config()->set('services.mamore.url', null);
    config()->set('services.mamore.key', null);

    $this->get(route('funcionarios.show', $persona))
        ->assertOk()
        ->assertSee('Extensión')
        ->assertSee('IGNACIO');
});

test('la ficha de Mamoré muestra la extensión dentro de la cédula y no como campo aparte', function () {
    // Mamoré ya manda la cédula con su extensión en `full_ci`. Repetirla en un
    // campo suelto gastaba una fila entera para decir lo mismo dos veces.
    config()->set('services.mamore.url', 'http://mamore.test/api/personal');
    config()->set('services.mamore.key', 'secreta');

    Http::fake([
        'mamore.test/api/personal/people/ci/*' => Http::response([
            'data' => [
                'ci' => '7633685',
                'extension' => 'BE',
                'full_ci' => '7633685 BE',
                'full_name' => 'IGNACIO MOLINA GUZMAN',
            ],
        ], 200),
    ]);

    $this->get(route('funcionarios.mamore', ['ci' => '7633685']))
        ->assertOk()
        ->assertSee('7633685 BE')
        ->assertDontSee('Extensión')
        // «Emisión» salió por lo mismo: Mamoré casi nunca la trae cargada y la
        // fila quedaba ocupada por un guión.
        ->assertDontSee('Emisión');
});

test('el directorio conserva la extensión al normalizar una persona de Mamoré', function () {
    $fila = app(DirectorioMamore::class)->normalizarPersona([
        'ci' => '7633685',
        'extension' => 'BE',
        'full_ci' => '7633685 BE',
        'first_name' => 'IGNACIO',
    ]);

    expect($fila['extension'])->toBe('BE')
        ->and($fila['ciCompleto'])->toBe('7633685 BE');

    // Sin extensión en la respuesta no se inventa una cadena vacía.
    $sinExtension = app(DirectorioMamore::class)->normalizarPersona(['ci' => '1', 'first_name' => 'Ana']);

    expect($sinExtension['extension'])->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Vigencia del contrato en la ficha de Mamoré
|--------------------------------------------------------------------------
|
| Recursos Humanos necesita ver hasta cuándo rige el contrato sin salir de la
| ficha: es lo que decide si la persona sigue siendo funcionario hoy, y de ahí
| sale el haber con el que el RIP convierte un descuento en bolivianos.
|
| El dato ya viajaba en la respuesta de Mamoré (`contrato.start` y
| `contrato.finish`, columnas `date` de la tabla `contracts`): lo único que
| faltaba era mostrarlo.
|
*/

/**
 * Respuesta de Mamoré con un contrato firmado, para las pruebas de vigencia.
 *
 * @param  array<string, mixed>  $contrato
 */
function fakeFichaMamoreConContrato(array $contrato): void
{
    config()->set('services.mamore.url', 'http://mamore.test/api/personal');
    config()->set('services.mamore.key', 'secreta');

    Http::fake([
        'mamore.test/api/personal/people/ci/*' => Http::response([
            'data' => [
                'ci' => '7654321',
                'full_name' => 'Juan Perez',
                'has_contract' => true,
                'contrato' => $contrato,
            ],
        ], 200),
    ]);
}

test('la ficha de Mamoré muestra desde y hasta cuándo rige el contrato', function () {
    fakeFichaMamoreConContrato([
        'cargo' => 'DESARROLLADOR DE SISTEMAS',
        'start' => '2024-01-15',
        'finish' => '2026-12-31',
    ]);

    $this->get(route('funcionarios.mamore', ['ci' => '7654321']))
        ->assertOk()
        ->assertSee('Vigencia desde')
        ->assertSee('Vigencia hasta')
        // En el formato del sistema, no en el ISO en que lo manda la API.
        ->assertSee('15/01/2024')
        ->assertSee('31/12/2026');
});

test('un contrato sin fecha de término se muestra como abierto y no como dato faltante', function () {
    // `finish` en null es un contrato vigente sin vencimiento, que es como lo
    // trata el resto del sistema. Un «—» lo haría parecer un dato sin cargar.
    fakeFichaMamoreConContrato([
        'cargo' => 'DESARROLLADOR DE SISTEMAS',
        'start' => '2024-01-15',
        'finish' => null,
    ]);

    $this->get(route('funcionarios.mamore', ['ci' => '7654321']))
        ->assertOk()
        ->assertSee('15/01/2024')
        ->assertSee('Sin fecha de término');
});

test('una fecha de contrato con forma inesperada se muestra cruda y no rompe la ficha', function () {
    // Antes que ocultarla: es una fecha de vigencia, y que se vea rara invita a
    // corregirla en Mamoré, que es donde se carga.
    fakeFichaMamoreConContrato([
        'cargo' => 'DESARROLLADOR DE SISTEMAS',
        'start' => 'sin fecha',
        'finish' => '2026-12-31',
    ]);

    $this->get(route('funcionarios.mamore', ['ci' => '7654321']))
        ->assertOk()
        ->assertSee('sin fecha')
        ->assertSee('31/12/2026');
});

test('la ficha de Mamoré no muestra la vigencia si la persona no tiene contrato', function () {
    config()->set('services.mamore.url', 'http://mamore.test/api/personal');
    config()->set('services.mamore.key', 'secreta');

    Http::fake([
        'mamore.test/api/personal/people/ci/*' => Http::response([
            'data' => ['ci' => '7654321', 'full_name' => 'Juan Perez', 'has_contract' => false, 'contrato' => null],
        ], 200),
    ]);

    $this->get(route('funcionarios.mamore', ['ci' => '7654321']))
        ->assertOk()
        ->assertDontSee('Vigencia desde')
        ->assertSee('no tiene un contrato firmado en Mamoré');
});

test('la ficha de Mamoré junta los datos personales y el contacto en una sola tarjeta', function () {
    // Son la misma cosa —quién es la persona— y separarlos en dos tarjetas
    // dejaba a la de contacto, con cinco campos contra siete, al lado de un
    // hueco. La foto va al costado de los datos y no encima.
    config()->set('services.mamore.url', 'http://mamore.test/api/personal');
    config()->set('services.mamore.key', 'secreta');

    Http::fake([
        'mamore.test/api/personal/people/ci/*' => Http::response([
            'data' => [
                'ci' => '7654321',
                'full_name' => 'Juan Perez',
                'phone' => '67285914',
                'email' => 'juan@test.bo',
            ],
        ], 200),
    ]);

    $respuesta = $this->get(route('funcionarios.mamore', ['ci' => '7654321']))
        ->assertOk()
        ->assertSee('Datos personales')
        ->assertSee('Contacto')
        ->assertSee('67285914')
        ->assertSee('juan@test.bo')
        ->assertSee('class="ficha-persona"', escape: false)
        ->assertSee('class="ficha-bloque"', escape: false);

    // El contacto ya no abre su propia tarjeta: queda adentro de la de datos
    // personales, detrás de un separador.
    $html = $respuesta->getContent();
    $personales = strpos($html, 'Datos personales');
    $contacto = strpos($html, 'ficha-bloque');
    $contrato = strpos($html, 'Contrato vigente');

    // Una sola apertura de tarjeta entre el título de datos personales y el
    // bloque de contacto: si hubiera dos, habría un `class="tarjeta"` en medio.
    expect(substr_count(substr($html, $personales, $contacto - $personales), 'class="tarjeta"'))->toBe(0)
        ->and($contacto)->toBeLessThan($contrato ?: PHP_INT_MAX);
});

test('la ficha de Mamoré marca el origen del dato en la cabecera y no en una franja aparte', function () {
    config()->set('services.mamore.url', 'http://mamore.test/api/personal');
    config()->set('services.mamore.key', 'secreta');

    Http::fake([
        'mamore.test/api/personal/people/ci/*' => Http::response([
            'data' => ['ci' => '7654321', 'full_name' => 'Juan Perez', 'has_contract' => true],
        ], 200),
    ]);

    $this->get(route('funcionarios.mamore', ['ci' => '7654321']))
        ->assertOk()
        // Al lado del estado del contrato, no en un renglón propio.
        ->assertSee('pill pill--neutro', escape: false)
        ->assertSee('>Mamoré</span>', escape: false)
        ->assertSee('Con contrato')
        // El «solo lectura» sigue estando, pero como title y no como franja.
        ->assertSee('title="Datos de solo lectura desde el sistema Mamoré"', escape: false)
        ->assertDontSee('<div class="aviso">Datos de solo lectura', escape: false);
});
