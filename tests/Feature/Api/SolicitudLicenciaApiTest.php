<?php

use App\Models\AsignacionTurno;
use App\Models\Licencia;
use App\Models\Persona;
use App\Models\Turno;
use App\Services\RegistroLicencia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const CLAVE_SOLICITUD = 'clave-de-prueba-de-la-api';

beforeEach(function () {
    config()->set('services.sismark_api.key', CLAVE_SOLICITUD);
    // Ningún test toca el bucket real.
    Storage::fake('s3');
});

/**
 * Funcionario con turno de lunes vigente, que es lo único que lo hace
 * licenciable: sin turno asignado no hay nada que licenciar.
 */
function funcionarioLicenciable(string $ci = '7633685'): void
{
    Persona::factory()->create(['ci' => $ci, 'nombres' => 'IGNACIO', 'paterno' => 'MOLINA']);

    // `dia` va en la convención del SIA: 1 = Domingo … 7 = Sábado.
    $turno = Turno::factory()->create(['dia' => '2', 'nombreTurno' => 'LUN: 08:00 - 16:00']);

    AsignacionTurno::factory()->create([
        'ci' => $ci,
        'turno_id' => $turno->id,
        'idTurno' => $turno->idTurno,
        'desde' => '2020-01-01 00:00:00',
        'hasta' => '2030-12-31 00:00:00',
    ]);
}

/**
 * Solicitud mínima válida: un lunes, turno completo.
 *
 * @return array<string, mixed>
 */
function solicitudValida(array $extra = []): array
{
    return $extra + [
        'desde' => '2026-08-03',
        'hasta' => '2026-08-03',
        'motivo' => 'CONSULTA MEDICA',
        'tCompleto' => true,
        'solicitante' => 'Ignacio Molina',
    ];
}

test('la solicitud del funcionario queda «Pendiente» y sin usuario de SisMark', function () {
    funcionarioLicenciable();

    $this->postJson('/api/v1/funcionarios/7633685/licencias', solicitudValida(), ['X-API-KEY' => CLAVE_SOLICITUD])
        ->assertCreated()
        ->assertJsonPath('estado', 'Pendiente');

    $licencia = Licencia::firstOrFail();

    expect($licencia->estado)->toBe(Licencia::PENDIENTE)
        // Quien pide no tiene cuenta en SisMark: la columna es una FK a `users`
        // y cualquier id ahí sería mentira.
        ->and($licencia->registerUser_id)->toBeNull()
        // La atribución no se pierde, cambia de columna.
        ->and($licencia->usuario)->toBe('Ignacio Molina')
        ->and($licencia->goceHaberes)->toBeTrue();
});

test('una solicitud pendiente no justifica la ausencia', function () {
    funcionarioLicenciable();

    $this->postJson('/api/v1/funcionarios/7633685/licencias', solicitudValida(), ['X-API-KEY' => CLAVE_SOLICITUD])
        ->assertCreated();

    // El cálculo de asistencia solo mira las aprobadas: mientras espere
    // decisión, la licencia no puede tapar una falta.
    expect(Licencia::where('estado', Licencia::APROBADO)->count())->toBe(0);
});

test('la solicitud puede traer un respaldo y queda guardado en el bucket', function () {
    funcionarioLicenciable();

    $archivo = UploadedFile::fake()->create('certificado.pdf', 40, 'application/pdf');

    $this->post(
        '/api/v1/funcionarios/7633685/licencias',
        solicitudValida(['respaldo' => $archivo]),
        ['X-API-KEY' => CLAVE_SOLICITUD, 'Accept' => 'application/json'],
    )->assertCreated();

    $licencia = Licencia::firstOrFail();

    expect($licencia->adjunto)->not->toBeNull()
        // El nombre original se conserva aparte: es el que se le muestra a quien
        // descarga, mientras que en el bucket el archivo va con nombre aleatorio.
        ->and($licencia->adjuntoNombre)->toBe('certificado.pdf');

    Storage::disk('s3')->assertExists($licencia->adjunto);
});

