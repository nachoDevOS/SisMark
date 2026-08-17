<?php

use App\Models\AsignacionTurno;
use App\Models\Licencia;
use App\Models\Persona;
use App\Models\Role;
use App\Models\Turno;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(asSuperAdmin());
    // Ningún test toca el bucket real: sin el disco falso, las altas que sí
    // mandan respaldo subirían un archivo a DigitalOcean.
    Storage::fake('s3');
});

/**
 * Respaldo válido para las altas que lo mandan. Adjuntarlo es opcional; el
 * archivo se sigue ejercitando en las pruebas que cubren la subida al bucket.
 */
function respaldoDePrueba(): UploadedFile
{
    return UploadedFile::fake()->create('respaldo.pdf', 40, 'application/pdf');
}

/**
 * Funcionario con un turno asignado el día de la semana pedido (convención del
 * SIA: 1 = Domingo … 7 = Sábado).
 *
 * @return array{0: Persona, 1: AsignacionTurno}
 */
function funcionarioConTurno(int $dia = 2, string $ci = '7633685'): array
{
    $persona = Persona::factory()->create(['ci' => $ci]);
    $turno = Turno::factory()->create([
        'idTurno' => 'T'.$dia.'A',
        'dia' => (string) $dia,
        'nombreTurno' => 'Turno mañana',
    ]);
    $asignacion = AsignacionTurno::factory()->create([
        'ci' => $persona->ci,
        'turno_id' => $turno->id,
        'idTurno' => $turno->idTurno,
        'desde' => '2020-01-01 00:00:00',
        'hasta' => '2030-12-31 00:00:00',
    ]);

    return [$persona, $asignacion];
}

test('el listado muestra las licencias registradas', function () {
    Persona::factory()->create(['ci' => '7633685', 'nombres' => 'IGNACIO', 'paterno' => 'MOLINA']);
    Licencia::factory()->create([
        'ci' => '7633685',
        'fecha' => '2026-07-28 00:00:00',
        'motivo' => 'PRUEBA DIAS',
    ]);

    $this->get(route('licencias.list'))
        ->assertOk()
        ->assertSee('PRUEBA DIAS')
        ->assertSee('28/07/2026')
        ->assertSee('MOLINA');
});

test('el listado muestra la foto de Mamoré del funcionario', function () {
    Persona::factory()->create(['ci' => '7633685', 'nombres' => 'IGNACIO', 'paterno' => 'MOLINA']);
    Licencia::factory()->create(['ci' => '7633685', 'motivo' => 'FERIADO']);

    fakeMamore(['7633685' => [
        'nombre' => 'IGNACIO MOLINA GUZMAN',
        'cargo' => 'DESARROLLADOR DE SISTEMAS',
        'image' => 'http://mamore.test/fotos/7633685.png',
    ]]);

    // Se pinta la miniatura; la original queda como respaldo del `onerror`.
    $this->get(route('licencias.list'))
        ->assertOk()
        ->assertSee('IGNACIO MOLINA GUZMAN')
        ->assertSee('http://mamore.test/fotos/7633685-cropped.png')
        ->assertSee('http://mamore.test/fotos/7633685.png');
});

test('el listado cae al ícono genérico cuando el funcionario no tiene foto', function () {
    Persona::factory()->create(['ci' => '7633685', 'nombres' => 'IGNACIO', 'paterno' => 'MOLINA']);
    Licencia::factory()->create(['ci' => '7633685', 'motivo' => 'FERIADO']);

    // Sin Mamoré la ficha sale de la base local, que no guarda fotos.
    $this->get(route('licencias.list'))
        ->assertOk()
        ->assertSee('MOLINA')
        ->assertDontSee('-cropped.png');
});

test('la búsqueda filtra por motivo y por nombre del funcionario', function () {
    Persona::factory()->create(['ci' => '111', 'nombres' => 'ANA', 'paterno' => 'PEREZ']);
    Persona::factory()->create(['ci' => '222', 'nombres' => 'LUIS', 'paterno' => 'ROJAS']);
    Licencia::factory()->create(['ci' => '111', 'motivo' => 'VACACION']);
    Licencia::factory()->create(['ci' => '222', 'motivo' => 'BAJA MEDICA']);

    $this->get(route('licencias.list', ['q' => 'vacaci']))
        ->assertOk()
        ->assertSee('VACACION')
        ->assertDontSee('BAJA MEDICA');

    $this->get(route('licencias.list', ['q' => 'rojas']))
        ->assertOk()
        ->assertSee('BAJA MEDICA')
        ->assertDontSee('VACACION');
});

test('la búsqueda por CI deja fuera las licencias de los demás funcionarios', function () {
    Persona::factory()->create(['ci' => '7633685', 'nombres' => 'IGNACIO', 'paterno' => 'MOLINA']);
    Persona::factory()->create(['ci' => '1924269', 'nombres' => 'IGNACIO', 'paterno' => 'GUAJI']);
    Persona::factory()->create(['ci' => '4163255', 'nombres' => 'MIGUEL', 'paterno' => 'OJOPI']);

    Licencia::factory()->create(['ci' => '7633685', 'motivo' => 'LA BUSCADA']);
    Licencia::factory()->create(['ci' => '1924269', 'motivo' => 'OTRA MAS']);
    Licencia::factory()->create(['ci' => '4163255', 'motivo' => 'UNA TERCERA']);

    $this->get(route('licencias.list', ['q' => '7633685']))
        ->assertOk()
        ->assertSee('LA BUSCADA')
        ->assertDontSee('OTRA MAS')
        ->assertDontSee('UNA TERCERA');
});

test('la búsqueda por nombre cruza nombre y apellido sin traer al resto', function () {
    Persona::factory()->create(['ci' => '111', 'nombres' => 'IGNACIO', 'paterno' => 'MOLINA', 'materno' => 'GUZMAN']);
    Persona::factory()->create(['ci' => '222', 'nombres' => 'IGNACIO', 'paterno' => 'GUAJI', 'materno' => 'TECO']);

    Licencia::factory()->create(['ci' => '111', 'motivo' => 'LA DE MOLINA']);
    Licencia::factory()->create(['ci' => '222', 'motivo' => 'LA DE GUAJI']);

    $this->get(route('licencias.list', ['q' => 'ignacio mol']))
        ->assertOk()
        ->assertSee('LA DE MOLINA')
        ->assertDontSee('LA DE GUAJI');
});

test('la columna funcionario usa el nombre de Mamoré cuando existe', function () {
    config()->set('services.mamore.url', 'http://mamore.test/api/personal');
    config()->set('services.mamore.key', 'secreta');

    Http::fake([
        'mamore.test/api/personal/people/ci/*' => Http::response([
            'data' => ['id' => 1, 'ci' => '7633685', 'full_name' => 'MARIELA CRUZ PORCO'],
        ], 200),
    ]);

    // Existe también localmente, pero Mamoré tiene prioridad.
    Persona::factory()->create(['ci' => '7633685', 'nombres' => 'IGNACIO', 'paterno' => 'MOLINA']);
    Licencia::factory()->create(['ci' => '7633685']);

    $this->get(route('licencias.list'))
        ->assertOk()
        ->assertSee('MARIELA CRUZ PORCO')
        ->assertDontSee('IGNACIO MOLINA');
});

test('la columna funcionario cae a la BD local si el CI no está en Mamoré', function () {
    config()->set('services.mamore.url', 'http://mamore.test/api/personal');
    config()->set('services.mamore.key', 'secreta');

    Http::fake(['mamore.test/*' => Http::response(['message' => 'not found'], 404)]);

    Persona::factory()->create(['ci' => '7633685', 'nombres' => 'IGNACIO', 'paterno' => 'MOLINA']);
    Licencia::factory()->create(['ci' => '7633685']);

    $this->get(route('licencias.list'))
        ->assertOk()
        ->assertSee('IGNACIO MOLINA');
});

test('la columna funcionario muestra «Sin persona» si el CI no está en ningún sistema', function () {
    config()->set('services.mamore.url', 'http://mamore.test/api/personal');
    config()->set('services.mamore.key', 'secreta');

    Http::fake(['mamore.test/*' => Http::response(['message' => 'not found'], 404)]);

    Licencia::factory()->create(['ci' => '999999']);

    $this->get(route('licencias.list'))
        ->assertOk()
        ->assertSee('Sin persona');
});

test('un fallo de la API de Mamoré no rompe el listado y cae a la BD local', function () {
    config()->set('services.mamore.url', 'http://mamore.test/api/personal');
    config()->set('services.mamore.key', 'secreta');

    Http::fake(['mamore.test/*' => Http::response('boom', 500)]);

    Persona::factory()->create(['ci' => '7633685', 'nombres' => 'IGNACIO', 'paterno' => 'MOLINA']);
    Licencia::factory()->create(['ci' => '7633685']);

    $this->get(route('licencias.list'))
        ->assertOk()
        ->assertSee('IGNACIO MOLINA');
});

test('la pantalla de licenciar muestra los turnos asignados del funcionario', function () {
    [$persona] = funcionarioConTurno();
    fakeMamore([$persona->ci => 'MARIELA CRUZ PORCO']);

    $this->get(route('licencias.create', ['ci' => $persona->ci]))
        ->assertOk()
        ->assertSee('Turnos vigentes de')
        ->assertSee('Turno mañana')
        ->assertSee('08:00')
        ->assertSee('16:00');
});

