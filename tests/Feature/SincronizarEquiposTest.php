<?php

use App\Models\Asistencia;
use App\Models\Equipo;
use App\Models\EquipoAuditoria;
use App\Services\SincronizadorEquipos;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.device_service.url', 'http://microservicio.test');
    config()->set('services.device_service.token', 'token-de-prueba');

    // El funcionario tiene que existir en local: el registro cruza el ID del
    // reloj contra `personas.ci`.
    DB::table('personas')->insert([
        'ci' => '7633685', 'paterno' => 'Molina', 'materno' => null, 'nombres' => 'Ignacio',
        'pinReloj' => null, 'marcaDirecta' => false,
    ]);
});

/**
 * Respuesta del microservicio con una marcación del funcionario local.
 */
function relojResponde(string $momento = '2026-07-09T08:05:00'): void
{
    Http::fake([
        'microservicio.test/device/attendance*' => Http::response([
            'marcaciones' => [
                ['uid' => 1, 'user_id' => '7633685', 'nombre' => 'Ignacio Molina', 'timestamp' => $momento],
            ],
        ], 200),
    ]);
}

test('sincroniza el equipo cuando el minuto coincide con una de sus horas', function () {
    relojResponde();
    $this->travelTo('2026-07-09 08:30:00');

    $equipo = Equipo::factory()->create([
        'activo' => true,
        'sync_automatica' => true,
        'sync_horarios' => ['08:30', '13:00'],
    ]);

    $this->artisan('sismark:sincronizar-equipos')->assertSuccessful();

    expect(Asistencia::query()->where('ci', '7633685')->count())->toBe(1);
    expect($equipo->fresh()->sync_ultimo_automatico)->not->toBeNull();
});

test('no hace nada fuera de las horas configuradas', function () {
    relojResponde();
    $this->travelTo('2026-07-09 09:47:00');

    Equipo::factory()->create([
        'activo' => true,
        'sync_automatica' => true,
        'sync_horarios' => ['08:30', '13:00'],
    ]);

    $this->artisan('sismark:sincronizar-equipos')->assertSuccessful();

    Http::assertNothingSent();
    expect(Asistencia::query()->count())->toBe(0);
});

test('saltea el equipo con la sincronización automática apagada', function () {
    relojResponde();
    $this->travelTo('2026-07-09 08:30:00');

    Equipo::factory()->create([
        'activo' => true,
        'sync_automatica' => false,
        'sync_horarios' => ['08:30'],
    ]);

    $this->artisan('sismark:sincronizar-equipos')->assertSuccessful();

    Http::assertNothingSent();
});

test('saltea el equipo inactivo aunque tenga horarios', function () {
    relojResponde();
    $this->travelTo('2026-07-09 08:30:00');

    Equipo::factory()->create([
        'activo' => false,
        'sync_automatica' => true,
        'sync_horarios' => ['08:30'],
    ]);

    $this->artisan('sismark:sincronizar-equipos')->assertSuccessful();

    Http::assertNothingSent();
});

test('no repite la corrida si ya se sincronizó en ese mismo minuto', function () {
    relojResponde();
    $this->travelTo('2026-07-09 08:30:00');

    Equipo::factory()->create([
        'activo' => true,
        'sync_automatica' => true,
        'sync_horarios' => ['08:30'],
        'sync_ultimo_automatico' => '2026-07-09 08:30:10',
    ]);

    $this->artisan('sismark:sincronizar-equipos')->assertSuccessful();

    Http::assertNothingSent();
});

test('con --forzar corre aunque no sea la hora', function () {
    relojResponde();
    $this->travelTo('2026-07-09 09:47:00');

    Equipo::factory()->create([
        'activo' => true,
        'sync_automatica' => true,
        'sync_horarios' => ['08:30'],
    ]);

    $this->artisan('sismark:sincronizar-equipos', ['--forzar' => true])->assertSuccessful();

    expect(Asistencia::query()->count())->toBe(1);
});

test('con --equipo corre ese equipo sin mirar horarios ni el interruptor', function () {
    relojResponde();
    $this->travelTo('2026-07-09 09:47:00');

    $equipo = Equipo::factory()->create(['sync_automatica' => false, 'sync_horarios' => null]);

    $this->artisan('sismark:sincronizar-equipos', ['--equipo' => $equipo->id])->assertSuccessful();

    expect(Asistencia::query()->count())->toBe(1);
});

