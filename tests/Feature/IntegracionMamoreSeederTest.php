<?php

use App\Models\SistemaExterno;
use App\Models\Turno;
use Database\Seeders\IntegracionMamoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * El horario general de la Gobernación tal como lo trae el SIA: una fila por
 * día hábil, todas 08:00 a 16:00.
 */
function horarioGeneralDelSia(): void
{
    foreach (['2', '3', '4', '5', '6'] as $dia) {
        Turno::factory()->create([
            'dia' => $dia,
            'hEntrada' => '1899-12-30 08:00:00',
            'hSalida' => '1899-12-30 16:00:00',
            'sugerido' => false,
        ]);
    }
}

test('deja el sistema mamoré activo con su token', function () {
    $this->seed(IntegracionMamoreSeeder::class);

    $sistema = SistemaExterno::query()->where('slug', 'mamore')->firstOrFail();

    expect($sistema->activo)->toBeTrue()
        ->and($sistema->tokens()->count())->toBe(1);
});

test('el token sembrado entra a la API', function () {
    $this->seed(IntegracionMamoreSeeder::class);

    // La prueba de que el hash sembrado corresponde al texto plano que el
    // seeder imprime: si no coincidieran, esto daría 401 y el `.env` de
    // desarrollo quedaría con una credencial muerta.
    $plano = '1|sismark-local-solo-desarrollo-no-usar-en-produccion';

    $this->withHeaders(['Authorization' => 'Bearer '.$plano])
        ->getJson('/api/v1/turnos/sugeridos')
        ->assertOk();
});

test('el token sembrado trae todos los alcances', function () {
    $this->seed(IntegracionMamoreSeeder::class);

    $abilities = SistemaExterno::query()->where('slug', 'mamore')->firstOrFail()
        ->tokens()->firstOrFail()->abilities;

    expect($abilities)->toBe(array_keys(SistemaExterno::ALCANCES));
});

test('marca el horario general como sugerido', function () {
    horarioGeneralDelSia();

    $this->seed(IntegracionMamoreSeeder::class);

    // Cinco y no uno: `turnos` guarda un día por fila.
    expect(Turno::query()->sugeridos()->count())->toBe(5);
});

test('no pisa el horario que ya eligió Recursos Humanos', function () {
    horarioGeneralDelSia();
    $elegido = Turno::factory()->create(['dia' => '7', 'sugerido' => true]);

    $this->seed(IntegracionMamoreSeeder::class);

    // Una decisión tomada desde la pantalla vale más que el default del seeder.
    expect(Turno::query()->sugeridos()->pluck('id')->all())->toBe([$elegido->id]);
});

test('correrlo dos veces no duplica el sistema ni sus tokens', function () {
    $this->seed(IntegracionMamoreSeeder::class);
    $this->seed(IntegracionMamoreSeeder::class);

    expect(SistemaExterno::withTrashed()->where('slug', 'mamore')->count())->toBe(1)
        ->and(DB::table('personal_access_tokens')->count())->toBe(1);
});

test('en producción no siembra ninguna credencial', function () {
    app()->detectEnvironment(fn (): string => 'production');

    // Se corre el seeder derecho y no con `$this->seed()`: ese pasa por
    // `db:seed`, que en producción se planta a preguntar si de verdad se quiere
    // correr y ahí la prueba se cuelga esperando una respuesta.
    app(IntegracionMamoreSeeder::class)->run();

    // Una credencial conocida y escrita en el repositorio es exactamente lo que
    // no puede existir en producción.
    expect(DB::table('personal_access_tokens')->count())->toBe(0)
        ->and(SistemaExterno::withTrashed()->count())->toBe(0);
});