test('la ficha del funcionario elegido sale de Mamoré, no de la base local', function () {
    // Existe también localmente y con otro nombre: no tiene que verse.
    [$persona] = funcionarioConTurno();
    $persona->update(['nombres' => 'IGNACIO', 'paterno' => 'MOLINA']);

    fakeMamore([$persona->ci => 'MARIELA CRUZ PORCO']);

    $this->get(route('licencias.create', ['ci' => $persona->ci]))
        ->assertOk()
        ->assertSee('MARIELA CRUZ PORCO')
        ->assertDontSee('IGNACIO MOLINA');
});

test('avisa si el CI no figura en Mamoré y no carga sus turnos', function () {
    [$persona] = funcionarioConTurno();
    fakeMamore(); // padrón vacío: la API responde 404

    $this->get(route('licencias.create', ['ci' => $persona->ci]))
        ->assertOk()
        ->assertSee('no figura en la API de Mamoré')
        ->assertDontSee('Turno mañana');
});

test('avisa cuando la API de Mamoré no responde', function () {
    [$persona] = funcionarioConTurno();

    config()->set('services.mamore.url', 'http://mamore.test/api/personal');
    config()->set('services.mamore.key', 'secreta');
    Http::fake(['mamore.test/*' => Http::response('boom', 500)]);

    $this->get(route('licencias.create', ['ci' => $persona->ci]))
        ->assertOk()
        ->assertSee('La API de Mamoré respondió con un error (500).');
});

test('avisa cuando la API de Mamoré no está configurada', function () {
    [$persona] = funcionarioConTurno();

    $this->get(route('licencias.create', ['ci' => $persona->ci]))
        ->assertOk()
        ->assertSee('La API de Mamoré no está configurada', escape: false);
});

test('la grilla solo lista los turnos vigentes y ofrece ver los vencidos', function () {
    $persona = Persona::factory()->create(['ci' => '7633685']);
    fakeMamore(['7633685' => 'MARIELA CRUZ PORCO']);

    $vigente = Turno::factory()->create(['idTurno' => 'V01', 'dia' => '2', 'nombreTurno' => 'TURNO VIGENTE']);
    $vencido = Turno::factory()->create(['idTurno' => 'X01', 'dia' => '2', 'nombreTurno' => 'TURNO VENCIDO']);

    AsignacionTurno::factory()->create([
        'ci' => $persona->ci, 'turno_id' => $vigente->id, 'idTurno' => $vigente->idTurno,
        'desde' => now()->subYear(), 'hasta' => now()->addYear(),
    ]);
    AsignacionTurno::factory()->vencida()->create([
        'ci' => $persona->ci, 'turno_id' => $vencido->id, 'idTurno' => $vencido->idTurno,
    ]);

    $this->get(route('licencias.create', ['ci' => $persona->ci]))
        ->assertOk()
        ->assertSee('TURNO VIGENTE')
        ->assertDontSee('TURNO VENCIDO')
        ->assertSee('Ver también los 1 vencido(s)');

    $this->get(route('licencias.create', ['ci' => $persona->ci, 'vencidos' => 1]))
        ->assertOk()
        ->assertSee('TURNO VIGENTE')
        ->assertSee('TURNO VENCIDO')
        ->assertSee('Vencido');
});

test('la grilla ordena los turnos vigentes por día de la semana y hora de entrada', function () {
    $persona = Persona::factory()->create(['ci' => '7633685']);
    fakeMamore(['7633685' => 'MARIELA CRUZ PORCO']);

    // Se crean desordenados a propósito: viernes tarde, lunes tarde, lunes mañana.
    $orden = [
        ['idTurno' => 'F01', 'dia' => '6', 'hEntrada' => '1899-12-30 14:30:00', 'nombreTurno' => 'VIE TARDE'],
        ['idTurno' => 'L02', 'dia' => '2', 'hEntrada' => '1899-12-30 14:30:00', 'nombreTurno' => 'LUN TARDE'],
        ['idTurno' => 'L01', 'dia' => '2', 'hEntrada' => '1899-12-30 08:00:00', 'nombreTurno' => 'LUN MANANA'],
    ];

    foreach ($orden as $datos) {
        $turno = Turno::factory()->create($datos);
        AsignacionTurno::factory()->create([
            'ci' => $persona->ci, 'turno_id' => $turno->id, 'idTurno' => $turno->idTurno,
            'desde' => now()->subYear(), 'hasta' => now()->addYear(),
        ]);
    }

    $this->get(route('licencias.create', ['ci' => $persona->ci]))
        ->assertOk()
        ->assertSeeInOrder(['LUN MANANA', 'LUN TARDE', 'VIE TARDE']);
});

test('con los vencidos a la vista, los vigentes van primero', function () {
    $persona = Persona::factory()->create(['ci' => '7633685']);
    fakeMamore(['7633685' => 'MARIELA CRUZ PORCO']);

    $vencido = Turno::factory()->create(['idTurno' => 'X01', 'dia' => '2', 'nombreTurno' => 'EL VENCIDO']);
    $vigente = Turno::factory()->create(['idTurno' => 'V01', 'dia' => '7', 'nombreTurno' => 'EL VIGENTE']);

    AsignacionTurno::factory()->vencida()->create([
        'ci' => $persona->ci, 'turno_id' => $vencido->id, 'idTurno' => $vencido->idTurno,
    ]);
    AsignacionTurno::factory()->create([
        'ci' => $persona->ci, 'turno_id' => $vigente->id, 'idTurno' => $vigente->idTurno,
        'desde' => now()->subYear(), 'hasta' => now()->addYear(),
    ]);

    // El vigente es sábado (día 7) y el vencido lunes (día 2): si el orden por
    // vigencia no mandara, el lunes iría primero.
    $this->get(route('licencias.create', ['ci' => $persona->ci, 'vencidos' => 1]))
        ->assertOk()
        ->assertSeeInOrder(['EL VIGENTE', 'EL VENCIDO']);
});

test('avisa cuando el funcionario solo tiene turnos vencidos', function () {
    $persona = Persona::factory()->create(['ci' => '7633685']);
    fakeMamore(['7633685' => 'MARIELA CRUZ PORCO']);
    $turno = Turno::factory()->create(['idTurno' => 'X01', 'dia' => '2']);

    AsignacionTurno::factory()->vencida()->create([
        'ci' => $persona->ci, 'turno_id' => $turno->id, 'idTurno' => $turno->idTurno,
    ]);

    $this->get(route('licencias.create', ['ci' => $persona->ci]))
        ->assertOk()
        ->assertSee('no tiene turnos vigentes');
});

test('la grilla ignora los turnos eliminados lógicamente', function () {
    $persona = Persona::factory()->create(['ci' => '7633685']);
    fakeMamore(['7633685' => 'MARIELA CRUZ PORCO']);
    $turno = Turno::factory()->create(['idTurno' => 'B01', 'dia' => '2', 'nombreTurno' => 'TURNO BORRADO']);

    AsignacionTurno::factory()->create([
        'ci' => $persona->ci, 'turno_id' => $turno->id, 'idTurno' => $turno->idTurno,
        'desde' => now()->subYear(), 'hasta' => now()->addYear(),
    ]);

    $turno->delete();

    $this->get(route('licencias.create', ['ci' => $persona->ci]))
        ->assertOk()
        ->assertDontSee('TURNO BORRADO');
});

test('sin funcionario elegido pide elegir uno', function () {
    $this->get(route('licencias.create'))
        ->assertOk()
        ->assertSee('Elegí un funcionario para ver sus turnos asignados.');
});

test('el combo busca funcionarios en Mamoré por ci o nombre', function () {
    fakeMamore(['7633685' => 'MARIELA CRUZ PORCO', '1924269' => 'JUAN PEREZ ROJAS']);

    $this->getJson(route('licencias.funcionarios', ['q' => 'cruz']))
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonFragment(['id' => '7633685', 'texto' => '7633685 — MARIELA CRUZ PORCO']);

    $this->getJson(route('licencias.funcionarios', ['q' => '1924269']))
        ->assertOk()
        ->assertJsonFragment(['id' => '1924269']);
});

test('el combo muestra el cargo y la dirección del funcionario', function () {
    fakeMamore([
        '7604314' => ['nombre' => 'LUIS ALPIRE DURAN', 'cargo' => 'Analista II', 'direccion' => 'SDAF'],
        '1924269' => 'JUAN PEREZ ROJAS',
    ]);

    // Con contrato: el combo agrega el cargo y la sigla de la dirección.
    $this->getJson(route('licencias.funcionarios', ['q' => 'alpire']))
        ->assertOk()
        ->assertJsonFragment(['id' => '7604314', 'texto' => '7604314 — LUIS ALPIRE DURAN · Analista II (SDAF)']);

    // Sin contrato: sigue apareciendo, solo que sin cargo.
    $this->getJson(route('licencias.funcionarios', ['q' => 'perez']))
        ->assertOk()
        ->assertJsonFragment(['id' => '1924269', 'texto' => '1924269 — JUAN PEREZ ROJAS']);
});

test('la pantalla de licenciar muestra el cargo del funcionario elegido', function () {
    fakeMamore(['7604314' => ['nombre' => 'LUIS ALPIRE DURAN', 'cargo' => 'Analista II', 'direccion' => 'SDAF']]);

    $this->get(route('licencias.create', ['ci' => '7604314']))
        ->assertOk()
        ->assertSee('Analista II')
        ->assertSee('Dirección administrativa');
});