test('la corrida automática queda en la bitácora sin usuario', function () {
    relojResponde();
    $this->travelTo('2026-07-09 08:30:00');

    Equipo::factory()->create([
        'activo' => true,
        'sync_automatica' => true,
        'sync_horarios' => ['08:30'],
    ]);

    $this->artisan('sismark:sincronizar-equipos')->assertSuccessful();

    $registro = EquipoAuditoria::query()->latest('id')->first();

    expect($registro)->not->toBeNull()
        ->and($registro->accion)->toBe(EquipoAuditoria::ACCION_SINCRONIZAR)
        // Sin sesión, la bitácora la firma «Sistema».
        ->and($registro->nombreUsuario())->toBe('Sistema');
});

test('un equipo caído no tumba la corrida pero termina en fallo', function () {
    Http::fake([
        'microservicio.test/device/attendance*' => Http::response(['detail' => 'No se pudo conectar con el equipo'], 503),
    ]);
    $this->travelTo('2026-07-09 08:30:00');

    $equipo = Equipo::factory()->create([
        'activo' => true,
        'sync_automatica' => true,
        'sync_horarios' => ['08:30'],
    ]);

    $this->artisan('sismark:sincronizar-equipos')->assertFailed();

    // La marca de tiempo se escribe igual: es cuándo corrió la tarea, no si el
    // reloj contestó. Sin esto, el próximo intento volvería a pedir el rango
    // completo.
    expect($equipo->fresh()->sync_ultimo_automatico)->not->toBeNull();

    $registro = EquipoAuditoria::query()->latest('id')->first();
    expect($registro->exito)->toBeFalse();
});

test('el rango arranca en la última corrida que trajo datos', function () {
    relojResponde();
    $this->travelTo('2026-07-09 08:30:00');

    Equipo::factory()->create([
        'activo' => true,
        'sync_automatica' => true,
        'sync_horarios' => ['08:30'],
        'sync_ultimo_exito' => '2026-07-07 19:00:00',
    ]);

    $this->artisan('sismark:sincronizar-equipos')->assertSuccessful();

    // Se le pide al microservicio desde el día del último dato traído hasta hoy,
    // así lo que no entró aquella vez se recupera solo.
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'desde=2026-07-07')
        && str_contains($request->url(), 'hasta=2026-07-09'));
});

test('el planificador corre la tarea cada minuto', function () {
    $tarea = collect(app(Schedule::class)->events())
        ->first(fn ($evento): bool => str_contains($evento->command ?? '', 'sismark:sincronizar-equipos'));

    expect($tarea)->not->toBeNull()
        ->and($tarea->expression)->toBe('* * * * *');
});

/*
|--------------------------------------------------------------------------
| Días de la semana
|--------------------------------------------------------------------------
|
| Los números son los de `Turno::DIAS` (1 = Domingo … 7 = Sábado). En las
| fechas de estos tests: 2026-07-09 es jueves (5) y 2026-07-11 es sábado (7).
*/

test('sincroniza cuando el día de la semana está entre los elegidos', function () {
    relojResponde();
    $this->travelTo('2026-07-09 08:30:00'); // jueves

    Equipo::factory()->create([
        'activo' => true,
        'sync_automatica' => true,
        'sync_horarios' => ['08:30'],
        'sync_dias' => [2, 3, 4, 5, 6], // lunes a viernes
    ]);

    $this->artisan('sismark:sincronizar-equipos')->assertSuccessful();

    expect(Asistencia::query()->count())->toBe(1);
});

test('no habla con el reloj en un día que no está elegido', function () {
    relojResponde();
    $this->travelTo('2026-07-11 08:30:00'); // sábado

    Equipo::factory()->create([
        'activo' => true,
        'sync_automatica' => true,
        'sync_horarios' => ['08:30'],
        'sync_dias' => [2, 3, 4, 5, 6], // lunes a viernes
    ]);

    $this->artisan('sismark:sincronizar-equipos')->assertSuccessful();

    Http::assertNothingSent();
    expect(Asistencia::query()->count())->toBe(0);
});

test('sin días elegidos el equipo se sincroniza todos los días', function () {
    // La marca tiene que caer dentro del rango que se le pide al reloj, que
    // arranca en el día de la corrida.
    relojResponde('2026-07-11T08:05:00');
    $this->travelTo('2026-07-11 08:30:00'); // sábado

    Equipo::factory()->create([
        'activo' => true,
        'sync_automatica' => true,
        'sync_horarios' => ['08:30'],
        'sync_dias' => null,
    ]);

    $this->artisan('sismark:sincronizar-equipos')->assertSuccessful();

    expect(Asistencia::query()->count())->toBe(1);
});