test('el respaldo es opcional', function () {
    funcionarioLicenciable();

    $this->postJson('/api/v1/funcionarios/7633685/licencias', solicitudValida(), ['X-API-KEY' => CLAVE_SOLICITUD])
        ->assertCreated();

    expect(Licencia::firstOrFail()->adjunto)->toBeNull();
});

test('el respaldo se limita a imagen o PDF de hasta 5 MB', function () {
    funcionarioLicenciable();

    $this->post(
        '/api/v1/funcionarios/7633685/licencias',
        solicitudValida(['respaldo' => UploadedFile::fake()->create('planilla.xlsx', 40)]),
        ['X-API-KEY' => CLAVE_SOLICITUD, 'Accept' => 'application/json'],
    )->assertJsonValidationErrors('respaldo');

    $this->post(
        '/api/v1/funcionarios/7633685/licencias',
        solicitudValida(['respaldo' => UploadedFile::fake()->create('enorme.pdf', 6000, 'application/pdf')]),
        ['X-API-KEY' => CLAVE_SOLICITUD, 'Accept' => 'application/json'],
    )->assertJsonValidationErrors('respaldo');

    expect(Licencia::count())->toBe(0);
});

test('una licencia parcial en multipart sigue exigiendo las horas', function () {
    funcionarioLicenciable();

    // En `multipart/form-data` no hay booleanos: `tCompleto` llega como el texto
    // «0». Sin normalizarlo, `required_if:tCompleto,false` no dispara y la
    // licencia parcial pasaría sin horas de entrada ni de salida.
    $this->post(
        '/api/v1/funcionarios/7633685/licencias',
        solicitudValida([
            'tCompleto' => '0',
            'respaldo' => UploadedFile::fake()->create('certificado.pdf', 40, 'application/pdf'),
        ]),
        ['X-API-KEY' => CLAVE_SOLICITUD, 'Accept' => 'application/json'],
    )->assertJsonValidationErrors(['lEntra', 'lSale']);

    expect(Licencia::count())->toBe(0);
});

test('sin turnos asignados no hay nada que licenciar', function () {
    Persona::factory()->create(['ci' => '7633685']);

    $this->postJson('/api/v1/funcionarios/7633685/licencias', solicitudValida(), ['X-API-KEY' => CLAVE_SOLICITUD])
        ->assertStatus(422);

    expect(Licencia::count())->toBe(0);
});

test('sin la clave compartida no se registra ninguna solicitud', function () {
    funcionarioLicenciable();

    $this->postJson('/api/v1/funcionarios/7633685/licencias', solicitudValida())
        ->assertUnauthorized();

    expect(Licencia::count())->toBe(0);
});

/**
 * Solicitud «Pendiente» de varios días, como la deja `RegistroLicencia`.
 *
 * @return Collection<int, Licencia>
 */
function solicitudDelFuncionario(string $ci = '7633685', int $dias = 2): Collection
{
    funcionarioLicenciable($ci);
    $turno = Turno::query()->first();
    $solicitud = (string) Str::ulid();

    return collect(range(0, $dias - 1))->map(fn (int $i) => Licencia::factory()->create([
        'ci' => $ci,
        'turno_id' => $turno->id,
        'fecha' => Carbon::parse('2026-08-03')->addWeeks($i)->toDateString(),
        'solicitud' => $solicitud,
        'estado' => Licencia::PENDIENTE,
    ]));
}