test('el combo no devuelve funcionarios que solo existen en la base local', function () {
    Persona::factory()->create(['ci' => '7633685', 'nombres' => 'IGNACIO', 'paterno' => 'MOLINA']);
    fakeMamore(); // padrón vacío

    $this->getJson(route('licencias.funcionarios', ['q' => 'molina']))
        ->assertOk()
        ->assertJsonCount(0);
});

test('el combo cruza nombre y apellido aunque la API busque por un solo término', function () {
    fakeMamore(['111' => 'MARIELA CRUZ PORCO', '222' => 'MARIELA GUZMAN TECO']);

    $this->getJson(route('licencias.funcionarios', ['q' => 'mariela cruz']))
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonFragment(['id' => '111']);
});

test('el combo informa el error cuando la API de Mamoré falla', function () {
    config()->set('services.mamore.url', 'http://mamore.test/api/personal');
    config()->set('services.mamore.key', 'secreta');
    Http::fake(['mamore.test/*' => Http::response('boom', 500)]);

    $this->getJson(route('licencias.funcionarios', ['q' => 'cruz']))
        ->assertStatus(502)
        ->assertJsonPath('error', 'La API de Mamoré respondió con un error (500).');
});

test('sube el respaldo al bucket y lo comparte con todas las filas del rango', function () {
    Storage::fake('s3');
    [$persona, $asignacion] = funcionarioConTurno(dia: 2);

    $this->post(route('licencias.store'), [
        'modo' => 'uno',
        'ci' => $persona->ci,
        'asignaciones' => [$asignacion->id],
        'desde' => '2025-03-03',
        'hasta' => '2025-03-16',
        'tCompleto' => '1',
        'goceHaberes' => '1',
        'motivo' => 'BAJA MEDICA',
        'respaldo' => UploadedFile::fake()->create('certificado médico.pdf', 120, 'application/pdf'),
    ])->assertRedirect();

    $licencias = Licencia::query()->orderBy('fecha')->get();

    // Las dos filas del rango son la misma licencia partida por día: comparten
    // el archivo en vez de subirlo dos veces.
    expect($licencias)->toHaveCount(2)
        ->and($licencias[0]->adjunto)->not->toBeNull()
        ->and($licencias[1]->adjunto)->toBe($licencias[0]->adjunto)
        // El nombre original se conserva aparte; el del bucket es aleatorio.
        ->and($licencias[0]->adjuntoNombre)->toBe('certificado médico.pdf')
        ->and($licencias[0]->adjunto)->not->toContain('certificado');

    Storage::disk('s3')->assertExists($licencias[0]->adjunto);

    // La ruta se ordena por año y cédula, para que el bucket siga navegable.
    expect($licencias[0]->adjunto)->toStartWith('licencias/'.now()->format('Y').'/'.trim($persona->ci).'/');
});

test('se anota una licencia sin respaldo', function () {
    [$persona, $asignacion] = funcionarioConTurno(dia: 2);

    // El respaldo es opcional: el certificado se suele presentar al volver, y
    // el alta masiva de un feriado no tiene un documento por funcionario.
    $this->post(route('licencias.store'), [
        'modo' => 'uno',
        'ci' => $persona->ci,
        'asignaciones' => [$asignacion->id],
        'desde' => '2025-03-03',
        'hasta' => '2025-03-03',
        'tCompleto' => '1',
        'goceHaberes' => '1',
        'motivo' => 'FERIADO',
    ])->assertSessionHasNoErrors();

    $licencia = Licencia::query()->sole();

    expect($licencia->adjunto)->toBeNull()
        ->and($licencia->adjuntoNombre)->toBeNull();
});

test('rechaza un respaldo que no sea imagen ni PDF', function () {
    Storage::fake('s3');
    [$persona, $asignacion] = funcionarioConTurno(dia: 2);

    $this->post(route('licencias.store'), [
        'modo' => 'uno',
        'ci' => $persona->ci,
        'asignaciones' => [$asignacion->id],
        'desde' => '2025-03-03',
        'hasta' => '2025-03-03',
        'tCompleto' => '1',
        'goceHaberes' => '1',
        'motivo' => 'PRUEBA',
        'respaldo' => UploadedFile::fake()->create('planilla.xlsx', 50),
    ])->assertSessionHasErrors('respaldo');

    expect(Licencia::query()->count())->toBe(0);
    expect(Storage::disk('s3')->allFiles())->toBeEmpty();
});

test('rechaza un respaldo de más de 5 MB', function () {
    Storage::fake('s3');
    [$persona, $asignacion] = funcionarioConTurno(dia: 2);

    $this->post(route('licencias.store'), [
        'modo' => 'uno',
        'ci' => $persona->ci,
        'asignaciones' => [$asignacion->id],
        'desde' => '2025-03-03',
        'hasta' => '2025-03-03',
        'tCompleto' => '1',
        'goceHaberes' => '1',
        'motivo' => 'PRUEBA',
        'respaldo' => UploadedFile::fake()->create('escaneo.pdf', 6000, 'application/pdf'),
    ])->assertSessionHasErrors('respaldo');

    expect(Licencia::query()->count())->toBe(0);
});

test('el listado enlaza el respaldo solo cuando la licencia lo tiene', function () {
    Persona::factory()->create(['ci' => '7633685']);
    $conRespaldo = Licencia::factory()->create(['ci' => '7633685', 'adjunto' => 'licencias/2026/7633685/x.pdf']);
    $sinRespaldo = Licencia::factory()->create(['ci' => '7633685', 'adjunto' => null]);

    $this->get(route('licencias.list'))
        ->assertOk()
        ->assertSee(route('licencias.respaldo', $conRespaldo), escape: false)
        ->assertDontSee(route('licencias.respaldo', $sinRespaldo), escape: false);
});

test('la descarga del respaldo avisa cuando la licencia no tiene archivo', function () {
    Storage::fake('s3');
    $licencia = Licencia::factory()->create(['ci' => '7633685', 'adjunto' => null]);

    $this->get(route('licencias.respaldo', $licencia))
        ->assertRedirect()
        ->assertSessionHas('error');
});

test('anota una licencia por cada día del rango que coincide con el turno', function () {
    [$persona, $asignacion] = funcionarioConTurno(dia: 2); // lunes

    // Del lunes 2025-03-03 al domingo 2025-03-16: dos lunes en el rango.
    $this->post(route('licencias.store'), [
        'modo' => 'uno',
        'ci' => $persona->ci,
        'asignaciones' => [$asignacion->id],
        'desde' => '2025-03-03',
        'hasta' => '2025-03-16',
        'tCompleto' => '1',
        'goceHaberes' => '1',
        'motivo' => 'PRUEBA DIAS',
        'respaldo' => respaldoDePrueba(),
    ])
        ->assertRedirect(route('licencias.index', ['q' => $persona->ci]))
        ->assertSessionHas('estado');

    $licencias = Licencia::query()->orderBy('fecha')->get();

    expect($licencias)->toHaveCount(2)
        ->and($licencias[0]->fecha->format('Y-m-d'))->toBe('2025-03-03')
        ->and($licencias[1]->fecha->format('Y-m-d'))->toBe('2025-03-10')
        ->and($licencias[0]->turno_id)->toBe($asignacion->turno_id)
        // El código del SIA no se escribe: el horario va por la FK.
        ->and($licencias[0]->idTurno)->toBeNull()
        ->and($licencias[0]->tCompleto)->toBeTrue()
        ->and($licencias[0]->goceHaberes)->toBeTrue()
        ->and($licencias[0]->lEntra)->toBeNull()
        ->and($licencias[0]->lSale)->toBeNull()
        ->and($licencias[0]->motivo)->toBe('PRUEBA DIAS');
});

test('fechaPedido guarda la fecha y la hora actuales del sistema', function () {
    [$persona, $asignacion] = funcionarioConTurno(dia: 2);

    // Hora local de Bolivia: si la app quedara en UTC, se guardaría 4 h adelante.
    Carbon::setTestNow(Carbon::create(2026, 7, 26, 18, 35, 42, config('app.timezone')));

    $this->post(route('licencias.store'), [
        'modo' => 'uno',
        'ci' => $persona->ci,
        'asignaciones' => [$asignacion->id],
        'desde' => '2026-07-27',
        'hasta' => '2026-07-27',
        'tCompleto' => '1',
        'goceHaberes' => '1',
        'motivo' => 'VACACION',
        'respaldo' => respaldoDePrueba(),
    ])->assertRedirect();

    expect(Licencia::query()->firstOrFail()->fechaPedido->format('Y-m-d H:i:s'))
        ->toBe('2026-07-26 18:35:42');

    Carbon::setTestNow();
});

test('la aplicación usa la hora de Bolivia, no UTC', function () {
    // El SIA y los biométricos guardan en hora local: con la app en UTC todos
    // los sellos de tiempo propios quedaban 4 horas adelantados.
    expect(config('app.timezone'))->toBe('America/La_Paz');
});

test('la licencia por horas guarda las horas sobre la fecha base 1899-12-30', function () {
    [$persona, $asignacion] = funcionarioConTurno(dia: 2);

    $this->post(route('licencias.store'), [
        'modo' => 'uno',
        'ci' => $persona->ci,
        'asignaciones' => [$asignacion->id],
        'desde' => '2025-03-03',
        'hasta' => '2025-03-03',
        'goceHaberes' => '1',
        'lEntra' => '09:30',
        'lSale' => '12:00',
        'motivo' => 'LLEGADA TARDE',
        'respaldo' => respaldoDePrueba(),
    ])->assertRedirect();

    $licencia = Licencia::query()->firstOrFail();

    expect($licencia->tCompleto)->toBeFalse()
        ->and($licencia->lEntra->format('Y-m-d H:i'))->toBe('1899-12-30 09:30')
        ->and($licencia->lSale->format('Y-m-d H:i'))->toBe('1899-12-30 12:00');
});