test('los días guardados fuera de rango se descartan en vez de romper la corrida', function () {
    relojResponde();
    $this->travelTo('2026-07-09 08:30:00'); // jueves

    $equipo = Equipo::factory()->create([
        'activo' => true,
        'sync_automatica' => true,
        'sync_horarios' => ['08:30'],
        // 0 y 9 no son días; queda solo el 5 (jueves), así que igual le toca.
        'sync_dias' => [0, 5, 9],
    ]);

    expect($equipo->diasSync())->toBe([5]);

    $this->artisan('sismark:sincronizar-equipos')->assertSuccessful();

    expect(Asistencia::query()->count())->toBe(1);
});

test('la sincronización automática no vacía el historial del reloj', function () {
    relojResponde();
    $this->travelTo('2026-07-09 08:30:00');

    Equipo::factory()->create([
        'activo' => true,
        'sync_automatica' => true,
        'sync_horarios' => ['08:30'],
        'sync_dias' => [5],
    ]);

    $this->artisan('sismark:sincronizar-equipos')->assertSuccessful();

    // Copiar las marcaciones es solo leer: el endpoint que las borra del equipo
    // no se toca nunca desde la tarea programada.
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/device/attendance/clear'));
    expect(Asistencia::query()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Recuperación de un equipo caído
|--------------------------------------------------------------------------
|
| `sync_ultimo_automatico` es «cuándo corrió la tarea» y se escribe ande o no el
| reloj. `sync_ultimo_exito` es «hasta cuándo se trajo información» y solo se
| escribe cuando el equipo contestó. De esta última sale el «desde», que es lo
| que hace que una caída de varios días se recupere entera.
*/

test('el fallo no mueve la marca del último dato traído', function () {
    Http::fake([
        'microservicio.test/device/attendance*' => Http::response(['detail' => 'No se pudo conectar con el equipo'], 503),
    ]);
    $this->travelTo('2026-07-09 08:30:00');

    $equipo = Equipo::factory()->create([
        'activo' => true,
        'sync_automatica' => true,
        'sync_horarios' => ['08:30'],
        'sync_ultimo_exito' => '2026-07-05 19:00:00',
    ]);

    $this->artisan('sismark:sincronizar-equipos')->assertFailed();

    $equipo->refresh();

    // Corrió (y quedó anotado), pero no trajo nada: la marca de datos no se toca.
    expect($equipo->sync_ultimo_automatico?->toDateString())->toBe('2026-07-09')
        ->and($equipo->sync_ultimo_exito?->toDateString())->toBe('2026-07-05');
});

test('un equipo caído varios días recupera todo el hueco al volver', function () {
    // Un solo stub para todo el test, con una bandera: `Http::fake()` acumula
    // definiciones en vez de reemplazarlas, así que llamarlo de nuevo más abajo
    // dejaría ganando al 503 de acá arriba.
    $relojVolvio = false;

    Http::fake([
        'microservicio.test/device/attendance*' => function () use (&$relojVolvio) {
            if (! $relojVolvio) {
                return Http::response(['detail' => 'No se pudo conectar con el equipo'], 503);
            }

            return Http::response([
                'marcaciones' => [
                    ['uid' => 1, 'user_id' => '7633685', 'nombre' => 'Ignacio Molina', 'timestamp' => '2026-07-06T08:05:00'],
                    ['uid' => 2, 'user_id' => '7633685', 'nombre' => 'Ignacio Molina', 'timestamp' => '2026-07-08T08:05:00'],
                    ['uid' => 3, 'user_id' => '7633685', 'nombre' => 'Ignacio Molina', 'timestamp' => '2026-07-10T08:05:00'],
                ],
            ], 200);
        },
    ]);

    $equipo = Equipo::factory()->create([
        'activo' => true,
        'sync_automatica' => true,
        'sync_horarios' => ['08:30'],
        'sync_ultimo_exito' => '2026-07-05 08:30:00',
    ]);

    // Cuatro días seguidos sin que el reloj conteste.
    foreach (['2026-07-06', '2026-07-07', '2026-07-08', '2026-07-09'] as $dia) {
        $this->travelTo($dia.' 08:30:00');
        $this->artisan('sismark:sincronizar-equipos')->assertFailed();
    }

    // Al quinto día el equipo vuelve.
    $relojVolvio = true;
    $this->travelTo('2026-07-10 08:30:00');

    $this->artisan('sismark:sincronizar-equipos')->assertSuccessful();

    // Se pide desde el último día con datos —el 5— y no desde ayer: si saliera
    // de la marca de corrida, del 6 al 8 no los bajaría nadie nunca.
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'desde=2026-07-05')
        && str_contains($request->url(), 'hasta=2026-07-10'));

    // Y las tres marcaciones del hueco entran.
    expect(Asistencia::query()->where('ci', '7633685')->count())->toBe(3)
        ->and($equipo->fresh()->sync_ultimo_exito?->toDateString())->toBe('2026-07-10');
});

test('un equipo que nunca trajo datos arranca por hoy', function () {
    relojResponde();
    $this->travelTo('2026-07-09 08:30:00');

    Equipo::factory()->create([
        'activo' => true,
        'sync_automatica' => true,
        'sync_horarios' => ['08:30'],
        'sync_ultimo_exito' => null,
    ]);

    $this->artisan('sismark:sincronizar-equipos')->assertSuccessful();

    // El historial viejo se baja a mano con --desde, no de sorpresa en la
    // primera corrida automática.
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'desde=2026-07-09'));
});

