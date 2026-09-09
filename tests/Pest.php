<?php

use App\Models\SistemaExterno;
use App\Models\User;
use App\Policies\RolePolicy;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
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
        config()->set('services.mamore', ['url' => null, 'token' => null, 'origen' => null]);

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
 * Usuario con exactamente los permisos pedidos y ninguno más, para comprobar
 * dónde corta cada módulo. No usa `asSuperAdmin()`: ese rol pasa por el
 * `Gate::before` de AppServiceProvider y puede todo sin permisos asignados.
 *
 * @param  list<string>  $permisos
 */
function usuarioCon(array $permisos): User
{
    foreach (RolePolicy::nombresDePermiso() as $nombre) {
        Permission::firstOrCreate(['name' => $nombre, 'guard_name' => 'web']);
    }

    // Nombre único: cada prueba arma más de un usuario para comparar el antes y
    // el después, y el nombre del rol es clave única.
    static $numero = 0;

    $rol = Role::create(['name' => 'acotado_'.(++$numero), 'guard_name' => 'web']);
    $rol->givePermissionTo($permisos);

    return User::factory()->create()->assignRole($rol);
}

/**
 * Cabeceras con las que un sistema externo consume la API de asistencia.
 *
 * Emite un token de Sanctum sobre un `SistemaExterno` de prueba. Sin
 * `$alcances` se le dan todos, que es lo que quiere la mayoría de los tests: los
 * que prueban justamente el corte por alcance piden los suyos.
 *
 * Emite uno nuevo en cada llamada, sin cachear. Cachearlo en un `static` es la
 * trampa obvia y no funciona: `RefreshDatabase` vacía la tabla entre tests, así
 * que un token guardado de un test anterior deja de existir y el siguiente
 * pedido se rechaza con 401 por un motivo que no tiene nada que ver con lo que
 * se estaba probando.
 *
 * @param  list<string>  $alcances
 * @param  array<string, string>  $extra
 * @return array<string, string>
 */
function cabecerasApi(array $alcances = [], array $extra = []): array
{
    $token = SistemaExterno::query()
        ->firstOrCreate(['slug' => 'pruebas'], ['nombre' => 'Sistema de pruebas'])
        ->createToken('pruebas', $alcances ?: array_keys(SistemaExterno::ALCANCES))
        ->plainTextToken;

    return ['Authorization' => 'Bearer '.$token] + $extra;
}

/**
 * Configura la API de Mamoré y falsea sus endpoints con el padrón dado, para
 * probar sin red las pantallas que leen los datos personales de ahí: `/people`,
 * `/people/ci/{ci}`, sus contratos —de a uno y por lote— y `/catalogos`.
 *
 * Cada persona puede darse como `ci => 'NOMBRE COMPLETO'` (sin contrato) o como
 * `ci => ['nombre' => ..., 'cargo' => ..., 'direccion' => ...]` para que salga
 * con contrato firmado, igual que lo entrega la API real. Con `image` se le
 * agrega la foto, de donde sale la miniatura que pintan las tablas, y con
 * `direccion_id` la dirección administrativa por la que filtra el reporte por
 * dirección.
 *
 * @param  array<string, string|array<string, mixed>>  $padron
 */