test('no duplica una licencia ya registrada para el mismo día y turno', function () {
    [$persona, $asignacion] = funcionarioConTurno(dia: 2);

    $envio = [
        'modo' => 'uno',
        'ci' => $persona->ci,
        'asignaciones' => [$asignacion->id],
        'desde' => '2025-03-03',
        'hasta' => '2025-03-03',
        'tCompleto' => '1',
        'goceHaberes' => '1',
        'motivo' => 'VACACION',
    ];

    // Un `UploadedFile` se consume al subirlo: cada envío lleva el suyo.
    $this->post(route('licencias.store'), $envio + ['respaldo' => respaldoDePrueba()])->assertRedirect();
    $this->post(route('licencias.store'), $envio + ['respaldo' => respaldoDePrueba()])->assertSessionHas('error');

    expect(Licencia::query()->count())->toBe(1);
});

test('una licencia dada de baja no se pisa: el pedido nuevo es otra fila', function () {
    [$persona, $asignacion] = funcionarioConTurno(dia: 2);

    $envio = [
        'modo' => 'uno',
        'ci' => $persona->ci,
        'asignaciones' => [$asignacion->id],
        'desde' => '2025-03-03',
        'hasta' => '2025-03-03',
        'tCompleto' => '1',
        'goceHaberes' => '1',
        'motivo' => 'VACACION',
    ];

    $this->post(route('licencias.store'), $envio + ['respaldo' => respaldoDePrueba()])->assertRedirect();
    Licencia::query()->firstOrFail()->delete();

    $this->post(route('licencias.store'), ['motivo' => 'COMISION', 'respaldo' => respaldoDePrueba()] + $envio)
        ->assertSessionHas('estado');

    $licencia = Licencia::query()->firstOrFail();

    // La dada de baja queda como historial y el pedido nuevo entra aparte: por
    // eso el índice único incluye `solicitud`.
    expect(Licencia::withTrashed()->count())->toBe(2)
        ->and(Licencia::count())->toBe(1)
        ->and($licencia->motivo)->toBe('COMISION')
        ->and($licencia->trashed())->toBeFalse()
        ->and(Licencia::onlyTrashed()->firstOrFail()->motivo)->toBe('VACACION');
});

test('no anota nada si el rango cae fuera de la vigencia del turno asignado', function () {
    $persona = Persona::factory()->create(['ci' => '7633685']);
    $turno = Turno::factory()->create(['idTurno' => 'T2A', 'dia' => '2']);
    $asignacion = AsignacionTurno::factory()->create([
        'ci' => $persona->ci,
        'turno_id' => $turno->id,
        'idTurno' => $turno->idTurno,
        'desde' => '2020-01-01 00:00:00',
        'hasta' => '2020-12-31 00:00:00',
    ]);

    $this->post(route('licencias.store'), [
        'modo' => 'uno',
        'ci' => $persona->ci,
        'asignaciones' => [$asignacion->id],
        'desde' => '2025-03-03',
        'hasta' => '2025-03-03',
        'tCompleto' => '1',
        'goceHaberes' => '1',
        'motivo' => 'VACACION',
        'respaldo' => respaldoDePrueba(),
    ])->assertSessionHas('error');

    expect(Licencia::query()->count())->toBe(0);
});

test('rechaza turnos que no son del funcionario elegido', function () {
    [$persona] = funcionarioConTurno(dia: 2, ci: '111');
    [, $ajena] = funcionarioConTurno(dia: 3, ci: '222');

    $this->post(route('licencias.store'), [
        'modo' => 'uno',
        'ci' => $persona->ci,
        'asignaciones' => [$ajena->id],
        'desde' => '2025-03-04',
        'hasta' => '2025-03-04',
        'tCompleto' => '1',
        'goceHaberes' => '1',
        'motivo' => 'VACACION',
        'respaldo' => respaldoDePrueba(),
    ])->assertSessionHas('error');

    expect(Licencia::query()->count())->toBe(0);
});

test('valida funcionario, fechas y motivo obligatorios', function () {
    $this->post(route('licencias.store'), [
        'respaldo' => respaldoDePrueba(),
    ])
        ->assertSessionHasErrors(['ci', 'desde', 'hasta', 'motivo']);

    expect(Licencia::query()->count())->toBe(0);
});

test('sin turnos elegidos licencia todos los días del rango con turno', function () {
    $persona = Persona::factory()->create(['ci' => '7633685']);

    // Jornada continua de lunes a viernes, como el caso real.
    foreach ([2 => 'LUN', 3 => 'MAR', 4 => 'MIE', 5 => 'JUE', 6 => 'VIE'] as $dia => $etiqueta) {
        $turno = Turno::factory()->create(['idTurno' => 'D'.$dia, 'dia' => (string) $dia, 'nombreTurno' => $etiqueta]);
        AsignacionTurno::factory()->create([
            'ci' => $persona->ci, 'turno_id' => $turno->id, 'idTurno' => $turno->idTurno,
            'desde' => '2026-01-05 00:00:00', 'hasta' => '2026-12-31 00:00:00',
        ]);
    }

    // Lunes 27/07/2026 al viernes 31/07/2026: cinco días hábiles, sin marcar nada.
    $this->post(route('licencias.store'), [
        'modo' => 'uno',
        'ci' => $persona->ci,
        'desde' => '2026-07-27',
        'hasta' => '2026-07-31',
        'tCompleto' => '1',
        'goceHaberes' => '1',
        'motivo' => 'VACACION',
        'respaldo' => respaldoDePrueba(),
    ])->assertSessionHas('estado');

    expect(Licencia::query()->count())->toBe(5)
        ->and(Licencia::query()->orderBy('fecha')->pluck('fecha')
            ->map(fn ($f) => $f->format('Y-m-d'))->all())
        ->toBe(['2026-07-27', '2026-07-28', '2026-07-29', '2026-07-30', '2026-07-31']);
});

test('sin turnos elegidos saltea los días sin turno asignado', function () {
    $persona = Persona::factory()->create(['ci' => '7633685']);
    $turno = Turno::factory()->create(['idTurno' => 'L01', 'dia' => '2', 'nombreTurno' => 'SOLO LUNES']);
    AsignacionTurno::factory()->create([
        'ci' => $persona->ci, 'turno_id' => $turno->id, 'idTurno' => $turno->idTurno,
        'desde' => '2026-01-05 00:00:00', 'hasta' => '2026-12-31 00:00:00',
    ]);

    // Semana completa, pero solo trabaja los lunes.
    $this->post(route('licencias.store'), [
        'modo' => 'uno',
        'ci' => $persona->ci,
        'desde' => '2026-07-27',
        'hasta' => '2026-07-31',
        'tCompleto' => '1',
        'goceHaberes' => '1',
        'motivo' => 'VACACION',
        'respaldo' => respaldoDePrueba(),
    ])->assertSessionHas('estado');

    expect(Licencia::query()->count())->toBe(1)
        ->and(Licencia::query()->firstOrFail()->fecha->format('Y-m-d'))->toBe('2026-07-27');
});

test('sin turnos elegidos avisa si el funcionario no tiene turnos en el rango', function () {
    $persona = Persona::factory()->create(['ci' => '7633685']);
    $turno = Turno::factory()->create(['idTurno' => 'L01', 'dia' => '2']);
    AsignacionTurno::factory()->create([
        'ci' => $persona->ci, 'turno_id' => $turno->id, 'idTurno' => $turno->idTurno,
        'desde' => '2020-01-01 00:00:00', 'hasta' => '2020-12-31 00:00:00',
    ]);

    $this->post(route('licencias.store'), [
        'modo' => 'uno',
        'ci' => $persona->ci,
        'desde' => '2026-07-27',
        'hasta' => '2026-07-31',
        'tCompleto' => '1',
        'goceHaberes' => '1',
        'motivo' => 'VACACION',
        'respaldo' => respaldoDePrueba(),
    ])->assertSessionHas('error');

    expect(Licencia::query()->count())->toBe(0);
});

test('con selección manual licencia solo el turno elegido del día doble', function () {
    $persona = Persona::factory()->create(['ci' => '7633685']);

    $manana = Turno::factory()->create(['idTurno' => 'M01', 'dia' => '2', 'nombreTurno' => 'LUN MANANA']);
    $tarde = Turno::factory()->create(['idTurno' => 'T01', 'dia' => '2', 'nombreTurno' => 'LUN TARDE']);

    foreach ([$manana, $tarde] as $turno) {
        AsignacionTurno::factory()->create([
            'ci' => $persona->ci, 'turno_id' => $turno->id, 'idTurno' => $turno->idTurno,
            'desde' => '2026-01-05 00:00:00', 'hasta' => '2026-12-31 00:00:00',
        ]);
    }

    $soloTarde = AsignacionTurno::query()->where('turno_id', $tarde->id)->firstOrFail();

    $this->post(route('licencias.store'), [
        'modo' => 'uno',
        'ci' => $persona->ci,
        'asignaciones' => [$soloTarde->id],
        'desde' => '2026-07-27',
        'hasta' => '2026-07-27',
        'tCompleto' => '1',
        'goceHaberes' => '1',
        'motivo' => 'PERMISO TARDE',
        'respaldo' => respaldoDePrueba(),
    ])->assertSessionHas('estado');

    expect(Licencia::query()->count())->toBe(1)
        ->and(Licencia::query()->firstOrFail()->turno_id)->toBe($tarde->id);
});

