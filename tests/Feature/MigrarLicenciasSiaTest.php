<?php

use App\Models\Licencia;
use App\Models\Sia\DiaTurno;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // SIA falso (sqlite en memoria) con Licencias; la base local trae la tabla
    // `licencias` de la migración propia.
    fakeSiaDatabase();

    // La licencia referencia el horario por la FK `turno_id`, así que los turnos
    // tienen que existir antes: es el orden real de la migración.
    DiaTurno::factory()->create(['IdTurno' => '8DW']);
    DiaTurno::factory()->create(['IdTurno' => 'EKX']);
    $this->artisan('sia:migrar-horarios')->assertSuccessful();
});

/**
 * Inserta una licencia en el SIA falso con valores por defecto sobrescribibles.
 *
 * @param  array<string, mixed>  $extra
 */
function insertarLicenciaSia(array $extra = []): void
{
    DB::connection('sia')->table('Licencias')->insert([
        'FechaPedido' => '2026-07-08 09:45:56',
        'Usuario' => 'mvillavicencio',
        'Fecha' => '2026-06-22 00:00:00',
        'IdPersona' => '10790063',
        'IdTurno' => '8DW',
        'LEntra' => null,
        'LSale' => null,
        'TCompleto' => true,
        'Motivo' => 'COMISION',
        'GoceHaberes' => true,
        ...$extra,
    ]);
}

test('copia las licencias del SIA a la base local con id y timestamps', function () {
    insertarLicenciaSia();
    insertarLicenciaSia(['IdPersona' => '4191164', 'IdTurno' => 'EKX']);

    $this->artisan('sia:migrar-licencias')->assertSuccessful();

    expect(DB::table('licencias')->count())->toBe(2);

    $fila = DB::table('licencias')->first();
    expect($fila->id)->toBeGreaterThan(0)
        ->and($fila->created_at)->not->toBeNull();
});

test('mapea IdPersona→ci y preserva los campos, recortando el relleno', function () {
    insertarLicenciaSia([
        'IdPersona' => '10790063    ', // char(12) con relleno; en local va a ci.
        'IdTurno' => '8DW',
        'Motivo' => 'INGRESO AL BIOMETRICO',
        'LEntra' => '1899-12-30 08:00:00',
    ]);

    $this->artisan('sia:migrar-licencias')->assertSuccessful();

    $local = DB::table('licencias')->where('ci', '10790063')->first();

    expect($local)->not->toBeNull()
        ->and($local->usuario)->toBe('mvillavicencio')
        ->and($local->idTurno)->toBe('8DW')
        ->and($local->motivo)->toBe('INGRESO AL BIOMETRICO')
        // El SIA la manda como «1899-12-30 08:00:00»; acá queda solo la hora.
        ->and($local->lEntra)->toBe('08:00:00');
});

test('resuelve turno_id cruzando idTurno con turnos local y conserva el código como histórico', function () {
    $turnoId = DB::table('turnos')->where('idTurno', '8DW')->value('id');

    insertarLicenciaSia(['IdTurno' => '8DW']);

    $this->artisan('sia:migrar-licencias')->assertSuccessful();

    $local = DB::table('licencias')->first();

    expect($local->turno_id)->toBe($turnoId)
        // El código del SIA se copia solo como dato histórico de lo migrado.
        ->and($local->idTurno)->toBe('8DW');
});

test('saltea la licencia si su IdTurno no cruza con ningún turno', function () {
    insertarLicenciaSia(['IdTurno' => 'ZZZ']);
    insertarLicenciaSia(['IdPersona' => '4191164', 'IdTurno' => 'EKX']);

    $this->artisan('sia:migrar-licencias')->assertSuccessful();

    expect(DB::table('licencias')->count())->toBe(1)
        ->and(DB::table('licencias')->value('ci'))->toBe('4191164');
});

test('falla si los turnos no se migraron antes', function () {
    DB::table('turnos')->delete();
    insertarLicenciaSia();

    $this->artisan('sia:migrar-licencias')->assertFailed();

    expect(DB::table('licencias')->count())->toBe(0);
});

