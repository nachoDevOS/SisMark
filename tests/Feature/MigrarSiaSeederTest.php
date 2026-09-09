<?php

use App\Models\Sia\DiaTurno;
use App\Models\Sia\Persona;
use App\Models\User;
use App\Policies\RolePolicy;
use Database\Seeders\MigrarSiaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    fakeSiaDatabase();
});

test('el seeder corre toda la migración del SIA en orden y resuelve turno_id', function () {
    // Datos de origen en el SIA falso.
    Persona::factory()->create(['IdPersona' => '111']);
    DiaTurno::factory()->create(['IdTurno' => '8DW']);
    DB::connection('sia')->table('AsignacionTurnos')->insert([
        'IdPersona' => '111',
        'IdTurno' => '8DW',
        'Desde' => '2026-07-21 00:00:00',
        'Hasta' => '2026-11-30 00:00:00',
    ]);

    $this->seed(MigrarSiaSeeder::class);

    // Todas las tablas locales quedaron pobladas.
    expect(DB::table('personas')->count())->toBe(1)
        ->and(DB::table('turnos')->count())->toBe(1)
        ->and(DB::table('asignacion_turnos')->count())->toBe(1);

    // La FK turno_id se resolvió porque el seeder migra horarios antes.
    $turnoId = DB::table('turnos')->where('idTurno', '8DW')->value('id');
    expect(DB::table('asignacion_turnos')->value('turno_id'))->toBe($turnoId);
});

test('copiar los horarios del SIA no marca ninguno como sugerido', function () {
    DiaTurno::factory()->count(3)->create();

    $this->artisan('sia:migrar-horarios')->assertSuccessful();

    // Cuál es el horario sugerido lo decide Recursos Humanos desde la pantalla
    // de Horarios. Copiar los datos del SIA no puede elegirlo por ellos: un
    // horario marcado solo es el que alguien marcó a propósito.
    //
    // Se prueba sobre el comando y no sobre `MigrarSiaSeeder` entero porque el
    // seeder termina llamando a `IntegracionMamoreSeeder`, que fuera de
    // producción sí deja marcado el horario general para no tener que
    // rehacerlo después de cada `migrate:fresh`. El comando de copia, nunca.
    expect(DB::table('turnos')->where('sugerido', true)->count())->toBe(0);
});

test('recopiar los horarios no borra lo marcado como sugerido', function () {
    DiaTurno::factory()->create(['IdTurno' => '8DW']);

    $this->seed(MigrarSiaSeeder::class);

    // Un turno marcado a mano desde la pantalla de Horarios: `sia:migrar-horarios`
    // no lo lleva en su MAPA, así que volver a copiar no puede pisarlo.
    DB::table('turnos')->where('idTurno', '8DW')->update([
        'sugerido' => true,
        'nombreTurno' => 'PISAME',
    ]);
    $this->artisan('sia:migrar-horarios')->assertSuccessful();

    $turno = DB::table('turnos')->where('idTurno', '8DW')->first();

    expect($turno->sugerido)->toEqual(1)
        // El resto de las columnas sí se recopia: la de arriba se pisó.
        ->and($turno->nombreTurno)->not->toBe('PISAME');
});

test('el seeder es idempotente: correrlo dos veces no duplica', function () {
    Persona::factory()->count(2)->create();

    $this->seed(MigrarSiaSeeder::class);
    $this->seed(MigrarSiaSeeder::class);

    expect(DB::table('personas')->count())->toBe(2);
});

test('el seeder deja permisos y un administrador que puede entrar', function () {
    config(['auth.seed_admin.password' => 'clave-de-prueba']);

    $this->seed(MigrarSiaSeeder::class);

    // Migrar los datos del SIA sin dejar con qué entrar a verlos no sirve de
    // nada: el seeder corre DatabaseSeeder antes de copiar.
    $usuario = User::where('email', config('auth.seed_admin.email'))->first();

    expect(Permission::count())->toBe(count(RolePolicy::nombresDePermiso()))
        ->and($usuario)->not->toBeNull()
        ->and($usuario->hasRole('super_admin'))->toBeTrue()
        ->and(Auth::attempt([
            'email' => config('auth.seed_admin.email'),
            'password' => 'clave-de-prueba',
        ]))->toBeTrue();
});