test('una caída larguísima se acota a los últimos 30 días', function () {
    relojResponde('2026-07-09T08:05:00');
    $this->travelTo('2026-07-09 08:30:00');

    Equipo::factory()->create([
        'activo' => true,
        'sync_automatica' => true,
        'sync_horarios' => ['08:30'],
        // Seis meses sin dar señales.
        'sync_ultimo_exito' => '2026-01-09 08:30:00',
    ]);

    $this->artisan('sismark:sincronizar-equipos')->assertSuccessful();

    // Pedirle medio año de historial al reloj lo deja inservible varios minutos:
    // se corta en 30 días y el resto se baja a mano.
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'desde=2026-06-09'));
});

/*
|--------------------------------------------------------------------------
| Trazabilidad: de qué reloj salió cada marcación y qué pasó con ella
|--------------------------------------------------------------------------
*/

test('la marcación guarda de qué equipo salió', function () {
    relojResponde();
    $this->travelTo('2026-07-09 08:30:00');

    $equipo = Equipo::factory()->create([
        'activo' => true,
        'sync_automatica' => true,
        'sync_horarios' => ['08:30'],
    ]);

    $this->artisan('sismark:sincronizar-equipos')->assertSuccessful();

    $marcacion = Asistencia::query()->where('ci', '7633685')->first();

    expect($marcacion->equipo_id)->toBe($equipo->id)
        ->and($marcacion->equipo->nombre)->toBe($equipo->nombre);
});

test('la bitácora desglosa qué pasó con cada marcación del reloj', function () {
    $this->travelTo('2026-07-09 08:30:00');

    // El reloj entrega cuatro: una nueva, una que ya está, una de alguien que no
    // figura en el padrón y una con la fecha basura que arrastra el RTC.
    Asistencia::query()->create([
        'ci' => '7633685',
        'fecha' => '2026-07-09 00:00:00',
        'hora' => '1899-12-30 07:00:00',
        'tipo' => Asistencia::TIPO_RELOJ,
    ]);

    Http::fake([
        'microservicio.test/device/attendance*' => Http::response([
            'marcaciones' => [
                ['uid' => 1, 'user_id' => '7633685', 'timestamp' => '2026-07-09T08:05:00'], // nueva
                ['uid' => 2, 'user_id' => '7633685', 'timestamp' => '2026-07-09T07:00:00'], // repetida
                ['uid' => 3, 'user_id' => '9999999', 'timestamp' => '2026-07-09T08:06:00'], // sin funcionario
                ['uid' => 4, 'user_id' => '7633685', 'timestamp' => '2064-01-01T08:07:00'], // fecha basura
            ],
        ], 200),
    ]);

    $equipo = Equipo::factory()->create([
        'activo' => true,
        'sync_automatica' => true,
        'sync_horarios' => ['08:30'],
    ]);

    $this->artisan('sismark:sincronizar-equipos')->assertSuccessful();

    $registro = EquipoAuditoria::query()
        ->where('equipo_id', $equipo->id)
        ->where('accion', EquipoAuditoria::ACCION_SINCRONIZAR)
        ->latest('id')
        ->first();

    expect($registro->total_marcaciones)->toBe(4)
        ->and($registro->nuevas)->toBe(1)
        ->and($registro->repetidas)->toBe(1)
        ->and($registro->sin_funcionario)->toBe(1)
        // La de 2064 no llega a `RegistroAsistencia`: el filtro por rango la
        // saca antes, y por eso cuenta como fuera de rango y no como fallida.
        ->and($registro->fallidas)->toBe(0)
        ->and($registro->fuera_de_rango)->toBe(1);

    // El desglose tiene que cerrar contra el total que entregó el reloj: si no
    // suma, alguna marcación se perdió sin quedar contada en ningún lado.
    expect($registro->nuevas + $registro->repetidas + $registro->sin_funcionario
        + $registro->fallidas + $registro->fuera_de_rango)
        ->toBe($registro->total_marcaciones);
});

