<?php

use App\Models\AsignacionTurno;
use App\Models\Asistencia;
use App\Models\Licencia;
use App\Models\Persona;
use App\Models\Turno;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const CLAVE = 'clave-de-prueba-de-la-api';

beforeEach(function () {
    config()->set('services.sismark_api.key', CLAVE);
});

/**
 * Pedido autenticado con la clave compartida, como lo hace el sistema externo.
 */
function comoMamore(string $ruta): TestResponse
{
    return test()->getJson($ruta, ['X-API-KEY' => CLAVE]);
}

/**
 * Funcionario con turno de lunes 08:00–16:00 vigente y una marcación de entrada
 * con atraso el lunes 2026-08-03.
 */
function funcionarioConAsistencia(string $ci = '7633685'): void
{
    Persona::factory()->create(['ci' => $ci, 'nombres' => 'IGNACIO', 'paterno' => 'MOLINA']);

    // `dia` va en la convención del SIA: 1 = Domingo … 7 = Sábado.
    $turno = Turno::factory()->create([
        'dia' => '2',
        'nombreTurno' => 'LUN: 08:00 - 16:00',
        'hEntrada' => '1899-12-30 08:00:00',
        'hTolerancia' => '1899-12-30 08:10:00',
        'hSalida' => '1899-12-30 16:00:00',
        'sTolerancia' => '1899-12-30 16:00:00',
        'eMinima' => '1899-12-30 07:00:00',
        'eMaxima' => '1899-12-30 12:00:00',
        'sMinima' => '1899-12-30 15:00:00',
        'sMaxima' => '1899-12-30 23:59:00',
        'hTrabajadas' => 8,
        'siguienteDia' => false,
    ]);

    AsignacionTurno::factory()->create([
        'ci' => $ci,
        'turno_id' => $turno->id,
        'idTurno' => $turno->idTurno,
        'desde' => '2020-01-01 00:00:00',
        'hasta' => '2030-12-31 00:00:00',
    ]);

    // 08:12:04 contra una tolerancia de 08:10 → atraso medido sobre las 08:00.
    Asistencia::factory()->create([
        'ci' => $ci,
        'fecha' => '2026-08-03 00:00:00',
        'hora' => '1899-12-30 08:12:04',
        'tipo' => Asistencia::TIPO_RELOJ,
    ]);

    Asistencia::factory()->create([
        'ci' => $ci,
        'fecha' => '2026-08-03 00:00:00',
        'hora' => '1899-12-30 16:03:00',
        'tipo' => Asistencia::TIPO_RELOJ,
    ]);
}

test('sin clave el acceso se rechaza', function () {
    $this->getJson('/api/v1/funcionarios/7633685/marcaciones')
        ->assertUnauthorized();
});

test('con una clave equivocada el acceso se rechaza', function () {
    $this->getJson('/api/v1/funcionarios/7633685/marcaciones', ['X-API-KEY' => 'otra'])
        ->assertUnauthorized();
});

test('si el servidor no tiene clave configurada la API no atiende a nadie', function () {
    // Un despliegue recién hecho y sin configurar no puede quedar abierto.
    config()->set('services.sismark_api.key', null);

    $this->getJson('/api/v1/funcionarios/7633685/marcaciones', ['X-API-KEY' => CLAVE])
        ->assertStatus(503);
});

test('la clave también se acepta como Bearer token', function () {
    funcionarioConAsistencia();

    $this->getJson('/api/v1/funcionarios/7633685/marcaciones?desde=2026-08-01&hasta=2026-08-31', [
        'Authorization' => 'Bearer '.CLAVE,
    ])->assertOk();
});

test('devuelve las marcaciones crudas del funcionario en el rango', function () {
    funcionarioConAsistencia();

    comoMamore('/api/v1/funcionarios/7633685/marcaciones?desde=2026-08-01&hasta=2026-08-31')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.fecha', '2026-08-03')
        ->assertJsonPath('data.0.hora', '08:12:04')
        ->assertJsonPath('data.0.tipoEtiqueta', 'Reloj')
        ->assertJsonPath('meta.funcionario.ci', '7633685')
        ->assertJsonPath('meta.rango.desde', '2026-08-01');
});

