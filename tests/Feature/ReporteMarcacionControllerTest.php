<?php

use App\Models\Asistencia;
use App\Models\Persona;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(asSuperAdmin());
});

function funcionarioConMarcaciones(): Persona
{
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
        'hora' => '1899-12-30 08:15:00',
        'tipo' => Asistencia::TIPO_RELOJ,
    ]);
    Asistencia::factory()->create([
        'ci' => $persona->ci,
        'fecha' => today()->subYear(),
        'hora' => '1899-12-30 07:00:00',
        'tipo' => Asistencia::TIPO_RELOJ,
    ]);

    return $persona;
}

test('el formulario de selección carga', function () {
    $this->get(route('reportes.marcaciones.sin-procesar'))
        ->assertOk()
        ->assertSee('Marcaciones sin procesar');
});

test('el endpoint del combo devuelve funcionarios en JSON', function () {
    funcionarioConMarcaciones();

    $this->getJson(route('reportes.marcaciones.funcionarios', ['q' => 'ignacio molina']))
        ->assertOk()
        ->assertJsonFragment(['id' => '7633685'])
        ->assertJsonFragment(['texto' => '7633685 — Ignacio Molina Guzman (PIN 7633685)']);
});

test('el combo busca en Mamoré y muestra el cargo cuando la API está configurada', function () {
    funcionarioConMarcaciones();
    fakeMamore(['7633685' => ['nombre' => 'IGNACIO MOLINA GUZMAN', 'cargo' => 'Analista II', 'direccion' => 'SDAF']]);

    $this->getJson(route('reportes.marcaciones.funcionarios', ['q' => 'molina']))
        ->assertOk()
        ->assertJsonFragment(['id' => '7633685', 'texto' => '7633685 — IGNACIO MOLINA GUZMAN · Analista II (SDAF)']);
});

test('el combo devuelve la miniatura de la foto de Mamoré', function () {
    funcionarioConMarcaciones();
    fakeMamore(['7633685' => [
        'nombre' => 'IGNACIO MOLINA GUZMAN',
        'cargo' => 'Analista II',
        'image' => 'http://mamore.test/fotos/7633685.png',
    ]]);

    $this->getJson(route('reportes.marcaciones.funcionarios', ['q' => 'molina']))
        ->assertOk()
        ->assertJsonFragment(['foto' => 'http://mamore.test/fotos/7633685-cropped.png']);
});

test('el combo devuelve la foto en null cuando el funcionario sale de la base local', function () {
    funcionarioConMarcaciones();

    // SIAT no guarda fotos: la fila del combo cae al ícono genérico.
    $this->getJson(route('reportes.marcaciones.funcionarios', ['q' => 'ignacio molina']))
        ->assertOk()
        ->assertJsonFragment(['id' => '7633685', 'foto' => null]);
});

test('el combo cae a la base local si Mamoré falla', function () {
    funcionarioConMarcaciones();

    config()->set('services.mamore.url', 'http://mamore.test/api/personal');
    config()->set('services.mamore.key', 'secreta');
    Http::fake(['mamore.test/*' => Http::response('boom', 500)]);

    $this->getJson(route('reportes.marcaciones.funcionarios', ['q' => 'ignacio molina']))
        ->assertOk()
        ->assertJsonFragment(['id' => '7633685']);
});

test('el reporte imprimible muestra el cargo que informa Mamoré', function () {
    $persona = funcionarioConMarcaciones();
    fakeMamore([
        '7633685' => [
            'nombre' => 'IGNACIO MOLINA GUZMAN',
            'cargo' => 'Analista II',
            'direccion' => 'SDAF',
        ],
    ]);

    $this->get(route('reportes.marcaciones.sin-procesar.generar', [
        'persona' => trim($persona->ci),
        'desde' => today()->startOfMonth()->toDateString(),
        'hasta' => today()->toDateString(),
        'print' => 1,
    ]))
        ->assertOk()
        ->assertSee('REPORTE DE MARCACIONES')
        ->assertSee('Analista II')
        ->assertSee('SDAF')
        ->assertSee('08:15:00');
});

test('el combo no devuelve nada sin término de búsqueda', function () {
    funcionarioConMarcaciones();

    $this->getJson(route('reportes.marcaciones.funcionarios'))
        ->assertOk()
        ->assertExactJson([]);
});

test('generar muestra el reporte en pantalla con el total del rango', function () {
    $persona = funcionarioConMarcaciones();

    $this->get(route('reportes.marcaciones.sin-procesar.generar', [
        'persona' => trim($persona->ci),
        'desde' => today()->startOfMonth()->toDateString(),
        'hasta' => today()->toDateString(),
    ]))
        ->assertOk()
        ->assertSee('08:15:00')
        ->assertDontSee('07:00:00')
        ->assertSee('Total: 1 registro(s)');
});

test('generar con print=1 devuelve la versión imprimible', function () {
    $persona = funcionarioConMarcaciones();

    $this->get(route('reportes.marcaciones.sin-procesar.generar', [
        'persona' => trim($persona->ci),
        'desde' => today()->startOfMonth()->toDateString(),
        'hasta' => today()->toDateString(),
        'print' => 1,
    ]))
        ->assertOk()
        ->assertSee('REPORTE DE MARCACIONES')
        ->assertSeeText('PIN Reloj: 7633685')
        ->assertSee('08:15:00')
        ->assertSee('Total registros:');
});

test('generar con print=2 descarga el CSV', function () {
    $persona = funcionarioConMarcaciones();

    $response = $this->get(route('reportes.marcaciones.sin-procesar.generar', [
        'persona' => trim($persona->ci),
        'desde' => today()->startOfMonth()->toDateString(),
        'hasta' => today()->toDateString(),
        'print' => 2,
    ]))
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');

    expect($response->getContent())
        ->toContain('Fecha,Hora,Tipo')
        ->toContain('08:15:00,R');
});

test('generar sin funcionario vuelve al formulario con error', function () {
    $this->get(route('reportes.marcaciones.sin-procesar.generar'))
        ->assertRedirect(route('reportes.marcaciones.sin-procesar'))
        ->assertSessionHas('error');
});

test('un usuario sin permiso no puede entrar', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('reportes.marcaciones.sin-procesar'))->assertForbidden();
});
