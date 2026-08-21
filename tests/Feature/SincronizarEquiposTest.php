<?php

use App\Models\Asistencia;
use App\Models\Equipo;
use App\Models\EquipoAuditoria;
use App\Services\SincronizadorEquipos;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

test('no se le pide rango al reloj: se baja el buffer completo', function () {
    relojResponde();
    $this->travelTo('2026-07-09 08:30:00');

    Equipo::factory()->create([
        'activo' => true,
        'sync_automatica' => true,
        'sync_horarios' => ['08:30'],
        'sync_ultimo_exito' => '2026-07-07 19:00:00',
    ]);

    $this->artisan('sismark:sincronizar-equipos')->assertSuccessful();

    // El protocolo ZK vuelca todo el historial en cada lectura, así que pedir un
    // rango no le ahorraba trabajo al reloj: solo descartaba después. Sin rango
    // nada queda afuera y la corrida es idempotente.
    Http::assertSent(fn ($request): bool => ! str_contains($request->url(), 'desde=')
        && ! str_contains($request->url(), 'hasta='));
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

    // Las tres marcaciones del hueco entran de una, sin cálculo de rango: el
    // reloj entrega su historial completo y se guarda lo que falte.
    expect(Asistencia::query()->where('ci', '7633685')->count())->toBe(3)
        ->and($equipo->fresh()->sync_ultimo_exito?->toDateString())->toBe('2026-07-10');
});

test('un equipo que nunca sincronizó baja todo su historial en la primera corrida', function () {
    $this->travelTo('2026-07-09 08:30:00');

    Http::fake([
        'microservicio.test/device/attendance*' => Http::response([
            'marcaciones' => [
                ['uid' => 1, 'user_id' => '7633685', 'timestamp' => '2026-05-02T08:05:00'],
                ['uid' => 2, 'user_id' => '7633685', 'timestamp' => '2026-06-15T08:05:00'],
                ['uid' => 3, 'user_id' => '7633685', 'timestamp' => '2026-07-09T08:05:00'],
            ],
        ], 200),
    ]);

    Equipo::factory()->create([
        'activo' => true,
        'sync_automatica' => true,
        'sync_horarios' => ['08:30'],
        'sync_ultimo_exito' => null,
    ]);

    $this->artisan('sismark:sincronizar-equipos')->assertSuccessful();

    // Antes arrancaba por hoy y el historial viejo había que bajarlo a mano.
    // Ahora entra solo: leerlo cuesta lo mismo que leer un día.
    expect(Asistencia::query()->where('ci', '7633685')->count())->toBe(3);
});

test('una caída larguísima se recupera entera, sin tope de días', function () {
    $this->travelTo('2026-07-09 08:30:00');

    Http::fake([
        'microservicio.test/device/attendance*' => Http::response([
            'marcaciones' => [
                // Seis meses atrás: antes quedaba afuera por el tope de 30 días
                // y no la bajaba nadie nunca.
                ['uid' => 1, 'user_id' => '7633685', 'timestamp' => '2026-01-15T08:05:00'],
                ['uid' => 2, 'user_id' => '7633685', 'timestamp' => '2026-07-09T08:05:00'],
            ],
        ], 200),
    ]);

    Equipo::factory()->create([
        'activo' => true,
        'sync_automatica' => true,
        'sync_horarios' => ['08:30'],
        // Seis meses sin dar señales.
        'sync_ultimo_exito' => '2026-01-09 08:30:00',
    ]);

    $this->artisan('sismark:sincronizar-equipos')->assertSuccessful();

    expect(Asistencia::query()->where('ci', '7633685')->count())->toBe(2);
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
        'fecha' => '2026-07-09',
        'hora' => '07:00:00',
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
        // La de 2064 ahora sí llega a `RegistroAsistencia`: sin rango no hay
        // filtro que la saque antes, y se cuenta como fallida, que es lo que es.
        ->and($registro->fallidas)->toBe(1)
        // Sin rango pedido nunca se recorta nada.
        ->and($registro->fuera_de_rango)->toBe(0);

    // El desglose tiene que cerrar contra el total que entregó el reloj: si no
    // suma, alguna marcación se perdió sin quedar contada en ningún lado.
    expect($registro->nuevas + $registro->repetidas + $registro->sin_funcionario
        + $registro->fallidas + $registro->fuera_de_rango)
        ->toBe($registro->total_marcaciones);

    // La del ID desconocido se guardó: contarla no significa descartarla.
    expect(Asistencia::query()->where('ci', '9999999')->count())->toBe(1);
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
        'fecha' => '2026-07-09',
        'hora' => '08:05:00',
        'tipo' => Asistencia::TIPO_MANUAL,
    ]);

    expect($marcacion->equipo_id)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Integridad de la transferencia: ¿llegó todo lo que el reloj tenía?
|--------------------------------------------------------------------------
|
| El reloj informa su propio contador de registros junto con las marcaciones.
| Compararlo contra lo que llegó es lo único que delata una lectura cortada por
| el medio: contando solo lo que llegó, 1499 de 1500 se ve idéntico a 1500 de
| 1500.
*/