test('sin turnos elegidos y con día doble licencia los dos turnos', function () {
    $persona = Persona::factory()->create(['ci' => '7633685']);

    foreach (['M01' => 'LUN MANANA', 'T01' => 'LUN TARDE'] as $codigo => $nombre) {
        $turno = Turno::factory()->create(['idTurno' => $codigo, 'dia' => '2', 'nombreTurno' => $nombre]);
        AsignacionTurno::factory()->create([
            'ci' => $persona->ci, 'turno_id' => $turno->id, 'idTurno' => $turno->idTurno,
            'desde' => '2026-01-05 00:00:00', 'hasta' => '2026-12-31 00:00:00',
        ]);
    }

    $this->post(route('licencias.store'), [
        'modo' => 'uno',
        'ci' => $persona->ci,
        'desde' => '2026-07-27',
        'hasta' => '2026-07-27',
        'tCompleto' => '1',
        'goceHaberes' => '1',
        'motivo' => 'DIA COMPLETO',
        'respaldo' => respaldoDePrueba(),
    ])->assertSessionHas('estado');

    expect(Licencia::query()->count())->toBe(2);
});

test('exige las horas cuando no es turno completo', function () {
    [$persona, $asignacion] = funcionarioConTurno(dia: 2);

    $this->post(route('licencias.store'), [
        'modo' => 'uno',
        'ci' => $persona->ci,
        'asignaciones' => [$asignacion->id],
        'desde' => '2025-03-03',
        'hasta' => '2025-03-03',
        'goceHaberes' => '1',
        'motivo' => 'LLEGADA TARDE',
        'respaldo' => respaldoDePrueba(),
    ])->assertSessionHasErrors(['lEntra', 'lSale']);

    expect(Licencia::query()->count())->toBe(0);
});

test('rechaza un rango invertido o desmedido', function () {
    [$persona, $asignacion] = funcionarioConTurno(dia: 2);

    $base = [
        'modo' => 'uno',
        'ci' => $persona->ci,
        'asignaciones' => [$asignacion->id],
        'tCompleto' => '1',
        'goceHaberes' => '1',
        'motivo' => 'VACACION',
    ];

    $this->post(route('licencias.store'), $base + ['desde' => '2025-03-10', 'hasta' => '2025-03-03'])
        ->assertSessionHasErrors('hasta');

    $this->post(route('licencias.store'), $base + ['desde' => '2020-01-01', 'hasta' => '2025-01-01'])
        ->assertSessionHasErrors('hasta');

    expect(Licencia::query()->count())->toBe(0);
});

/**
 * Funcionarios con jornada continua de lunes a viernes vigente en 2026, como el
 * caso real: es el escenario de los feriados que alcanzan a más de 400 personas.
 *
 * @return list<Persona>
 */
function plantelDeLunesAViernes(int $cuantos = 3): array
{
    $turnos = collect([2 => 'LUN', 3 => 'MAR', 4 => 'MIE', 5 => 'JUE', 6 => 'VIE'])
        ->map(fn (string $etiqueta, int $dia) => Turno::factory()->create([
            'idTurno' => 'D'.$dia,
            'dia' => (string) $dia,
            'nombreTurno' => $etiqueta,
        ]));

    $personas = [];

    foreach (range(1, $cuantos) as $n) {
        $persona = Persona::factory()->create(['ci' => (string) (1000000 + $n)]);

        foreach ($turnos as $turno) {
            AsignacionTurno::factory()->create([
                'ci' => $persona->ci, 'turno_id' => $turno->id, 'idTurno' => $turno->idTurno,
                'desde' => '2026-01-05 00:00:00', 'hasta' => '2026-12-31 00:00:00',
            ]);
        }

        $personas[] = $persona;
    }

    return $personas;
}

test('licencia grupal: anota a los funcionarios elegidos y deja fuera al resto', function () {
    [$uno, $dos, $tres] = plantelDeLunesAViernes(3);

    $this->post(route('licencias.store'), [
        'modo' => 'varios',
        'cis' => [$uno->ci, $dos->ci],
        'desde' => '2026-07-27',
        'hasta' => '2026-07-27',
        'tCompleto' => '1',
        'goceHaberes' => '1',
        'motivo' => 'COMISION DE VIAJE',
        'respaldo' => respaldoDePrueba(),
    ])
        ->assertRedirect(route('licencias.index'))
        ->assertSessionHas('estado');

    expect(Licencia::query()->count())->toBe(2)
        ->and(Licencia::query()->pluck('ci')->map(fn ($ci) => trim($ci))->sort()->values()->all())
        ->toBe([trim($uno->ci), trim($dos->ci)])
        ->and(Licencia::query()->where('ci', $tres->ci)->exists())->toBeFalse();
});

test('licencia grupal: el modo «todos» alcanza a quien tenga turno en el rango', function () {
    plantelDeLunesAViernes(3);

    // Este no trabaja en 2026: no tiene que recibir la licencia.
    $antiguo = Persona::factory()->create(['ci' => '9999999']);
    $turnoViejo = Turno::factory()->create(['idTurno' => 'OLD', 'dia' => '2']);
    AsignacionTurno::factory()->create([
        'ci' => $antiguo->ci, 'turno_id' => $turnoViejo->id, 'idTurno' => $turnoViejo->idTurno,
        'desde' => '2019-01-01 00:00:00', 'hasta' => '2019-12-31 00:00:00',
    ]);

    $this->post(route('licencias.store'), [
        'modo' => 'todos',
        'desde' => '2026-07-27',
        'hasta' => '2026-07-27',
        'tCompleto' => '1',
        'goceHaberes' => '1',
        'motivo' => 'FERIADO VIERNES SANTO',
        'respaldo' => respaldoDePrueba(),
    ])->assertSessionHas('estado');

    expect(Licencia::query()->count())->toBe(3)
        ->and(Licencia::query()->where('ci', $antiguo->ci)->exists())->toBeFalse();
});

test('licencia grupal: expande el rango completo por cada funcionario', function () {
    plantelDeLunesAViernes(3);

    // Lunes a viernes × 3 funcionarios = 15 licencias.
    $this->post(route('licencias.store'), [
        'modo' => 'todos',
        'desde' => '2026-07-27',
        'hasta' => '2026-07-31',
        'tCompleto' => '1',
        'goceHaberes' => '1',
        'motivo' => 'TOLERANCIA GENERAL',
        'respaldo' => respaldoDePrueba(),
    ])->assertSessionHas('estado', fn (string $mensaje): bool => str_contains($mensaje, '15 licencia(s) anotada(s)')
        && str_contains($mensaje, '3 funcionario(s)'));

    expect(Licencia::query()->count())->toBe(15);
});

test('licencia grupal: no duplica lo ya registrado y lo informa', function () {
    [$uno, $dos] = plantelDeLunesAViernes(2);

    $envio = [
        'modo' => 'varios',
        'cis' => [$uno->ci, $dos->ci],
        'desde' => '2026-07-27',
        'hasta' => '2026-07-27',
        'tCompleto' => '1',
        'goceHaberes' => '1',
        'motivo' => 'FERIADO',
    ];

    // Primero solo uno; después el grupo entero: el que ya estaba no se duplica.
    $this->post(route('licencias.store'), ['cis' => [$uno->ci], 'respaldo' => respaldoDePrueba()] + $envio)
        ->assertSessionHas('estado');
    $this->post(route('licencias.store'), $envio + ['respaldo' => respaldoDePrueba()])
        ->assertSessionHas('estado', fn (string $mensaje): bool => str_contains($mensaje, '1 licencia(s) anotada(s)')
            && str_contains($mensaje, '1 ya existían'));

    expect(Licencia::query()->count())->toBe(2);
});

test('licencia grupal: guarda el autor y la fecha de pedido en cada fila', function () {
    $admin = asSuperAdmin();
    $this->actingAs($admin);
    plantelDeLunesAViernes(2);

    Carbon::setTestNow(Carbon::create(2026, 7, 26, 9, 15, 0, config('app.timezone')));

    $this->post(route('licencias.store'), [
        'modo' => 'todos',
        'desde' => '2026-07-27',
        'hasta' => '2026-07-27',
        'tCompleto' => '1',
        'goceHaberes' => '1',
        'motivo' => 'FERIADO',
        'respaldo' => respaldoDePrueba(),
    ])->assertSessionHas('estado');

    // El alta masiva usa insert(), que no dispara eventos: la auditoría y los
    // timestamps se completan a mano y tienen que quedar igual que en el alta simple.
    $licencias = Licencia::query()->get();

    expect($licencias)->toHaveCount(2)
        ->and($licencias->every(fn (Licencia $l): bool => $l->registerUser_id === $admin->id))->toBeTrue()
        ->and($licencias->every(fn (Licencia $l): bool => $l->fechaPedido->format('Y-m-d H:i') === '2026-07-26 09:15'))->toBeTrue()
        ->and($licencias->every(fn (Licencia $l): bool => $l->usuario === $admin->name))->toBeTrue();

    Carbon::setTestNow();
});