test('el funcionario da de baja su solicitud pendiente, con eliminación lógica', function () {
    $licencias = solicitudDelFuncionario(dias: 2);

    $this->deleteJson('/api/v1/funcionarios/7633685/licencias/'.$licencias->first()->id, [
        'observacion' => 'Me equivoqué de fecha.',
    ], ['X-API-KEY' => CLAVE_SOLICITUD])->assertOk();

    // Los dos días del pedido, no solo el que se pidió.
    expect(Licencia::count())->toBe(0)
        ->and(Licencia::withTrashed()->count())->toBe(2);

    $baja = Licencia::withTrashed()->first();

    expect($baja->deleted_at)->not->toBeNull()
        // Queda anotado el motivo que escribió, junto con el origen de la baja:
        // quién la dio no se puede guardar, el funcionario no tiene usuario acá.
        ->and($baja->deleteObservacion)->toBe('Cancelada por el funcionario desde Mamoré: Me equivoqué de fecha.');
});

test('no se puede dar de baja la licencia de otro funcionario', function () {
    $ajena = solicitudDelFuncionario('6522875', dias: 1)->first();
    funcionarioLicenciable('7633685');

    // El id es un número corrido: sin la comprobación, subirlo de a uno
    // borraría las licencias de todo el personal.
    $this->deleteJson('/api/v1/funcionarios/7633685/licencias/'.$ajena->id, [], [
        'X-API-KEY' => CLAVE_SOLICITUD,
    ])->assertNotFound();

    expect(Licencia::whereKey($ajena->id)->exists())->toBeTrue();
});

test('una licencia ya resuelta no la puede dar de baja el funcionario', function () {
    $licencias = solicitudDelFuncionario(dias: 2);
    Licencia::query()->update(['estado' => Licencia::APROBADO]);

    // Ya justifica una ausencia y entró en reportes firmados: la baja es
    // decisión de Recursos Humanos.
    $this->deleteJson('/api/v1/funcionarios/7633685/licencias/'.$licencias->first()->id, [], [
        'X-API-KEY' => CLAVE_SOLICITUD,
    ])->assertStatus(422);

    expect(Licencia::count())->toBe(2);
});

test('sin la clave compartida no se da de baja ninguna licencia', function () {
    $licencias = solicitudDelFuncionario(dias: 1);

    $this->deleteJson('/api/v1/funcionarios/7633685/licencias/'.$licencias->first()->id)
        ->assertUnauthorized();

    expect(Licencia::count())->toBe(1);
});

test('el motivo de la baja es opcional', function () {
    $licencias = solicitudDelFuncionario(dias: 1);

    $this->deleteJson('/api/v1/funcionarios/7633685/licencias/'.$licencias->first()->id, [], [
        'X-API-KEY' => CLAVE_SOLICITUD,
    ])->assertOk();

    // Sin motivo igual queda constancia de dónde salió la baja.
    expect(Licencia::withTrashed()->first()->deleteObservacion)
        ->toBe('Cancelada por el funcionario desde Mamoré.');
});

test('el motivo de la baja tiene tope de largo', function () {
    $licencias = solicitudDelFuncionario(dias: 1);

    $this->deleteJson('/api/v1/funcionarios/7633685/licencias/'.$licencias->first()->id, [
        'observacion' => str_repeat('x', 256),
    ], ['X-API-KEY' => CLAVE_SOLICITUD])->assertJsonValidationErrors('observacion');

    expect(Licencia::count())->toBe(1);
});