/**
 * Respuesta del microservicio con las dos cifras de la transferencia.
 *
 * @param  int  $enEquipo  cuántas dice el reloj que tiene guardadas
 * @param  int  $llegaron  cuántas marcaciones devuelve de verdad
 */
function relojEntrega(int $enEquipo, int $llegaron): void
{
    $marcaciones = [];

    // Van de a un segundo y sobre un día ya pasado, para que ni la tanda más
    // grande cruce la medianoche: una marcación con fecha futura la descarta
    // `RegistroAsistencia` como basura del RTC y falsearía el conteo.
    for ($i = 0; $i < $llegaron; $i++) {
        $marcaciones[] = [
            'uid' => $i + 1,
            'user_id' => '7633685',
            'timestamp' => Carbon::parse('2026-07-08 00:00:00')->addSeconds($i)->toIso8601String(),
        ];
    }

    Http::fake([
        'microservicio.test/device/attendance*' => Http::response([
            'en_equipo' => $enEquipo,
            'leidas' => $llegaron,
            'total' => $llegaron,
            'marcaciones' => $marcaciones,
        ], 200),
    ]);
}

/**
 * Equipo al que le toca sincronizar en el minuto al que viaja cada prueba.
 *
 * @param  array<string, mixed>  $atributos
 */
function equipoDeGuardia(array $atributos = []): Equipo
{
    return Equipo::factory()->create([
        'activo' => true,
        'sync_automatica' => true,
        'sync_horarios' => ['08:30'],
        ...$atributos,
    ]);
}

test('la bitácora guarda cuántas tenía el reloj y cuántas llegaron', function () {
    $this->travelTo('2026-07-09 08:30:00');
    relojEntrega(enEquipo: 5, llegaron: 5);

    $equipo = equipoDeGuardia();

    $this->artisan('sismark:sincronizar-equipos')->assertSuccessful();

    $registro = EquipoAuditoria::query()->where('equipo_id', $equipo->id)->latest('id')->first();

    // Son dos cuentas encadenadas y distintas: la primera mide el transporte
    // (reloj → SisMark), la segunda el destino (SisMark → base).
    expect($registro->en_equipo)->toBe(5)
        ->and($registro->total_marcaciones)->toBe(5)
        ->and($registro->marcacionesPerdidas())->toBe(0)
        ->and($registro->transferenciaCompleta())->toBeTrue()
        ->and($registro->nuevas)->toBe(5);
});

test('una lectura incompleta se marca fallida aunque lo que llegó se haya guardado', function () {
    $this->travelTo('2026-07-09 08:30:00');
    // El reloj declara 1500 y entrega 1499: la lectura se cortó por el medio.
    relojEntrega(enEquipo: 1500, llegaron: 1499);

    $equipo = equipoDeGuardia();

    $this->artisan('sismark:sincronizar-equipos')->assertFailed();

    $registro = EquipoAuditoria::query()->where('equipo_id', $equipo->id)->latest('id')->first();

    expect($registro->en_equipo)->toBe(1500)
        ->and($registro->total_marcaciones)->toBe(1499)
        ->and($registro->marcacionesPerdidas())->toBe(1)
        ->and($registro->transferenciaCompleta())->toBeFalse()
        ->and($registro->exito)->toBeFalse()
        ->and($registro->detalle)->toContain('faltan 1');

    // Lo que llegó se guardó igual: no se tira nada por una lectura corta.
    expect(Asistencia::query()->count())->toBe(1499);
});

test('la lectura incompleta no mueve la marca de última corrida con datos', function () {
    $this->travelTo('2026-07-09 08:30:00');
    relojEntrega(enEquipo: 10, llegaron: 9);

    $equipo = equipoDeGuardia(['sync_ultimo_exito' => '2026-07-05 08:30:00']);

    $this->artisan('sismark:sincronizar-equipos')->assertFailed();

    $equipo->refresh();

    // La corrida queda anotada, pero la marca de «trajo todo» no se mueve: así
    // la ficha delata al reloj que responde y entrega a medias.
    expect($equipo->sync_ultimo_automatico?->toDateString())->toBe('2026-07-09')
        ->and($equipo->sync_ultimo_exito?->toDateString())->toBe('2026-07-05');
});

test('la lectura completa sí mueve la marca de última corrida con datos', function () {
    $this->travelTo('2026-07-09 08:30:00');
    relojEntrega(enEquipo: 3, llegaron: 3);

    $equipo = equipoDeGuardia(['sync_ultimo_exito' => '2026-07-05 08:30:00']);

    $this->artisan('sismark:sincronizar-equipos')->assertSuccessful();

    expect($equipo->fresh()->sync_ultimo_exito?->toDateString())->toBe('2026-07-09');
});