test('es idempotente: correrlo dos veces no duplica', function () {
    insertarLicenciaSia();
    insertarLicenciaSia(['IdPersona' => '4191164', 'IdTurno' => 'EKX']);

    $this->artisan('sia:migrar-licencias')->assertSuccessful();
    $this->artisan('sia:migrar-licencias')->assertSuccessful();

    expect(DB::table('licencias')->count())->toBe(2);
});

test('no escribe sobre la base del SIA (origen intacto)', function () {
    insertarLicenciaSia();

    $this->artisan('sia:migrar-licencias')->assertSuccessful();

    expect(DB::connection('sia')->table('Licencias')->count())->toBe(1);
});

test('los días de un mismo pedido del SIA quedan sin solicitud', function () {
    // Mismo funcionario, mismo momento del pedido y mismo motivo: en el SIA eso
    // es UNA licencia partida en un día por fila.
    foreach (['2026-06-22', '2026-06-23', '2026-06-24'] as $dia) {
        insertarLicenciaSia(['Fecha' => $dia.' 00:00:00']);
    }

    // Otro pedido del mismo funcionario, en otro momento.
    insertarLicenciaSia(['Fecha' => '2026-07-06 00:00:00', 'FechaPedido' => '2026-07-01 08:00:00']);
    // Y otro con el mismo momento pero distinto motivo.
    insertarLicenciaSia(['Fecha' => '2026-07-07 00:00:00', 'Motivo' => 'VACACION']);

    $this->artisan('sia:migrar-licencias')->assertSuccessful();

    // Lo del SIA no fue una solicitud: las cinco filas quedan sin ella.
    expect(Licencia::count())->toBe(5)
        ->and(Licencia::whereNotNull('solicitud')->count())->toBe(0);
});

test('reejecutar la copia no le inventa una solicitud a lo ya migrado', function () {
    insertarLicenciaSia();
    insertarLicenciaSia(['Fecha' => '2026-06-23 00:00:00']);

    $this->artisan('sia:migrar-licencias')->assertSuccessful();
    $antes = Licencia::orderBy('fecha')->pluck('solicitud');

    // El identificador se deriva de la clave y no se sortea: la segunda corrida
    // llega al mismo valor, así que los agrupamientos no se mueven.
    $this->artisan('sia:migrar-licencias')->assertSuccessful();

    expect(Licencia::count())->toBe(2)
        ->and(Licencia::orderBy('fecha')->pluck('solicitud')->all())->toBe($antes->all());
});

test('licencias de funcionarios distintos quedan como filas separadas', function () {
    // Un feriado se anota para todos con el mismo momento y motivo: cada
    // funcionario tiene que quedar con su propia licencia.
    insertarLicenciaSia(['IdPersona' => '10790063']);
    insertarLicenciaSia(['IdPersona' => '4191164', 'IdTurno' => 'EKX']);

    $this->artisan('sia:migrar-licencias')->assertSuccessful();

    expect(Licencia::count())->toBe(2)
        ->and(Licencia::whereNotNull('solicitud')->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// La columna `solicitud` solo la llenan los pedidos de verdad
// ---------------------------------------------------------------------------

test('lo migrado del SIA deja la columna solicitud vacía', function () {
    // Lo que viene del sistema viejo no fue una solicitud: es una licencia ya
    // otorgada. Esa columna la llenan solo SisMark y Mamoré.
    insertarLicenciaSia();
    insertarLicenciaSia(['Fecha' => '2026-06-23 00:00:00']);

    $this->artisan('sia:migrar-licencias')->assertSuccessful();

    expect(Licencia::count())->toBe(2)
        ->and(Licencia::whereNotNull('solicitud')->count())->toBe(0)
        ->and(Licencia::pluck('origen')->unique()->all())->toBe(['sia']);
});

test('sigue sin duplicar aunque la solicitud quede vacía', function () {
    // El upsert deduplica por (ci, fecha, turno_id), la clave natural del
    // sistema viejo. `solicitud` quedó fuera justamente porque va en null.
    insertarLicenciaSia();
    insertarLicenciaSia(['Fecha' => '2026-06-23 00:00:00']);

    $this->artisan('sia:migrar-licencias')->assertSuccessful();
    $this->artisan('sia:migrar-licencias')->assertSuccessful();
    $this->artisan('sia:migrar-licencias')->assertSuccessful();

    expect(Licencia::count())->toBe(2)
        ->and(Licencia::whereNotNull('solicitud')->count())->toBe(0);
});