test('licencia grupal: resuelve el alta masiva en pocas consultas', function () {
    plantelDeLunesAViernes(20);

    $consultas = 0;
    DB::listen(function () use (&$consultas): void {
        $consultas++;
    });

    // 20 funcionarios × 5 días hábiles = 100 licencias. El costo no puede crecer
    // con la cantidad de filas: una consulta de existencia y inserts por bloque.
    $this->post(route('licencias.store'), [
        'modo' => 'todos',
        'desde' => '2026-07-27',
        'hasta' => '2026-07-31',
        'tCompleto' => '1',
        'goceHaberes' => '1',
        'motivo' => 'FERIADO',
        'respaldo' => respaldoDePrueba(),
    ])->assertSessionHas('estado');

    expect(Licencia::query()->count())->toBe(100)
        ->and($consultas)->toBeLessThan(20);
});

test('licencia grupal: avisa si nadie tiene turno en el rango', function () {
    Persona::factory()->create(['ci' => '7633685']);

    $this->post(route('licencias.store'), [
        'modo' => 'todos',
        'desde' => '2026-07-27',
        'hasta' => '2026-07-27',
        'tCompleto' => '1',
        'goceHaberes' => '1',
        'motivo' => 'FERIADO',
        'respaldo' => respaldoDePrueba(),
    ])->assertSessionHas('error');

    expect(Licencia::query()->count())->toBe(0);
});

test('licencia grupal: exige la lista de funcionarios en el modo «varios»', function () {
    $this->post(route('licencias.store'), [
        'modo' => 'varios',
        'desde' => '2026-07-27',
        'hasta' => '2026-07-27',
        'tCompleto' => '1',
        'goceHaberes' => '1',
        'motivo' => 'FERIADO',
        'respaldo' => respaldoDePrueba(),
    ])->assertSessionHasErrors('cis');

    expect(Licencia::query()->count())->toBe(0);
});

test('la pantalla de licenciar ofrece los tres alcances', function () {
    $this->get(route('licencias.create'))
        ->assertOk()
        ->assertSee('Un funcionario')
        ->assertSee('Varios funcionarios')
        ->assertSee('Todos los que trabajen en el rango');
});

test('elimina una licencia de forma lógica y registra quién la borró', function () {
    $admin = asSuperAdmin();
    $this->actingAs($admin);

    $licencia = Licencia::factory()->create();

    $this->from(route('licencias.index'))
        ->delete(route('licencias.destroy', $licencia), ['deleteObservacion' => 'Anotada en la fecha equivocada.'])
        ->assertRedirect(route('licencias.index'));

    expect(Licencia::query()->whereKey($licencia->getKey())->exists())->toBeFalse();

    $borrada = Licencia::onlyTrashed()->find($licencia->getKey());

    expect($borrada)->not->toBeNull()
        ->and($borrada->deleteUser_id)->toBe($admin->id)
        ->and($borrada->deleteObservacion)->toBe('Anotada en la fecha equivocada.');
});

test('la ficha del funcionario ofrece registrar licencia y lista las suyas', function () {
    $persona = Persona::factory()->create(['ci' => '7633685']);
    Licencia::factory()->create(['ci' => $persona->ci, 'motivo' => 'COMISION DE VIAJE']);

    // Las licencias son una solapa de la ficha: la tabla llega por AJAX.
    $this->get(route('funcionarios.show', $persona))
        ->assertOk()
        ->assertSee('Registrar licencia')
        ->assertSee('data-tab="licencias"', escape: false);

    $this->get(route('funcionarios.licencias.list', ['ci' => $persona->ci]))
        ->assertOk()
        ->assertSee('COMISION DE VIAJE');
});

test('la solapa de licencias de la ficha permite eliminarlas', function () {
    $persona = Persona::factory()->create(['ci' => '7633685']);
    $licencia = Licencia::factory()->create(['ci' => $persona->ci, 'fecha' => today()]);

    // La URL viaja dentro del `x-on:click` del modal global, ya como JSON.
    $this->get(route('funcionarios.licencias.list', ['ci' => $persona->ci]))
        ->assertOk()
        ->assertSee(str_replace('/', '\/', route('licencias.destroy', $licencia)), escape: false)
        ->assertSee('$store.eliminar.abrir(', escape: false);

    $this->delete(route('licencias.destroy', $licencia), ['deleteObservacion' => 'Cargada por error.'])
        ->assertRedirect();

    $this->assertSoftDeleted('licencias', ['id' => $licencia->id]);
});

test('sin permiso de eliminar, la solapa de licencias no ofrece la baja', function () {
    $persona = Persona::factory()->create(['ci' => '7633685']);
    $licencia = Licencia::factory()->create(['ci' => $persona->ci]);

    foreach (['ViewAny:Licencia', 'ViewAny:Persona'] as $permiso) {
        Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']);
    }

    $rol = Role::create(['name' => 'solo_lectura_licencias', 'guard_name' => 'web']);
    $rol->givePermissionTo('ViewAny:Licencia', 'ViewAny:Persona');

    $this->actingAs(User::factory()->create()->assignRole($rol));

    $this->get(route('funcionarios.licencias.list', ['ci' => $persona->ci]))
        ->assertOk()
        ->assertDontSee(str_replace('/', '\/', route('licencias.destroy', $licencia)), escape: false);
});

test('la ficha licencia al funcionario desde un modal, sin pasar por la pantalla general', function () {
    $persona = Persona::factory()->create(['ci' => '7633685']);

    $this->get(route('funcionarios.show', $persona))
        ->assertOk()
        ->assertSee('Licenciar a CI 7633685')
        // El alcance ya está resuelto: es este funcionario y ningún otro.
        ->assertSee('<input type="hidden" name="modo" value="uno">', escape: false)
        ->assertSee('<input type="hidden" name="ci" value="7633685">', escape: false)
        ->assertSee('<input type="hidden" name="origen" value="local">', escape: false)
        // Los turnos vigentes se piden a su propio endpoint al abrir el modal.
        ->assertSee('x-ref="turnos"', escape: false)
        ->assertSee('situacion=vigentes', escape: false)
        // Nada de los modos de la pantalla general.
        ->assertDontSee('Varios funcionarios')
        ->assertDontSee('Todos los que trabajen en el rango');
});

test('anotada desde la ficha, la licencia vuelve a la ficha', function () {
    $persona = Persona::factory()->create(['ci' => '7633685']);
    $turno = Turno::factory()->create(['dia' => (string) (today()->dayOfWeek + 1)]);
    AsignacionTurno::factory()->create([
        'ci' => $persona->ci,
        'turno_id' => $turno->id,
        'desde' => today()->subMonth(),
        'hasta' => today()->addMonth(),
    ]);

    $datos = [
        'modo' => 'uno',
        'ci' => $persona->ci,
        'desde' => today()->toDateString(),
        'hasta' => today()->toDateString(),
        'tCompleto' => '1',
        'goceHaberes' => '1',
        'motivo' => 'REUNION SINDICAL',
    ];

    // El ancla deja abierta la solapa de licencias al volver. Cada envío lleva
    // su propio archivo: un `UploadedFile` se consume al subirlo.
    $this->post(route('licencias.store'), $datos + ['origen' => 'local', 'respaldo' => respaldoDePrueba()])
        ->assertRedirect(route('funcionarios.show', ['persona' => $persona->ci]).'#licencias');

    // Cada envío usa otra semana: la misma fecha sería una licencia repetida.
    $siguiente = ['desde' => today()->addWeek()->toDateString(), 'hasta' => today()->addWeek()->toDateString()];
    $ultima = ['desde' => today()->addWeeks(2)->toDateString(), 'hasta' => today()->addWeeks(2)->toDateString()];

    // Sin origen sigue yendo al listado filtrado por el carnet, como antes.
    $this->post(route('licencias.store'), array_merge($datos, $siguiente, ['respaldo' => respaldoDePrueba()]))
        ->assertRedirect(route('licencias.index', ['q' => $persona->ci]));

    // Un origen desconocido no saca al usuario del sistema.
    $this->post(route('licencias.store'), array_merge($datos, $ultima, [
        'origen' => 'https://otro-sitio.test',
        'respaldo' => respaldoDePrueba(),
    ]))->assertRedirect(route('licencias.index', ['q' => $persona->ci]));
});

test('un invitado no puede ver las licencias', function () {
    auth()->logout();

    $this->get(route('licencias.index'))->assertRedirect();
});

test('un usuario sin permiso no puede entrar al listado ni anotar licencias', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('licencias.index'))->assertForbidden();
    $this->get(route('licencias.create'))->assertForbidden();
    $this->post(route('licencias.store'), [
        'respaldo' => respaldoDePrueba(),
    ])->assertForbidden();
});

/**
 * Solicitud hecha desde Mamoré: varias filas de la misma tanda —una por día y
 * turno—, en «Pendiente» y sin usuario de SisMark detrás del alta.
 *
 * @return Collection<int, Licencia>
 */
