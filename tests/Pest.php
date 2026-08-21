<?php

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    // Aísla la conexión 'sia' del SQL Server real en TODOS los tests: por
    // defecto apunta a un sqlite vacío, así ningún test pega a la red del SIA
    // (p. ej. al renderizar el dashboard). Los tests que necesitan datos del
    // SIA llaman fakeSiaDatabase(), que además crea las tablas.
    ->beforeEach(function (): void {
        config()->set('database.connections.sia', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        DB::purge('sia');

        // Mamoré (API externa) desactivada por defecto: ningún test pega a la red
        // salvo los que la configuran y falsean la respuesta con Http::fake().
        config()->set('services.mamore', ['url' => null, 'key' => null]);

        // Caché limpia por test (evita arrastrar nombres de Mamoré cacheados).
        Cache::flush();
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Crea un usuario con el rol super_admin (acceso total al sistema).
 */
function asSuperAdmin(): User
{
    $role = Role::firstOrCreate([
        'name' => 'super_admin',
        'guard_name' => 'web',
    ]);

    return User::factory()->create()->assignRole($role);
}

/**
 * Configura la API de Mamoré y falsea sus dos endpoints (`/people` y
 * `/people/ci/{ci}`) con el padrón dado, para probar sin red las pantallas que
 * leen los datos personales de ahí.
 *
 * Cada persona puede darse como `ci => 'NOMBRE COMPLETO'` (sin contrato) o como
 * `ci => ['nombre' => ..., 'cargo' => ..., 'direccion' => ...]` para que salga
 * con contrato firmado, igual que lo entrega la API real. Con `image` se le
 * agrega la foto, de donde sale la miniatura que pintan las tablas.
 *
 * @param  array<string, string|array<string, mixed>>  $padron
 */
function fakeMamore(array $padron = []): void
{
    config()->set('services.mamore.url', 'http://mamore.test/api/personal');
    config()->set('services.mamore.key', 'secreta');

    $filas = collect($padron)
        ->map(function (string|array $datos, string $ci): array {
            $datos = is_array($datos) ? $datos : ['nombre' => $datos];
            $cargo = $datos['cargo'] ?? null;

            // Haber y vigencia del contrato firmado.
            $sueldo = array_key_exists('sueldo', $datos) ? $datos['sueldo'] : null;
            $bono = $datos['bono'] ?? null;
            $desde = $datos['start'] ?? '2026-01-01';
            $hasta = array_key_exists('finish', $datos) ? $datos['finish'] : '2026-12-31';

            // Todos los contratos firmados. Por defecto es el único de arriba;
            // `contratos` permite armar el caso de dos contratos en el mismo
            // mes con sueldos distintos, que es el que obliga a cobrar cada día
            // de sanción al contrato que regía ese día.
            $contratos = $datos['contratos'] ?? ($cargo === null ? [] : [[
                'salary' => $sueldo,
                'bonus' => $bono,
                'start' => $desde,
                'finish' => $hasta,
            ]]);

            return [
                'id' => crc32($ci),
                'ci' => (string) $ci,
                // Extensión del carnet (el departamento que lo emitió) y la
                // cédula completa que Mamoré arma con las dos.
                'extension' => $datos['extension'] ?? null,
                'full_ci' => isset($datos['extension'])
                    ? $ci.' '.$datos['extension']
                    : (string) $ci,
                'full_name' => $datos['nombre'],
                // La foto de la persona; la miniatura la deriva DirectorioMamore.
                'image' => $datos['image'] ?? null,
                'has_contract' => $cargo !== null,
                'contrato' => $cargo === null ? null : [
                    'code' => $datos['code'] ?? 'COD-1/2026',
                    'denominacion' => $datos['denominacion'] ?? $cargo,
                    'cargo' => $cargo,
                    'cargo_completo' => $cargo,
                    'direccion_administrativa' => ['nombre' => $datos['direccion'] ?? 'Dirección', 'sigla' => $datos['direccion'] ?? null],
                    'unidad_administrativa' => null,
                    // Haber y vigencia: los usa el régimen disciplinario para
                    // pasar los días de descuento a bolivianos. `sueldo` acepta
                    // `null` explícito, que es el contrato sin haber cargado.
                    'salary' => $sueldo,
                    'bonus' => $bono,
                    'start' => $desde,
                    'finish' => $hasta,
                ],
                'contratos' => $contratos,
            ];
        })
        ->values();

    Http::fake([
        // Los contratos van primero: su ruta cuelga de la del detalle, así que
        // el patrón de abajo también la alcanzaría y devolvería la ficha entera.
        //
        // De estos contratos depende qué días procesa el sistema, así que el
        // fake tiene que responder la misma forma que la API real: una lista
        // bajo `data`, con `start` y `finish`.
        'mamore.test/api/personal/people/ci/*/contracts*' => function (ClientRequest $peticion) use ($filas) {
            $partes = explode('/', trim((string) parse_url($peticion->url(), PHP_URL_PATH), '/'));
            $ci = urldecode($partes[count($partes) - 2] ?? '');
            $fila = $filas->firstWhere('ci', $ci);

            return Http::response(['data' => $fila['contratos'] ?? []]);
        },
        // El patrón del detalle va primero: el del listado también lo alcanzaría.
        'mamore.test/api/personal/people/ci/*' => function (ClientRequest $peticion) use ($filas) {
            $ci = urldecode(basename((string) parse_url($peticion->url(), PHP_URL_PATH)));
            $fila = $filas->firstWhere('ci', $ci);

            return $fila
                ? Http::response(['data' => $fila])
                : Http::response(['message' => 'not found'], 404);
        },
        'mamore.test/api/personal/people*' => function (ClientRequest $peticion) use ($filas) {
            parse_str((string) parse_url($peticion->url(), PHP_URL_QUERY), $parametros);
            $buscado = mb_strtolower(trim((string) ($parametros['search'] ?? '')));

            $encontrados = $buscado === ''
                ? $filas
                : $filas->filter(fn (array $fila): bool => str_contains(
                    mb_strtolower($fila['full_name'].' '.$fila['ci']),
                    $buscado
                ))->values();

            // La API filtra por situación de contrato del lado del servidor.
            $contrato = (string) ($parametros['contrato'] ?? 'todos');

            if ($contrato === 'con') {
                $encontrados = $encontrados->filter(fn (array $fila): bool => $fila['has_contract'])->values();
            } elseif ($contrato === 'sin') {
                $encontrados = $encontrados->filter(fn (array $fila): bool => ! $fila['has_contract'])->values();
            }

            return Http::response([
                'data' => $encontrados->all(),
                'meta' => [
                    'total' => $encontrados->count(),
                    'per_page' => (int) ($parametros['limit'] ?? 25),
                    'current_page' => (int) ($parametros['page'] ?? 1),
                    'contrato' => $contrato,
                    'total_con_contrato' => $filas->where('has_contract', true)->count(),
                    'total_sin_contrato' => $filas->where('has_contract', false)->count(),
                ],
            ]);
        },
    ]);
}

/**
 * Reemplaza la conexión 'sia' (SQL Server 2008 remoto) por un sqlite en
 * memoria con las tablas del módulo de asistencia, para probar sin red.
 */
function fakeSiaDatabase(): void
{
    config()->set('database.connections.sia', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]);

    DB::purge('sia');
    Cache::flush();

    Schema::connection('sia')->create('Personas', function (Blueprint $tabla): void {
        $tabla->string('IdPersona', 12)->primary();
        $tabla->string('OrigenId', 3)->nullable();
        $tabla->string('Paterno', 25);
        $tabla->string('Materno', 25)->nullable();
        $tabla->string('Nombres', 35);
        $tabla->dateTime('FechaNacimiento')->nullable();
        $tabla->string('LugarNacimiento', 25)->nullable();
        $tabla->string('Sexo', 1)->nullable();
        $tabla->string('EstadoCivil', 1)->nullable();
        $tabla->string('CodigoProfesion', 2)->nullable();
        $tabla->string('NivelEstudio', 20)->nullable();
        $tabla->string('Telefono', 20)->nullable();
        $tabla->string('Direccion', 40)->nullable();
        $tabla->string('CorreoE', 40)->nullable();
        // Sin default: igual que en el SQL Server real, el INSERT debe
        // mandar siempre MarcaDirecta o falla por NOT NULL.
        $tabla->boolean('MarcaDirecta');
        $tabla->string('PinReloj', 10)->nullable();
    });

    Schema::connection('sia')->create('Profesiones', function (Blueprint $tabla): void {
        $tabla->string('CodigoProfesion', 2)->primary();
        $tabla->string('NombreProfesion', 60);
    });

    Schema::connection('sia')->create('Asistencia', function (Blueprint $tabla): void {
        $tabla->string('IdPersona', 12);
        $tabla->dateTime('Fecha');
        $tabla->dateTime('Hora');
        $tabla->string('Tipo', 1);
    });

    Schema::connection('sia')->create('DiaTurnos', function (Blueprint $tabla): void {
        $tabla->string('IdTurno', 3)->primary();
        $tabla->string('Dia', 1);
        $tabla->string('NombreTurno', 25);
        $tabla->dateTime('HEntrada');
        $tabla->dateTime('HSalida');
        $tabla->dateTime('HTolerancia');
        $tabla->dateTime('EMinima');
        $tabla->dateTime('EMaxima');
        $tabla->dateTime('SMinima');
        $tabla->dateTime('SMaxima');
        $tabla->dateTime('STolerancia');
        $tabla->decimal('HTrabajadas', 19, 4);
        $tabla->boolean('SiguienteDia');
    });

    Schema::connection('sia')->create('Licencias', function (Blueprint $tabla): void {
        $tabla->dateTime('FechaPedido');
        $tabla->string('Usuario', 50);
        $tabla->dateTime('Fecha');
        $tabla->string('IdPersona', 12);
        $tabla->string('IdTurno', 3);
        $tabla->dateTime('LEntra')->nullable();
        $tabla->dateTime('LSale')->nullable();
        $tabla->boolean('TCompleto');
        $tabla->string('Motivo', 255)->nullable();
        $tabla->boolean('GoceHaberes');
    });

    Schema::connection('sia')->create('AsignacionTurnos', function (Blueprint $tabla): void {
        $tabla->string('IdPersona', 12);
        $tabla->string('IdTurno', 3);
        $tabla->dateTime('Desde');
        $tabla->dateTime('Hasta');
    });

    Schema::connection('sia')->create('Calendario', function (Blueprint $tabla): void {
        $tabla->dateTime('Fecha');
        $tabla->string('MotivoInasistencia', 255)->nullable();
    });
}