test('un pedido de varios días se agrupa igual venga de Mamoré o de SisMark', function () {
    funcionarioLicenciable('7633685');
    // Segundo funcionario con el mismo turno, para el alta de Recursos Humanos.
    Persona::factory()->create(['ci' => '6522875']);
    $turno = Turno::query()->first();
    AsignacionTurno::factory()->create([
        'ci' => '6522875',
        'turno_id' => $turno->id,
        'idTurno' => $turno->idTurno,
        'desde' => '2020-01-01 00:00:00',
        'hasta' => '2030-12-31 00:00:00',
    ]);

    // (1) Desde Mamoré: la API, tres lunes.
    $this->postJson('/api/v1/funcionarios/7633685/licencias', [
        'desde' => '2026-08-03',
        'hasta' => '2026-08-17',
        'motivo' => 'CONSULTA MEDICA',
        'tCompleto' => true,
        'solicitante' => 'Ignacio Molina',
    ], ['X-API-KEY' => CLAVE_SOLICITUD])->assertCreated();

    // (2) Desde SisMark: el mismo rango, por el servicio que usa la pantalla.
    app(RegistroLicencia::class)->anotar(
        app(RegistroLicencia::class)->turnosDelRango(
            ['6522875'],
            Carbon::parse('2026-08-03'),
            Carbon::parse('2026-08-17'),
        ),
        Carbon::parse('2026-08-03'),
        Carbon::parse('2026-08-17'),
        [
            'tCompleto' => true,
            'goceHaberes' => true,
            'motivo' => 'COMISION',
            'lEntra' => null,
            'lSale' => null,
            'usuario' => 'Recursos Humanos',
            'usuarioId' => null,
        ],
    );

    // Los dos generan tres filas y **una sola** solicitud cada uno.
    foreach (['7633685' => 'CONSULTA MEDICA', '6522875' => 'COMISION'] as $ci => $motivo) {
        $filas = Licencia::where('ci', $ci)->get();

        expect($filas)->toHaveCount(3)
            ->and($filas->pluck('solicitud')->unique())->toHaveCount(1)
            ->and($filas->first()->motivo)->toBe($motivo);
    }

    // Y no se mezclan entre sí.
    expect(Licencia::distinct()->count('solicitud'))->toBe(2);

    // El listado los muestra como dos licencias, no como seis.
    expect(Licencia::query()->iniciosDeSolicitud()->count())->toBe(2);

    // La única diferencia intencional es el estado: lo de Mamoré espera
    // revisión, lo que carga Recursos Humanos surte efecto en el acto.
    expect(Licencia::where('ci', '7633685')->pluck('estado')->unique()->all())->toBe([Licencia::PENDIENTE])
        ->and(Licencia::where('ci', '6522875')->pluck('estado')->unique()->all())->toBe([Licencia::APROBADO]);
});

test('el funcionario declara si pide con o sin goce de haberes', function () {
    funcionarioLicenciable();

    $this->postJson('/api/v1/funcionarios/7633685/licencias', solicitudValida(['goceHaberes' => false]), [
        'X-API-KEY' => CLAVE_SOLICITUD,
    ])->assertCreated();

    // Lo declara, pero no lo decide: la licencia sigue naciendo «Pendiente».
    expect(Licencia::firstOrFail()->goceHaberes)->toBeFalse()
        ->and(Licencia::firstOrFail()->estado)->toBe(Licencia::PENDIENTE);
});

test('sin el campo, la solicitud se anota con goce', function () {
    funcionarioLicenciable();

    // Un consumidor que no mande el campo tiene que seguir funcionando: «con
    // goce» es lo que corresponde a un pedido que todavía nadie resolvió.
    $datos = solicitudValida();
    unset($datos['goceHaberes']);

    $this->postJson('/api/v1/funcionarios/7633685/licencias', $datos, ['X-API-KEY' => CLAVE_SOLICITUD])
        ->assertCreated();

    expect(Licencia::firstOrFail()->goceHaberes)->toBeTrue();
});

test('el goce sobrevive el multipart cuando se adjunta respaldo', function () {
    funcionarioLicenciable();

    // En multipart no hay booleanos: sin normalizar, «0» se leería como
    // presente-y-verdadero y la licencia saldría con goce sin haberlo pedido.
    $this->post(
        '/api/v1/funcionarios/7633685/licencias',
        solicitudValida([
            'goceHaberes' => '0',
            'respaldo' => UploadedFile::fake()->create('certificado.pdf', 40, 'application/pdf'),
        ]),
        ['X-API-KEY' => CLAVE_SOLICITUD, 'Accept' => 'application/json'],
    )->assertCreated();

    expect(Licencia::firstOrFail()->goceHaberes)->toBeFalse();
});

/**
 * Pide una licencia por la API, como lo hace Mamoré.
 */