function solicitudPendiente(string $ci = '7633685', int $dias = 3, string $origen = Licencia::ORIGEN_MAMORE): Collection
{
    Persona::factory()->create(['ci' => $ci, 'nombres' => 'IGNACIO', 'paterno' => 'MOLINA']);
    $turno = Turno::factory()->create(['dia' => '2', 'nombreTurno' => 'LUN: 08:00 - 16:00']);

    // `solicitud` es lo que agrupa la tanda: `RegistroLicencia` escribe el mismo
    // ULID en todas las filas de un alta, por funcionario.
    $solicitud = (string) Str::ulid();

    $licencias = collect(range(0, $dias - 1))->map(fn (int $i) => Licencia::factory()->create([
        'ci' => $ci,
        'turno_id' => $turno->id,
        'fecha' => Carbon::parse('2026-08-03')->addWeeks($i)->toDateString(),
        'fechaPedido' => Carbon::parse('2026-08-15 09:12:00'),
        'solicitud' => $solicitud,
        'motivo' => 'CONSULTA MEDICA',
        'usuario' => 'Ignacio Molina',
        'estado' => Licencia::PENDIENTE,
        'origen' => $origen,
    ]));

    // `RegistersUserEvents` escribe `registerUser_id` con el usuario en sesión
    // al crear, así que el valor no se puede fijar desde la factory. En
    // producción la API no pasa por ahí —usa `Licencia::insert()`, que no
    // dispara eventos de modelo—, que es justo por qué la columna queda nula en
    // lo que llega de Mamoré.
    Licencia::whereIn('id', $licencias->pluck('id'))->update(['registerUser_id' => null]);

    return $licencias->map->fresh();
}

test('la ficha muestra la solicitud entera, no solo el día que se abrió', function () {
    $licencias = solicitudPendiente(dias: 3);

    $this->get(route('licencias.show', $licencias->first()))
        ->assertOk()
        ->assertSee('CONSULTA MEDICA')
        // Los tres días de la tanda, no solo el de la fila abierta.
        ->assertSee('03/08/2026')
        ->assertSee('10/08/2026')
        ->assertSee('17/08/2026')
        // Y de dónde vino el pedido, que ahora lo dice la columna `origen`.
        ->assertSee('Mamoré')
        ->assertSee('Aprobar 3 día(s)');
});

test('aprobar resuelve todos los días pendientes de la solicitud', function () {
    $licencias = solicitudPendiente(dias: 3);

    $this->patch(route('licencias.aprobar', $licencias->first()))
        ->assertRedirect()
        ->assertSessionHas('estado');

    expect(Licencia::where('estado', Licencia::APROBADO)->count())->toBe(3);

    $aprobada = Licencia::firstOrFail();

    expect($aprobada->revisadoPor_id)->toBe(auth()->id())
        ->and($aprobada->revisadoEn)->not->toBeNull();
});

test('la aprobación no alcanza a otra solicitud del mismo funcionario', function () {
    $primera = solicitudPendiente(dias: 2);

    // Misma persona y mismo turno, pero pedida en otro momento: es otra tanda.
    $otra = Licencia::factory()->create([
        'ci' => $primera->first()->ci,
        'turno_id' => $primera->first()->turno_id,
        'fecha' => '2026-09-07',
        'fechaPedido' => Carbon::parse('2026-09-01 08:00:00'),
        'estado' => Licencia::PENDIENTE,
        'registerUser_id' => null,
    ]);

    $this->patch(route('licencias.aprobar', $primera->first()))->assertRedirect();

    expect($otra->fresh()->estado)->toBe(Licencia::PENDIENTE);
});

test('rechazar exige el motivo y se lo guarda para el funcionario', function () {
    $licencias = solicitudPendiente(dias: 2);

    // Sin motivo no se resuelve nada: el funcionario vería «Rechazado» sin saber
    // qué le faltó.
    $this->patch(route('licencias.rechazar', $licencias->first()))
        ->assertSessionHasErrors('observacion');

    expect(Licencia::where('estado', Licencia::PENDIENTE)->count())->toBe(2);

    $this->patch(route('licencias.rechazar', $licencias->first()), [
        'observacion' => 'Falta el certificado médico.',
    ])->assertRedirect();

    expect(Licencia::where('estado', Licencia::RECHAZADO)->count())->toBe(2)
        ->and(Licencia::firstOrFail()->observacion)->toBe('Falta el certificado médico.');
});

test('lo ya resuelto no se vuelve a aprobar desde la ficha', function () {
    $licencias = solicitudPendiente(dias: 2);

    $this->patch(route('licencias.aprobar', $licencias->first()))->assertRedirect();

    // La política solo autoriza sobre lo «Pendiente»: aprobar hacia atrás
    // cambiaría reportes que Recursos Humanos ya firmó.
    $this->patch(route('licencias.rechazar', $licencias->first()->fresh()), [
        'observacion' => 'Me arrepentí.',
    ])->assertForbidden();

    expect(Licencia::where('estado', Licencia::APROBADO)->count())->toBe(2);
});

test('la ficha de una licencia ya aprobada no ofrece resolverla', function () {
    $licencia = Licencia::factory()->create(['estado' => Licencia::APROBADO]);

    $this->get(route('licencias.show', $licencia))
        ->assertOk()
        ->assertDontSee('Resolver la solicitud');
});

test('el listado filtra por estado', function () {
    Licencia::factory()->create(['motivo' => 'PEDIDO NUEVO', 'estado' => Licencia::PENDIENTE]);
    Licencia::factory()->create(['motivo' => 'FERIADO VIEJO', 'estado' => Licencia::APROBADO]);

    $this->get(route('licencias.list', ['estado' => Licencia::PENDIENTE]))
        ->assertOk()
        ->assertSee('PEDIDO NUEVO')
        ->assertDontSee('FERIADO VIEJO');

    // Un estado inventado devuelve el listado completo, no una tabla vacía sin
    // explicación.
    $this->get(route('licencias.list', ['estado' => 'Inventado']))
        ->assertOk()
        ->assertSee('PEDIDO NUEVO')
        ->assertSee('FERIADO VIEJO');
});

test('sin permiso de aprobación no se resuelve ninguna solicitud', function () {
    $licencias = solicitudPendiente();

    $usuario = User::factory()->create();
    $usuario->givePermissionTo(Permission::firstOrCreate(['name' => 'View:Licencia', 'guard_name' => 'web']));
    $this->actingAs($usuario);

    // Puede mirar la solicitud, pero no decidirla.
    $this->get(route('licencias.show', $licencias->first()))->assertOk();
    $this->patch(route('licencias.aprobar', $licencias->first()))->assertForbidden();

    expect(Licencia::where('estado', Licencia::PENDIENTE)->count())->toBe($licencias->count());
});

test('un alta de varios días sale como una sola licencia en el listado', function () {
    [$persona, $asignacion] = funcionarioConTurno(dia: 2); // martes

    // `dia = 2` es lunes en la convención del SIA (1 = Domingo). Del 3 al 17 de
    // agosto de 2026 caen tres: el alta genera tres filas, una por día y turno.
    $this->post(route('licencias.store'), [
        'modo' => 'uno',
        'ci' => $persona->ci,
        'desde' => '2026-08-03',
        'hasta' => '2026-08-17',
        'tCompleto' => '1',
        'goceHaberes' => '1',
        'motivo' => 'CONSULTA MEDICA',
    ])->assertRedirect();

    expect(Licencia::count())->toBe(3)
        // Las tres comparten la solicitud: es lo que las agrupa.
        ->and(Licencia::distinct()->pluck('solicitud'))->toHaveCount(1)
        ->and(Licencia::first()->solicitud)->not->toBeNull();

    // Y en pantalla son una sola fila, con el periodo completo.
    $contenido = $this->get(route('licencias.list'))
        ->assertOk()
        ->assertSee('03/08/2026')
        ->assertSee('17/08/2026')
        ->getContent();

    // El motivo aparece una sola vez: sin agrupar saldría tres.
    expect(substr_count($contenido, 'CONSULTA MEDICA'))->toBe(1);
});

test('un alta para varios funcionarios da una solicitud por cada uno', function () {
    // Un solo turno compartido: `funcionarioConTurno` fija `idTurno` a partir
    // del día, y crear dos con el mismo día chocaría contra su índice único.
    $turno = Turno::factory()->create(['dia' => '2', 'nombreTurno' => 'LUN: 08:00 - 16:00']);

    $cis = collect(['7633685', '6522875'])->each(function (string $ci) use ($turno): void {
        Persona::factory()->create(['ci' => $ci]);
        AsignacionTurno::factory()->create([
            'ci' => $ci,
            'turno_id' => $turno->id,
            'idTurno' => $turno->idTurno,
            'desde' => '2020-01-01 00:00:00',
            'hasta' => '2030-12-31 00:00:00',
        ]);
    });

    [$uno, $otro] = [(object) ['ci' => $cis[0]], (object) ['ci' => $cis[1]]];

    $this->post(route('licencias.store'), [
        'modo' => 'varios',
        'cis' => [$uno->ci, $otro->ci],
        'desde' => '2026-08-03',
        'hasta' => '2026-08-10',
        'tCompleto' => '1',
        'goceHaberes' => '1',
        'motivo' => 'FERIADO DEPARTAMENTAL',
    ])->assertRedirect();

    // Dos días × dos funcionarios = cuatro filas, pero dos solicitudes: la
    // licencia es de cada persona, no del alta.
    expect(Licencia::count())->toBe(4)
        ->and(Licencia::distinct()->pluck('solicitud'))->toHaveCount(2);

    foreach ([$uno->ci, $otro->ci] as $ci) {
        expect(Licencia::where('ci', $ci)->distinct()->pluck('solicitud'))->toHaveCount(1);
    }
});