test('la fecha basura del reloj se cuenta como fallida cuando no hay rango', function () {
    $this->travelTo('2026-07-09 08:30:00');

    Http::fake([
        'microservicio.test/device/attendance*' => Http::response([
            'marcaciones' => [
                ['uid' => 1, 'user_id' => '7633685', 'timestamp' => '2026-07-09T08:05:00'],
                ['uid' => 2, 'user_id' => '7633685', 'timestamp' => '2064-01-01T08:07:00'],
            ],
        ], 200),
    ]);

    $equipo = Equipo::factory()->create();

    // Sin `--desde`/`--hasta` explícitos el comando igual arma un rango, así que
    // se va por el servicio para probar la lectura sin recortar.
    $resultado = app(SincronizadorEquipos::class)->sincronizar($equipo);

    expect($resultado['exito'])->toBeTrue();

    $registro = EquipoAuditoria::query()->latest('id')->first();

    // Sin filtro por rango la basura del RTC llega a `RegistroAsistencia`, que la
    // descarta por fecha futura: ahí sí es una fallida.
    expect($registro->total_marcaciones)->toBe(2)
        ->and($registro->nuevas)->toBe(1)
        ->and($registro->fallidas)->toBe(1)
        ->and($registro->fuera_de_rango)->toBe(0);
});

test('el equipo que solo trae repetidas queda evidenciado en la bitácora', function () {
    $this->travelTo('2026-07-09 08:30:00');
    relojResponde();

    $equipo = Equipo::factory()->create([
        'activo' => true,
        'sync_automatica' => true,
        'sync_horarios' => ['08:30'],
    ]);

    // Primera corrida: la marcación entra.
    $this->artisan('sismark:sincronizar-equipos')->assertSuccessful();

    // Segunda corrida del mismo día, forzada: el reloj devuelve lo mismo.
    $this->artisan('sismark:sincronizar-equipos', ['--forzar' => true])->assertSuccessful();

    $registros = EquipoAuditoria::query()
        ->where('accion', EquipoAuditoria::ACCION_SINCRONIZAR)
        ->orderBy('id')
        ->get();

    expect($registros->first()->nuevas)->toBe(1)
        ->and($registros->first()->repetidas)->toBe(0)
        // La segunda no aportó nada, y la bitácora lo dice sin que haya que
        // interpretar el texto.
        ->and($registros->last()->nuevas)->toBe(0)
        ->and($registros->last()->repetidas)->toBe(1);
});

test('la baja de un equipo no se lleva sus marcaciones', function () {
    relojResponde();
    $this->travelTo('2026-07-09 08:30:00');

    $equipo = Equipo::factory()->create([
        'activo' => true,
        'sync_automatica' => true,
        'sync_horarios' => ['08:30'],
    ]);

    $this->artisan('sismark:sincronizar-equipos')->assertSuccessful();

    // Baja física, que es el caso que la FK tiene que sobrevivir: la marcación
    // es del funcionario, no del aparato.
    $equipo->forceDelete();

    $marcacion = Asistencia::query()->where('ci', '7633685')->first();

    expect($marcacion)->not->toBeNull()
        ->and($marcacion->equipo_id)->toBeNull();
});

test('la marcación cargada a mano no queda atada a ningún equipo', function () {
    $marcacion = Asistencia::query()->create([
        'ci' => '7633685',
        'fecha' => '2026-07-09 00:00:00',
        'hora' => '1899-12-30 08:05:00',
        'tipo' => Asistencia::TIPO_MANUAL,
    ]);

    expect($marcacion->equipo_id)->toBeNull();
});