function fakeMamore(array $padron = []): void
{
    config()->set('services.mamore.url', 'http://mamore.test/api/externo/personal');
    config()->set('services.mamore.token', 'secreta');
    config()->set('services.mamore.origen', 'http://sismark.test');

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

            // Dónde estaba destinada la persona en cada contrato. La API real lo
            // manda adentro de cada uno, no solo en la ficha: sin eso, el reporte
            // por dirección de un mes pasado no podría decir en qué dirección
            // cayó cada tramo de quien se movió a mitad de año.
            $direccionId = $datos['direccion_id'] ?? null;
            $direccionSigla = $datos['direccion'] ?? null;
            $unidadId = $datos['unidad_id'] ?? null;
            $unidadSigla = $datos['unidad'] ?? null;

            $contratos = array_map(fn (array $contrato): array => $contrato + [
                'status' => 'firmado',
                'direccion_administrativa' => $direccionSigla === null ? null : [
                    'id' => $direccionId,
                    'nombre' => $direccionSigla,
                    'sigla' => $direccionSigla,
                ],
                'unidad_administrativa' => $unidadSigla === null ? null : [
                    'id' => $unidadId,
                    'nombre' => $unidadSigla,
                    'sigla' => $unidadSigla,
                ],
            ], $contratos);

            return [
                'id' => crc32($ci),
                'direccion_id' => $direccionId,
                'unidad_id' => $unidadId,
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
                    'direccion_administrativa' => ['id' => $direccionId, 'nombre' => $datos['direccion'] ?? 'Dirección', 'sigla' => $datos['direccion'] ?? null],
                    'unidad_administrativa' => $unidadSigla === null ? null : [
                        'id' => $unidadId, 'nombre' => $unidadSigla, 'sigla' => $unidadSigla,
                    ],
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

    /**
     * Los contratos que tocan el rango pedido, con la misma regla que la API
     * real: empiezan antes de que termine y terminan después de que empiece; sin
     * `finish` siguen vigentes. Sin rango van todos.
     *
     * El fake tiene que recortar igual que la API. Si devolviera el historial
     * entero, quien tuvo su último contrato el año pasado saldría con tramos y
     * el reporte lo listaría como funcionario del mes que se está mirando.
     *
     * @param  list<array<string, mixed>>  $contratos
     * @return list<array<string, mixed>>
     */
    $delRango = function (array $contratos, ?string $desde, ?string $hasta): array {
        return array_values(array_filter($contratos, function (array $contrato) use ($desde, $hasta): bool {
            $inicio = $contrato['start'] ?? null;
            $fin = $contrato['finish'] ?? null;

            if ($hasta !== null && $inicio !== null && $inicio > $hasta) {
                return false;
            }

            return ! ($desde !== null && $fin !== null && $fin < $desde);
        }));
    };

    Http::fake([
        // Los contratos van primero: su ruta cuelga de la del detalle, así que
        // el patrón de abajo también la alcanzaría y devolvería la ficha entera.
        //
        // De estos contratos depende qué días procesa el sistema, así que el
        // fake tiene que responder la misma forma que la API real: una lista
        // bajo `data`, con `start` y `finish`.
        'mamore.test/api/externo/personal/people/ci/*/contracts*' => function (ClientRequest $peticion) use ($filas, $delRango) {
            $partes = explode('/', trim((string) parse_url($peticion->url(), PHP_URL_PATH), '/'));
            $ci = urldecode($partes[count($partes) - 2] ?? '');
            $fila = $filas->firstWhere('ci', $ci);
            parse_str((string) parse_url($peticion->url(), PHP_URL_QUERY), $parametros);

            return Http::response(['data' => $delRango(
                $fila['contratos'] ?? [],
                $parametros['desde'] ?? null,
                $parametros['hasta'] ?? null,
            )]);
        },
        // Los contratos de varias cédulas de una vez, agrupados por cédula. Va
        // antes que el patrón del listado, que también lo alcanzaría.
        //
        // Como la API real, la cédula que no existe vuelve con lista vacía y no
        // omitida: para quien procesa no es lo mismo «no tuvo contratos» que «no
        // vino en la respuesta».
        'mamore.test/api/externo/personal/people/contracts*' => function (ClientRequest $peticion) use ($filas, $delRango) {
            $cis = (array) ($peticion->data()['ci'] ?? []);
            parse_str((string) parse_url($peticion->url(), PHP_URL_QUERY), $parametros);

            $data = [];

            foreach ($cis as $ci) {
                $fila = $filas->firstWhere('ci', (string) $ci);
                $data[(string) $ci] = $delRango(
                    $fila['contratos'] ?? [],
                    $parametros['desde'] ?? null,
                    $parametros['hasta'] ?? null,
                );
            }

            return Http::response(['data' => $data]);
        },
        // Direcciones y unidades administrativas, con cuánta gente tiene cada
        // una. Se arman desde el propio padrón para que el conteo del combo y
        // las filas del reporte no puedan discrepar.
        'mamore.test/api/externo/personal/catalogos*' => function () use ($filas) {
            // Los dos conteos, como la API real: contratos por un lado y
            // personas distintas por el otro. En un rango alguien puede tener
            // dos contratos, y el combo del reporte cuenta funcionarios.
            $conteos = fn ($delGrupo): array => [
                'direccion' => null,
                'activa' => true,
                'contratos_count' => $delGrupo->sum(fn (array $fila): int => count($fila['contratos'])),
                'funcionarios_count' => $delGrupo->count(),
            ];

            $direcciones = $filas
                ->filter(fn (array $fila): bool => $fila['direccion_id'] !== null)
                ->groupBy('direccion_id')
                ->map(fn ($delGrupo, $id): array => [
                    'id' => (int) $id,
                    'nombre' => $delGrupo->first()['contrato']['direccion_administrativa']['nombre'] ?? 'Dirección',
                    'sigla' => $delGrupo->first()['contrato']['direccion_administrativa']['sigla'] ?? null,
                ] + $conteos($delGrupo))
                ->values();

            $unidades = $filas
                ->filter(fn (array $fila): bool => $fila['unidad_id'] !== null)
                ->groupBy('unidad_id')
                ->map(fn ($delGrupo, $id): array => [
                    'id' => (int) $id,
                    'nombre' => $delGrupo->first()['contrato']['unidad_administrativa']['nombre'] ?? 'Unidad',
                    'sigla' => $delGrupo->first()['contrato']['unidad_administrativa']['sigla'] ?? null,
                    'direccion_administrativa_id' => $delGrupo->first()['direccion_id'],
                ] + $conteos($delGrupo))
                ->values();

            return Http::response([
                'data' => ['direcciones' => $direcciones->all(), 'unidades' => $unidades->all()],
                'meta' => ['total_direcciones' => $direcciones->count(), 'total_unidades' => $unidades->count()],
            ]);
        },
        // El patrón del detalle va primero: el del listado también lo alcanzaría.
        'mamore.test/api/externo/personal/people/ci/*' => function (ClientRequest $peticion) use ($filas) {
            $ci = urldecode(basename((string) parse_url($peticion->url(), PHP_URL_PATH)));
            $fila = $filas->firstWhere('ci', $ci);

            return $fila
                ? Http::response(['data' => $fila])
                : Http::response(['message' => 'not found'], 404);
        },
        'mamore.test/api/externo/personal/people*' => function (ClientRequest $peticion) use ($filas, $delRango) {
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

            // Dirección administrativa. Con `desde`/`hasta` la API real pide
            // además que algún contrato toque el rango, concluidos incluidos:
            // así el reporte de un mes pasado alcanza a quien se fue a mitad de
            // período y deja afuera a quien nunca lo trabajó.
            $direccion = (string) ($parametros['direccion'] ?? '');

            if ($direccion !== '') {
                $desdeQ = $parametros['desde'] ?? null;
                $hastaQ = $parametros['hasta'] ?? null;

                $encontrados = $encontrados
                    ->filter(fn (array $fila): bool => (string) $fila['direccion_id'] === $direccion
                        && ($desdeQ === null && $hastaQ === null
                            || $delRango($fila['contratos'], $desdeQ, $hastaQ) !== []))
                    ->values();
            }

            // Unidad administrativa, que acota dentro de la dirección.
            $unidad = (string) ($parametros['unidad'] ?? '');

            if ($unidad !== '') {
                $encontrados = $encontrados
                    ->filter(fn (array $fila): bool => (string) $fila['unidad_id'] === $unidad)
                    ->values();
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