test('lo migrado del SIA no se agrupa: cada fila es su propia licencia', function () {
    $persona = Persona::factory()->create(['ci' => '7633685']);
    $turnos = collect([2, 3, 4])->map(fn (int $d) => Turno::factory()->create(['dia' => (string) $d]));

    // Mismo `fechaPedido` —el 62% del histórico ni siquiera trae la hora— pero
    // cada fila con su propia `solicitud`, que es como la dejó la migración de
    // relleno: en el SIA la fila es la licencia.
    $historicas = $turnos->map(fn ($turno, $i) => Licencia::factory()->create([
        'ci' => $persona->ci,
        'turno_id' => $turno->id,
        'fecha' => Carbon::parse('2021-06-14')->addDays($i)->toDateString(),
        'fechaPedido' => Carbon::parse('2021-06-14 00:00:00'),
        'motivo' => 'HISTORICO SIA',
    ]));

    // La ficha de una histórica muestra esa fila y nada más: agrupar por
    // `fechaPedido` traería 10.308 filas de hasta 20 años de diferencia.
    $this->get(route('licencias.show', $historicas->first()))
        ->assertOk()
        ->assertSee('14/06/2021')
        ->assertDontSee('15/06/2021');

    // Y el listado las muestra sueltas: tres filas, no una.
    $contenido = $this->get(route('licencias.list'))->getContent();
    expect(substr_count($contenido, 'HISTORICO SIA'))->toBe(3);
});

test('la baja desde la ficha elimina la solicitud entera', function () {
    // Cargada en SisMark: lo que viene de Mamoré no se elimina, se resuelve.
    $licencias = solicitudPendiente(dias: 3, origen: Licencia::ORIGEN_PROPIO);

    $this->delete(route('licencias.destroy', $licencias->first()))
        ->assertRedirect()
        ->assertSessionHas('estado');

    expect(Licencia::count())->toBe(0)
        ->and(Licencia::withTrashed()->count())->toBe(3);
});

test('la baja de una licencia histórica no arrastra a las demás', function () {
    $persona = Persona::factory()->create(['ci' => '7633685']);
    $turno = Turno::factory()->create(['dia' => '2']);

    $primera = Licencia::factory()->create([
        'ci' => $persona->ci, 'turno_id' => $turno->id,
        'fecha' => '2021-06-14', 'fechaPedido' => '2021-06-14 00:00:00', 'solicitud' => null,
    ]);
    Licencia::factory()->create([
        'ci' => $persona->ci, 'turno_id' => $turno->id,
        'fecha' => '2021-06-21', 'fechaPedido' => '2021-06-14 00:00:00', 'solicitud' => null,
    ]);

    $this->delete(route('licencias.destroy', $primera))->assertRedirect();

    expect(Licencia::count())->toBe(1);
});

test('el listado agrupado pagina por solicitud y no por día', function () {
    // 12 solicitudes de 2 días = 24 filas en la base. Con 10 por página (el
    // default), agrupando son 2 páginas; sin agrupar serían 3.
    collect(range(1, 12))->each(fn (int $i) => solicitudPendiente(ci: '90000'.$i, dias: 2));

    expect(Licencia::count())->toBe(24);

    $this->get(route('licencias.list'))
        ->assertOk()
        ->assertSee('page=2')
        ->assertDontSee('page=3');
});

test('una licencia rechazada no se puede eliminar', function () {
    $licencias = solicitudPendiente(dias: 2);

    $this->patch(route('licencias.rechazar', $licencias->first()), [
        'observacion' => 'Falta el certificado.',
    ])->assertRedirect();

    // La fila es la constancia de que se pidió y se negó, con su motivo:
    // borrarla dejaría al funcionario sin saber qué pasó.
    $this->delete(route('licencias.destroy', $licencias->first()->fresh()))
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(Licencia::count())->toBe(2)
        ->and(Licencia::withTrashed()->whereNotNull('deleted_at')->count())->toBe(0);
});

test('el listado no ofrece eliminar una licencia rechazada', function () {
    // Cargada acá, así que antes de rechazarla sí se puede eliminar: lo que se
    // prueba es la regla del rechazo, no la del origen.
    $licencias = solicitudPendiente(dias: 1, origen: Licencia::ORIGEN_PROPIO);

    $contenido = $this->get(route('licencias.list'))->assertOk()->getContent();
    expect($contenido)->toContain('$store.eliminar.abrir(');

    $this->patch(route('licencias.rechazar', $licencias->first()), ['observacion' => 'No.'])->assertRedirect();

    $contenido = $this->get(route('licencias.list'))->assertOk()->getContent();
    expect($contenido)->not->toContain('$store.eliminar.abrir(');
});

test('el listado dice de dónde salió cada licencia', function () {
    [$persona] = funcionarioConTurno(dia: 2);

    // Lo que carga Recursos Humanos acá es «Propio».
    $this->post(route('licencias.store'), [
        'modo' => 'uno',
        'ci' => $persona->ci,
        'desde' => '2026-08-03',
        'hasta' => '2026-08-03',
        'tCompleto' => '1',
        'goceHaberes' => '1',
        'motivo' => 'CARGADA EN SISMARK',
    ])->assertRedirect();

    expect(Licencia::firstOrFail()->origen)->toBe(Licencia::ORIGEN_PROPIO);

    $this->get(route('licencias.list'))->assertOk()->assertSee('Propio');
});

test('una licencia pedida desde Mamoré no se elimina, se resuelve', function () {
    $licencias = solicitudPendiente(dias: 2);

    expect($licencias->first()->origen)->toBe(Licencia::ORIGEN_MAMORE);

    $this->delete(route('licencias.destroy', $licencias->first()))
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(Licencia::count())->toBe(2);

    // Lo que sí se puede es resolverla.
    $this->patch(route('licencias.aprobar', $licencias->first()))->assertRedirect();

    expect(Licencia::where('estado', Licencia::APROBADO)->count())->toBe(2);
});

test('aprobada desde Mamoré tampoco se elimina', function () {
    $licencias = solicitudPendiente(dias: 1);
    $this->patch(route('licencias.aprobar', $licencias->first()))->assertRedirect();

    $this->delete(route('licencias.destroy', $licencias->first()->fresh()))
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(Licencia::count())->toBe(1);
});

test('el listado no ofrece eliminar lo que vino de Mamoré', function () {
    solicitudPendiente(dias: 1);

    $contenido = $this->get(route('licencias.list'))->assertOk()->getContent();

    expect($contenido)->toContain('Mamoré')
        ->and($contenido)->not->toContain('$store.eliminar.abrir(');
});

test('al rechazar se vuelve a la ficha del funcionario', function () {
    $licencias = solicitudPendiente(dias: 2);
    $persona = Persona::where('ci', $licencias->first()->ci)->firstOrFail();

    // El pedido quedó cerrado: lo que sigue es mirar el resto de sus licencias.
    $this->patch(route('licencias.rechazar', $licencias->first()), [
        'observacion' => 'No corresponde.',
    ])->assertRedirect(route('funcionarios.show', ['persona' => $persona]).'#licencias')
        ->assertSessionHas('estado');

    expect(Licencia::where('estado', Licencia::RECHAZADO)->count())->toBe(2);
});

test('sin registro local, el rechazo vuelve a la ficha por cédula', function () {
    $licencias = solicitudPendiente(dias: 1);
    // El padrón lo manda Mamoré: no todos tienen fila en `personas`.
    Persona::where('ci', $licencias->first()->ci)->forceDelete();

    $this->patch(route('licencias.rechazar', $licencias->first()->fresh()), [
        'observacion' => 'No corresponde.',
    ])->assertRedirect(route('funcionarios.mamore', ['ci' => '7633685']).'#licencias');
});

test('al aprobar se queda en la ficha de la solicitud', function () {
    $licencias = solicitudPendiente(dias: 2);

    // Se queda donde está, para poder comprobar cómo quedaron los días.
    $this->from(route('licencias.show', $licencias->first()))
        ->patch(route('licencias.aprobar', $licencias->first()))
        ->assertRedirect(route('licencias.show', $licencias->first()));
});

test('la barra avisa cuántas solicitudes esperan decisión', function () {
    // Un pedido de tres días es **una** solicitud, no tres.
    solicitudPendiente(ci: '7633685', dias: 3);
    solicitudPendiente(ci: '6522875', dias: 2);

    expect(Licencia::count())->toBe(5)
        ->and(Licencia::solicitudesPendientes())->toBe(2);

    $this->get(route('licencias.index'))
        ->assertOk()
        ->assertSee('Licencias pendientes')
        // El enlace lleva al listado ya filtrado.
        ->assertSee(route('licencias.index', ['estado' => Licencia::PENDIENTE]), escape: false);
});

test('sin solicitudes pendientes no se muestra el aviso', function () {
    $licencias = solicitudPendiente(dias: 1);
    $this->patch(route('licencias.aprobar', $licencias->first()))->assertRedirect();

    // Un cero permanente deja de mirarse: si no hay nada, no aparece.
    expect(Licencia::solicitudesPendientes())->toBe(0);

    $this->get(route('licencias.index'))->assertOk()->assertDontSee('Licencias pendientes');
});

test('quien no puede ver licencias no recibe el aviso', function () {
    solicitudPendiente(dias: 1);

    $usuario = User::factory()->create();
    $usuario->givePermissionTo(Permission::firstOrCreate(['name' => 'ViewAny:Persona', 'guard_name' => 'web']));

    // El aviso solo le sirve a quien puede resolverlas.
    $this->actingAs($usuario)->get(route('funcionarios.index'))
        ->assertOk()
        ->assertDontSee('Licencias pendientes');
});