function pedirLicencia(array $extra = []): TestResponse
{
    return test()->postJson(
        '/api/v1/funcionarios/7633685/licencias',
        solicitudValida($extra),
        ['X-API-KEY' => CLAVE_SOLICITUD],
    );
}

test('una licencia rechazada se puede volver a pedir, y la anterior queda', function () {
    funcionarioLicenciable();

    // Dos lunes pedidos y rechazados, con su motivo.
    pedirLicencia(['hasta' => '2026-08-10'])->assertCreated();
    Licencia::query()->update([
        'estado' => Licencia::RECHAZADO,
        'observacion' => 'Falta el certificado.',
        'revisadoEn' => now(),
    ]);

    expect(Licencia::count())->toBe(2);

    // El funcionario corrige y vuelve a pedir los mismos días.
    pedirLicencia(['hasta' => '2026-08-10', 'motivo' => 'CON CERTIFICADO'])->assertCreated();

    // Cuatro filas: el pedido nuevo **no pisa** al rechazado.
    expect(Licencia::count())->toBe(4)
        ->and(Licencia::distinct()->count('solicitud'))->toBe(2);

    $rechazadas = Licencia::where('estado', Licencia::RECHAZADO)->get();

    // El historial queda intacto, con su motivo y su fecha de revisión.
    expect($rechazadas)->toHaveCount(2)
        ->and($rechazadas->pluck('observacion')->unique()->all())->toBe(['Falta el certificado.'])
        ->and($rechazadas->pluck('revisadoEn')->filter())->toHaveCount(2);

    $nuevas = Licencia::where('estado', Licencia::PENDIENTE)->get();

    // Y el pedido nuevo arranca limpio.
    expect($nuevas)->toHaveCount(2)
        ->and($nuevas->pluck('motivo')->unique()->all())->toBe(['CON CERTIFICADO'])
        ->and($nuevas->pluck('observacion')->unique()->all())->toBe([null])
        ->and($nuevas->pluck('revisadoEn')->unique()->all())->toBe([null]);
});

test('una licencia dada de baja tampoco se pisa: queda como historial', function () {
    funcionarioLicenciable();

    pedirLicencia()->assertCreated();
    $baja = Licencia::firstOrFail();

    // El funcionario retira su pedido desde el perfil.
    $this->deleteJson('/api/v1/funcionarios/7633685/licencias/'.$baja->id, [
        'observacion' => 'Me equivoqué de fecha.',
    ], ['X-API-KEY' => CLAVE_SOLICITUD])->assertOk();

    // Y vuelve a pedir el mismo día.
    pedirLicencia(['motivo' => 'ESTA VEZ SÍ'])->assertCreated();

    // La dada de baja sigue ahí, con su motivo; la nueva es otra fila.
    expect(Licencia::count())->toBe(1)
        ->and(Licencia::withTrashed()->count())->toBe(2)
        ->and(Licencia::firstOrFail()->motivo)->toBe('ESTA VEZ SÍ')
        ->and(Licencia::onlyTrashed()->firstOrFail()->deleteObservacion)
        ->toContain('Me equivoqué de fecha.');
});

test('una licencia aprobada no se puede volver a pedir', function () {
    funcionarioLicenciable();

    pedirLicencia()->assertCreated();
    Licencia::query()->update(['estado' => Licencia::APROBADO]);

    // El día ya está justificado: pedirlo otra vez no tiene sentido.
    pedirLicencia()->assertStatus(422);

    expect(Licencia::count())->toBe(1)
        ->and(Licencia::firstOrFail()->estado)->toBe(Licencia::APROBADO);
});

test('una licencia pendiente no se puede volver a pedir', function () {
    funcionarioLicenciable();

    pedirLicencia()->assertCreated();

    // Ya hay un pedido esperando decisión: no se duplica.
    pedirLicencia()->assertStatus(422);

    expect(Licencia::count())->toBe(1);
});