test('las marcaciones de otro funcionario no se filtran en la respuesta', function () {
    funcionarioConAsistencia('7633685');
    Persona::factory()->create(['ci' => '1111111']);
    Asistencia::factory()->create([
        'ci' => '1111111',
        'fecha' => '2026-08-03 00:00:00',
        'hora' => '1899-12-30 09:00:00',
    ]);

    comoMamore('/api/v1/funcionarios/7633685/marcaciones?desde=2026-08-01&hasta=2026-08-31')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('la asistencia procesada calcula el atraso y las horas del día', function () {
    funcionarioConAsistencia();

    $respuesta = comoMamore('/api/v1/funcionarios/7633685/asistencia?desde=2026-08-03&hasta=2026-08-03')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    // 08:12:04 contra una hora de entrada de 08:00 → 12 min 4 seg de atraso.
    $respuesta
        ->assertJsonPath('data.0.fecha', '2026-08-03')
        ->assertJsonPath('data.0.estado', 'atraso')
        ->assertJsonPath('data.0.estadoEtiqueta', 'Atraso')
        ->assertJsonPath('data.0.atrasoSegundos', 724)
        ->assertJsonPath('data.0.atraso', '12 min 4 seg')
        ->assertJsonPath('data.0.bloques.0.entrada', '08:12:04')
        ->assertJsonPath('data.0.bloques.0.salida', '16:03:00')
        ->assertJsonPath('data.0.bloques.0.turno', 'LUN: 08:00 - 16:00')
        ->assertJsonPath('totales.atraso', '0h 12m');
});

test('el día sin turno asignado no cuenta como falta', function () {
    funcionarioConAsistencia();

    // El martes 2026-08-04: el funcionario solo tiene turno los lunes.
    comoMamore('/api/v1/funcionarios/7633685/asistencia?desde=2026-08-04&hasta=2026-08-04')
        ->assertOk()
        ->assertJsonPath('data.0.estado', 'no_laborable')
        ->assertJsonPath('data.0.estadoEtiqueta', 'No laborable');
});

test('la asistencia no expone los avisos de configuración del turno', function () {
    funcionarioConAsistencia();

    // Son diagnósticos para Recursos Humanos; al funcionario no le dicen nada.
    comoMamore('/api/v1/funcionarios/7633685/asistencia?desde=2026-08-03&hasta=2026-08-03')
        ->assertOk()
        ->assertJsonMissingPath('data.0.bloques.0.avisos');
});

test('devuelve las licencias del funcionario en el rango', function () {
    funcionarioConAsistencia();
    $turno = Turno::query()->first();

    Licencia::factory()->create([
        'ci' => '7633685',
        'turno_id' => $turno->id,
        'fecha' => '2026-08-03 00:00:00',
        'tCompleto' => true,
        'goceHaberes' => true,
        'motivo' => 'FERIADO DEPARTAMENTAL',
    ]);

    comoMamore('/api/v1/funcionarios/7633685/licencias?desde=2026-08-01&hasta=2026-08-31')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.motivo', 'FERIADO DEPARTAMENTAL')
        ->assertJsonPath('data.0.alcance', 'Turno completo')
        ->assertJsonPath('data.0.conGoceDeHaberes', true);
});

test('sin rango toma del primero del mes hasta hoy', function () {
    funcionarioConAsistencia();

    comoMamore('/api/v1/funcionarios/7633685/marcaciones')
        ->assertOk()
        ->assertJsonPath('meta.rango.desde', now()->startOfMonth()->toDateString())
        ->assertJsonPath('meta.rango.hasta', now()->toDateString());
});

test('el rango invertido se da vuelta en vez de fallar', function () {
    funcionarioConAsistencia();

    comoMamore('/api/v1/funcionarios/7633685/marcaciones?desde=2026-08-31&hasta=2026-08-01')
        ->assertOk()
        ->assertJsonPath('meta.rango.desde', '2026-08-01')
        ->assertJsonPath('meta.rango.hasta', '2026-08-31');
});

test('un rango desmedido se rechaza', function () {
    // Corta de raíz el pedido que barrería los once años de la tabla.
    comoMamore('/api/v1/funcionarios/7633685/marcaciones?desde=2020-01-01&hasta=2026-12-31')
        ->assertStatus(422)
        ->assertJsonValidationErrors('hasta');
});

test('una fecha mal formada se rechaza', function () {
    comoMamore('/api/v1/funcionarios/7633685/marcaciones?desde=no-es-fecha')
        ->assertStatus(422)
        ->assertJsonValidationErrors('desde');
});

test('informa hasta qué día llegaron marcaciones de los relojes', function () {
    funcionarioConAsistencia();

    // Sin este dato el consumidor no puede distinguir «no marcó» de «todavía no
    // se sincronizó ese día», y muestra faltas que no existen.
    comoMamore('/api/v1/funcionarios/7633685/asistencia?desde=2026-08-01&hasta=2026-08-31')
        ->assertOk()
        ->assertJsonPath('meta.ultimaSincronizacion', '2026-08-03');
});

test('sin ninguna marcación en el sistema la última sincronización viaja en null', function () {
    comoMamore('/api/v1/funcionarios/7633685/marcaciones')
        ->assertOk()
        ->assertJsonPath('meta.ultimaSincronizacion', null);
});

test('una cédula sin marcaciones devuelve vacío y no un error', function () {
    // Puede pasar: la cédula existe en Mamoré pero nunca marcó.
    comoMamore('/api/v1/funcionarios/9999999/marcaciones?desde=2026-08-01&hasta=2026-08-31')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.funcionario.ci', '9999999');
});