test('sin contador del reloj no se acusa ninguna pérdida', function () {
    $this->travelTo('2026-07-09 08:30:00');

    // Microservicio viejo o firmware que no expone el contador: no manda
    // `en_equipo`. No poder comprobarlo no es lo mismo que haber perdido algo.
    relojResponde();

    $equipo = equipoDeGuardia();

    $this->artisan('sismark:sincronizar-equipos')->assertSuccessful();

    $registro = EquipoAuditoria::query()->where('equipo_id', $equipo->id)->latest('id')->first();

    expect($registro->en_equipo)->toBeNull()
        ->and($registro->transferenciaCompleta())->toBeNull()
        ->and($registro->marcacionesPerdidas())->toBeNull()
        ->and($registro->exito)->toBeTrue();
});

test('un contador menor que lo entregado no cuenta como pérdida', function () {
    $this->travelTo('2026-07-09 08:30:00');
    // Contador roto —pasa con firmware viejo tras un corte de luz—: declara
    // menos de lo que entrega. No es motivo para dudar de lo que sí llegó.
    relojEntrega(enEquipo: 2, llegaron: 5);

    $equipo = equipoDeGuardia();

    $this->artisan('sismark:sincronizar-equipos')->assertSuccessful();

    $registro = EquipoAuditoria::query()->where('equipo_id', $equipo->id)->latest('id')->first();

    expect($registro->marcacionesPerdidas())->toBe(0)
        ->and($registro->transferenciaCompleta())->toBeTrue()
        ->and($registro->exito)->toBeTrue();
});

test('la marcación de un ID que no está en el padrón se guarda con su equipo', function () {
    $this->travelTo('2026-07-09 08:30:00');

    Http::fake([
        'microservicio.test/device/attendance*' => Http::response([
            'en_equipo' => 1,
            'leidas' => 1,
            'marcaciones' => [
                ['uid' => 1, 'user_id' => '9182736', 'timestamp' => '2026-07-09T08:31:02'],
            ],
        ], 200),
    ]);

    $equipo = equipoDeGuardia();

    $this->artisan('sismark:sincronizar-equipos')->assertSuccessful();

    $marcacion = Asistencia::query()->where('ci', '9182736')->first();

    // Se guarda con el reloj del que salió, que es lo que permite auditar la
    // corrida más adelante y rastrear de dónde vino ese ID desconocido.
    expect($marcacion)->not->toBeNull()
        ->and($marcacion->equipo_id)->toBe($equipo->id)
        ->and($marcacion->tipo)->toBe(Asistencia::TIPO_RELOJ);

    $registro = EquipoAuditoria::query()->where('equipo_id', $equipo->id)->latest('id')->first();

    expect($registro->sin_funcionario)->toBe(1)
        ->and($registro->nuevas)->toBe(0);
});

test('repetir la sincronización no duplica nada', function () {
    $this->travelTo('2026-07-09 08:30:00');
    relojEntrega(enEquipo: 20, llegaron: 20);

    equipoDeGuardia();

    $this->artisan('sismark:sincronizar-equipos')->assertSuccessful();

    // Segunda corrida forzada: el reloj vuelve a entregar todo su historial y no
    // entra ninguna de nuevo. La corrida es idempotente.
    $this->artisan('sismark:sincronizar-equipos', ['--forzar' => true])->assertSuccessful();

    expect(Asistencia::query()->count())->toBe(20);

    $registro = EquipoAuditoria::query()->latest('id')->first();

    expect($registro->nuevas)->toBe(0)
        ->and($registro->repetidas)->toBe(20);
});

test('una tanda más grande que el tamaño de lote se resuelve entera', function () {
    $this->travelTo('2026-07-09 08:30:00');
    // Más de las 500 filas que resuelve cada vuelta: el corte por lotes no puede
    // perder ni duplicar nada en el borde.
    relojEntrega(enEquipo: 1200, llegaron: 1200);

    equipoDeGuardia();

    $this->artisan('sismark:sincronizar-equipos')->assertSuccessful();

    expect(Asistencia::query()->count())->toBe(1200);

    $registro = EquipoAuditoria::query()->latest('id')->first();

    expect($registro->nuevas)->toBe(1200)
        ->and($registro->repetidas)->toBe(0);
});

test('el reloj que entrega dos veces la misma marcación no la duplica', function () {
    $this->travelTo('2026-07-09 08:30:00');

    Http::fake([
        'microservicio.test/device/attendance*' => Http::response([
            'en_equipo' => 3,
            'leidas' => 3,
            'marcaciones' => [
                ['uid' => 1, 'user_id' => '7633685', 'timestamp' => '2026-07-09T08:05:00'],
                ['uid' => 2, 'user_id' => '7633685', 'timestamp' => '2026-07-09T08:05:00'], // idéntica
                ['uid' => 3, 'user_id' => '7633685', 'timestamp' => '2026-07-09T08:05:30'], // rebote, otra hora
            ],
        ], 200),
    ]);

    equipoDeGuardia();

    $this->artisan('sismark:sincronizar-equipos')->assertSuccessful();

    // La idéntica se descarta dentro de la misma tanda; el rebote de 30 segundos
    // es otra hora y se guarda: colapsarlo es tarea del reporte, no del alta.
    expect(Asistencia::query()->count())->toBe(2);

    $registro = EquipoAuditoria::query()->latest('id')->first();

    expect($registro->nuevas)->toBe(2)
        ->and($registro->repetidas)->toBe(1);
});
