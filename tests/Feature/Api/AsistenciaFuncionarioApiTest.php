<?php

use App\Models\AsignacionTurno;
use App\Models\Asistencia;
use App\Models\Licencia;
use App\Models\Persona;
use App\Models\SistemaExterno;
use App\Models\Turno;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * Pedido autenticado con el token del sistema, como lo hace el consumidor real.
 */
function comoMamore(string $ruta): TestResponse
{
    return test()->getJson($ruta, cabecerasApi());
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
        'fecha' => '2026-08-03',
        'hora' => '08:12:04',
        'tipo' => Asistencia::TIPO_RELOJ,
    ]);

    Asistencia::factory()->create([
        'ci' => $ci,
        'fecha' => '2026-08-03',
        'hora' => '16:03:00',
        'tipo' => Asistencia::TIPO_RELOJ,
    ]);
}

test('sin token el acceso se rechaza', function () {
    $this->getJson('/api/v1/funcionarios/7633685/marcaciones')
        ->assertUnauthorized();
});

test('con un token inventado el acceso se rechaza', function () {
    $this->getJson('/api/v1/funcionarios/7633685/marcaciones', [
        'Authorization' => 'Bearer 1|noExisteEsteToken',
    ])->assertUnauthorized();
});

test('un despliegue sin ningún sistema cargado no atiende a nadie', function () {
    // Antes esto lo garantizaba la clave vacía; ahora lo garantiza que no haya
    // token emitido: sin fila en `sistemas_externos` no hay con qué entrar.
    expect(SistemaExterno::query()->count())->toBe(0);

    $this->getJson('/api/v1/funcionarios/7633685/marcaciones')
        ->assertUnauthorized();
});

/**
 * Pedido a la API olvidando primero el usuario que el guard dejó resuelto.
 *
 * En producción cada pedido levanta su propio contenedor, pero dentro de un
 * test el guard vive entre uno y otro y devuelve el mismo usuario que resolvió
 * la primera vez. Sin esto, apagar el sistema en medio del test no se notaría:
 * el segundo pedido pasaría con el resultado cacheado del primero.
 */
function pedirOlvidandoElGuard(array $cabeceras): TestResponse
{
    app('auth')->forgetGuards();

    return test()->getJson('/api/v1/funcionarios/7633685/marcaciones?desde=2026-08-01&hasta=2026-08-31', $cabeceras);
}

test('apagar el sistema le corta el acceso en el próximo pedido', function () {
    funcionarioConAsistencia();
    $cabeceras = cabecerasApi();

    pedirOlvidandoElGuard($cabeceras)->assertOk();

    // El interruptor no borra el token: lo deja sin efecto. Volver a encenderlo
    // restablece el acceso sin coordinar una credencial nueva.
    SistemaExterno::query()->where('slug', 'pruebas')->update(['activo' => false]);

    pedirOlvidandoElGuard($cabeceras)->assertUnauthorized();

    SistemaExterno::query()->where('slug', 'pruebas')->update(['activo' => true]);

    pedirOlvidandoElGuard($cabeceras)->assertOk();
});

test('dar de baja el sistema también le corta el acceso', function () {
    funcionarioConAsistencia();
    $cabeceras = cabecerasApi();

    SistemaExterno::query()->where('slug', 'pruebas')->delete();

    pedirOlvidandoElGuard($cabeceras)->assertUnauthorized();
});

test('un token sin el alcance de asistencia no puede leer las marcaciones', function () {
    funcionarioConAsistencia();

    // El token existe y el sistema está activo: lo que falta es el alcance.
    $this->getJson(
        '/api/v1/funcionarios/7633685/marcaciones?desde=2026-08-01&hasta=2026-08-31',
        cabecerasApi(['licencias:read'])
    )->assertForbidden();
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
        'fecha' => '2026-08-03',
        'hora' => '09:00:00',
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

    // 08:12:04 contra una hora de entrada de 08:00 → 12 minutos de atraso. Los
    // 4 segundos se descartan: el atraso se cuenta en minutos completos.
    $respuesta
        ->assertJsonPath('data.0.fecha', '2026-08-03')
        ->assertJsonPath('data.0.estado', 'atraso')
        ->assertJsonPath('data.0.estadoEtiqueta', 'Atraso')
        ->assertJsonPath('data.0.atrasoSegundos', 720)
        ->assertJsonPath('data.0.atraso', '12 min')
        ->assertJsonPath('data.0.bloques.0.entrada', '08:12:04')
        ->assertJsonPath('data.0.bloques.0.salida', '16:03:00')
        ->assertJsonPath('data.0.bloques.0.turno', 'LUN: 08:00 - 16:00')
        // Vacías: el día no fue ni abandono ni falta.
        ->assertJsonPath('data.0.bloques.0.abandono', '')
        ->assertJsonPath('data.0.bloques.0.falta', '')
        ->assertJsonPath('totales.atraso', '12 min');
});

test('el día sin marcar informa la falta como texto listo para el reporte', function () {
    funcionarioConAsistencia();

    // El lunes 2026-08-10 tiene turno pero no hay marcaciones cargadas.
    comoMamore('/api/v1/funcionarios/7633685/asistencia?desde=2026-08-10&hasta=2026-08-10')
        ->assertOk()
        ->assertJsonPath('data.0.estado', 'falta')
        ->assertJsonPath('data.0.bloques.0.falta', 'FALTA')
        ->assertJsonPath('data.0.bloques.0.abandono', '');
});

test('los totales cuentan los días de cada estado con su etiqueta', function () {
    funcionarioConAsistencia();

    // 03/08 lunes con turno y marcas; 04/08 martes con turno y sin marcas.
    comoMamore('/api/v1/funcionarios/7633685/asistencia?desde=2026-08-03&hasta=2026-08-04')
        ->assertOk()
        ->assertJsonFragment(['estado' => 'atraso', 'etiqueta' => 'Atraso', 'cantidad' => 1])
        ->assertJsonFragment(['estado' => 'no_laborable', 'etiqueta' => 'No laborable', 'cantidad' => 1]);
});

test('la ficha del funcionario trae lo que rotula el reporte', function () {
    funcionarioConAsistencia();
    fakeMamore(['7633685' => [
        'nombre' => 'IGNACIO MOLINA GUZMAN',
        'cargo' => 'DESARROLLADOR DE SISTEMAS',
        'direccion' => 'SDAF',
    ]]);

    comoMamore('/api/v1/funcionarios/7633685/asistencia?desde=2026-08-03&hasta=2026-08-03')
        ->assertOk()
        ->assertJsonPath('meta.funcionario.pinReloj', '7633685')
        ->assertJsonPath('meta.funcionario.cargo', 'DESARROLLADOR DE SISTEMAS')
        ->assertJsonPath('meta.funcionario.direccion', 'SDAF');
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
        'fecha' => '2026-08-03',
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

/**
 * Pedido de varios días: una `solicitud` compartida, y solo la primera fila la
 * abre. Es lo que deja `RegistroLicencia`.
 *
 * @return Collection<int, Licencia>
 */
function pedidoDeVariosDias(array $fechas, string $motivo = 'CONSULTA MEDICA'): Collection
{
    $turno = Turno::query()->first();
    $solicitud = (string) Str::ulid();

    return collect($fechas)->values()->map(fn (string $fecha, int $i) => Licencia::factory()->create([
        'ci' => '7633685',
        'turno_id' => $turno->id,
        'fecha' => $fecha,
        'solicitud' => $solicitud,
        'motivo' => $motivo,
    ]));
}

test('un pedido de varios días llega como una sola licencia', function () {
    funcionarioConAsistencia();
    pedidoDeVariosDias(['2026-08-03', '2026-08-10', '2026-08-17']);

    // Tres filas en la base, una licencia para el funcionario.
    expect(Licencia::count())->toBe(3);

    comoMamore('/api/v1/funcionarios/7633685/licencias?desde=2026-08-01&hasta=2026-08-31')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.fecha', '2026-08-03')
        ->assertJsonPath('data.0.hasta', '2026-08-17')
        ->assertJsonPath('data.0.dias', 3)
        ->assertJsonPath('data.0.unSoloDia', false);
});

test('una licencia de un solo día se marca como tal', function () {
    funcionarioConAsistencia();
    pedidoDeVariosDias(['2026-08-03']);

    comoMamore('/api/v1/funcionarios/7633685/licencias?desde=2026-08-01&hasta=2026-08-31')
        ->assertOk()
        ->assertJsonPath('data.0.dias', 1)
        ->assertJsonPath('data.0.unSoloDia', true)
        ->assertJsonPath('data.0.hasta', '2026-08-03');
});

test('un pedido que empezó antes del rango se ve entero y no cortado', function () {
    funcionarioConAsistencia();
    // Empieza en julio y sigue en agosto.
    pedidoDeVariosDias(['2026-07-27', '2026-08-03']);

    // Consultando agosto tiene que aparecer igual, con su periodo real: quien
    // pidió del 27 de julio al 3 de agosto no pidió dos licencias.
    comoMamore('/api/v1/funcionarios/7633685/licencias?desde=2026-08-01&hasta=2026-08-31')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.fecha', '2026-07-27')
        ->assertJsonPath('data.0.hasta', '2026-08-03')
        ->assertJsonPath('data.0.dias', 2);
});

test('un pedido fuera del rango no aparece', function () {
    funcionarioConAsistencia();
    pedidoDeVariosDias(['2026-06-01', '2026-06-08']);

    comoMamore('/api/v1/funcionarios/7633685/licencias?desde=2026-08-01&hasta=2026-08-31')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('los días resueltos de distinta manera se avisan como parciales', function () {
    funcionarioConAsistencia();
    $dias = pedidoDeVariosDias(['2026-08-03', '2026-08-10']);

    Licencia::whereKey($dias->last()->id)->update(['estado' => Licencia::RECHAZADO]);

    comoMamore('/api/v1/funcionarios/7633685/licencias?desde=2026-08-01&hasta=2026-08-31')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.estadoMixto', true);
});

test('las licencias llegan de la más reciente a la más vieja', function () {
    funcionarioConAsistencia();

    // Tres pedidos sueltos, creados en desorden a propósito.
    pedidoDeVariosDias(['2026-08-10'], 'DEL MEDIO');
    pedidoDeVariosDias(['2026-08-24'], 'LA MAS NUEVA');
    pedidoDeVariosDias(['2026-08-03'], 'LA MAS VIEJA');

    comoMamore('/api/v1/funcionarios/7633685/licencias?desde=2026-08-01&hasta=2026-08-31')
        ->assertOk()
        // Lo último que pidió es lo que viene a mirar: en un rango largo, con
        // orden cronológico quedaba al fondo de la tabla.
        ->assertJsonPath('data.0.motivo', 'LA MAS NUEVA')
        ->assertJsonPath('data.1.motivo', 'DEL MEDIO')
        ->assertJsonPath('data.2.motivo', 'LA MAS VIEJA');
});

test('el respaldo de una licencia propia se entrega como enlace temporal', function () {
    Storage::fake('s3');
    funcionarioConAsistencia();

    $licencia = pedidoDeVariosDias(['2026-08-03'])->first();
    $licencia->update(['adjunto' => 'licencias/2026/7633685/x.pdf', 'adjuntoNombre' => 'certificado.pdf']);
    Storage::disk('s3')->put('licencias/2026/7633685/x.pdf', 'contenido');

    comoMamore("/api/v1/funcionarios/7633685/licencias/{$licencia->id}/respaldo")
        ->assertOk()
        ->assertJsonPath('nombre', 'certificado.pdf')
        ->assertJsonStructure(['url', 'nombre']);
});

test('no se puede pedir el respaldo de la licencia de otro funcionario', function () {
    Storage::fake('s3');
    funcionarioConAsistencia();

    // Licencia de otra persona, con su certificado médico.
    $otro = Persona::factory()->create(['ci' => '6522875']);
    $ajena = Licencia::factory()->create([
        'ci' => $otro->ci,
        'turno_id' => Turno::query()->first()->id,
        'adjunto' => 'licencias/2026/6522875/y.pdf',
        'adjuntoNombre' => 'certificado-ajeno.pdf',
    ]);
    Storage::disk('s3')->put('licencias/2026/6522875/y.pdf', 'contenido');

    // El id es un número corrido: sin esta comprobación, subirlo de a uno daría
    // los certificados de todo el personal.
    comoMamore("/api/v1/funcionarios/7633685/licencias/{$ajena->id}/respaldo")
        ->assertNotFound()
        // Y el mensaje no confirma que exista: solo «no existe».
        ->assertJsonMissing(['nombre' => 'certificado-ajeno.pdf']);
});

test('una licencia sin respaldo devuelve 404 y no un enlace roto', function () {
    Storage::fake('s3');
    funcionarioConAsistencia();

    $licencia = pedidoDeVariosDias(['2026-08-03'])->first();

    comoMamore("/api/v1/funcionarios/7633685/licencias/{$licencia->id}/respaldo")
        ->assertNotFound();
});

test('sin la clave compartida no se entrega ningún respaldo', function () {
    Storage::fake('s3');
    funcionarioConAsistencia();

    $licencia = pedidoDeVariosDias(['2026-08-03'])->first();

    test()->getJson("/api/v1/funcionarios/7633685/licencias/{$licencia->id}/respaldo")
        ->assertUnauthorized();
});

test('la ficha de una licencia trae el pedido y sus días', function () {
    funcionarioConAsistencia();
    $dias = pedidoDeVariosDias(['2026-08-03', '2026-08-10', '2026-08-17'], 'CONSULTA MEDICA');

    comoMamore('/api/v1/funcionarios/7633685/licencias/'.$dias->first()->id)
        ->assertOk()
        ->assertJsonPath('licencia.fecha', '2026-08-03')
        ->assertJsonPath('licencia.hasta', '2026-08-17')
        ->assertJsonPath('licencia.dias', 3)
        ->assertJsonPath('licencia.motivo', 'CONSULTA MEDICA')
        ->assertJsonCount(3, 'dias')
        ->assertJsonPath('dias.0.fecha', '2026-08-03')
        ->assertJsonStructure(['dias' => [['fecha', 'diaSemana', 'turno', 'estado']]]);
});

test('se abre por cualquiera de sus días y siempre muestra el pedido entero', function () {
    funcionarioConAsistencia();
    $dias = pedidoDeVariosDias(['2026-08-03', '2026-08-10'], 'COMISION');

    // Entrando por el segundo día se ve el pedido desde el primero.
    comoMamore('/api/v1/funcionarios/7633685/licencias/'.$dias->last()->id)
        ->assertOk()
        ->assertJsonPath('licencia.fecha', '2026-08-03')
        ->assertJsonCount(2, 'dias');
});

test('no se puede abrir la ficha de la licencia de otro funcionario', function () {
    funcionarioConAsistencia();

    $otro = Persona::factory()->create(['ci' => '6522875']);
    $ajena = Licencia::factory()->create([
        'ci' => $otro->ci,
        'turno_id' => Turno::query()->first()->id,
        'motivo' => 'RESERVADO',
    ]);

    comoMamore('/api/v1/funcionarios/7633685/licencias/'.$ajena->id)
        ->assertNotFound()
        ->assertJsonMissing(['motivo' => 'RESERVADO']);
});

/*
|--------------------------------------------------------------------------
| La ficha del funcionario se puede apagar
|--------------------------------------------------------------------------
|
| Resolverla cuesta un salto de red a Mamoré, y el consumidor que pregunta por
| la cédula de su propia sesión ya sabe de quién se trata.
*/

test('con funcionario=0 no se va a buscar la ficha a Mamoré', function () {
    funcionarioConAsistencia();
    fakeMamore(['7633685' => 'IGNACIO MOLINA GUZMAN']);

    $respuesta = comoMamore('/api/v1/funcionarios/7633685/marcaciones?desde=2026-08-03&hasta=2026-08-03&funcionario=0');

    // Lo que importa no es que falte la clave, sino que no haya salido el
    // pedido: es ese salto el que hace lento y frágil todo lo demás.
    Http::assertNothingSent();

    $respuesta->assertOk()
        ->assertJsonMissingPath('meta.funcionario')
        // El resto de `meta` sigue viajando: no cuesta una consulta a nadie.
        ->assertJsonPath('meta.rango.desde', '2026-08-03');
});

test('sin el parámetro la ficha sigue viniendo', function () {
    funcionarioConAsistencia();
    fakeMamore(['7633685' => 'IGNACIO MOLINA GUZMAN']);

    // Apagarla es una decisión del consumidor, no el comportamiento nuevo por
    // omisión: los reportes que muestran una columna «Funcionario» a partir de
    // un CI suelto la siguen necesitando.
    comoMamore('/api/v1/funcionarios/7633685/marcaciones?desde=2026-08-03&hasta=2026-08-03')
        ->assertOk()
        ->assertJsonPath('meta.funcionario.nombre', 'IGNACIO MOLINA GUZMAN');
});

test('en licencias el parámetro también evita el salto de red', function () {
    funcionarioConAsistencia();
    fakeMamore(['7633685' => 'IGNACIO MOLINA GUZMAN']);

    comoMamore('/api/v1/funcionarios/7633685/licencias?desde=2026-08-03&hasta=2026-08-03&funcionario=0')
        ->assertOk()
        ->assertJsonMissingPath('meta.funcionario');

    Http::assertNothingSent();
});

test('en asistencia apaga la ficha, pero el contrato se sigue consultando', function () {
    funcionarioConAsistencia();
    fakeMamore(['7633685' => 'IGNACIO MOLINA GUZMAN']);

    comoMamore('/api/v1/funcionarios/7633685/asistencia?desde=2026-08-03&hasta=2026-08-03&funcionario=0')
        ->assertOk()
        ->assertJsonMissingPath('meta.funcionario');

    // Acá el parámetro ahorra menos, y la prueba lo deja escrito para que nadie
    // lo lea como «asistencia no habla con Mamoré»: `ProcesadorAsistencia`
    // pregunta por el contrato, que es la primera puerta del cálculo —sin
    // contrato vigente no hay día que controlar—. Ese salto no es de adorno y
    // no se puede apagar.
    Http::assertSentCount(1);
});

/*
|--------------------------------------------------------------------------
| Los contratos los puede mandar el consumidor
|--------------------------------------------------------------------------
|
| Mamoré es donde viven los contratos, así que pedírselos de vuelta era un viaje
| a su propio servidor para recuperar un dato que ya tenía en la mano.
*/

test('con los contratos en el pedido no se le pregunta nada a Mamoré', function () {
    funcionarioConAsistencia();
    fakeMamore(['7633685' => 'IGNACIO MOLINA GUZMAN']);

    $respuesta = comoMamore('/api/v1/funcionarios/7633685/asistencia'
        .'?desde=2026-08-03&hasta=2026-08-03&funcionario=0'
        .'&contratos[0][desde]=2026-01-01&contratos[0][hasta]=2026-12-31');

    // Lo que importa no es el número: es que no salió ningún pedido. Ese salto
    // era el único punto del endpoint que dependía de que el consumidor
    // estuviera disponible para contestarse a sí mismo.
    Http::assertNothingSent();

    $respuesta->assertOk()
        ->assertJsonPath('data.0.estado', 'atraso')
        ->assertJsonPath('totales.atraso', '12 min');
});

test('un contrato que no cubre el día lo deja sin controlar', function () {
    funcionarioConAsistencia();
    fakeMamore(['7633685' => 'IGNACIO MOLINA GUZMAN']);

    // El contrato terminó en julio y el día es de agosto: tiene turno asignado,
    // así que no es «no laborable» —eso taparía el problema—, es «sin contrato».
    comoMamore('/api/v1/funcionarios/7633685/asistencia'
        .'?desde=2026-08-03&hasta=2026-08-03&funcionario=0'
        .'&contratos[0][desde]=2026-01-01&contratos[0][hasta]=2026-07-31')
        ->assertOk()
        ->assertJsonPath('data.0.estado', 'sin_contrato');
});

test('el contrato sin fecha de fin sigue abierto', function () {
    funcionarioConAsistencia();
    fakeMamore(['7633685' => 'IGNACIO MOLINA GUZMAN']);

    comoMamore('/api/v1/funcionarios/7633685/asistencia'
        .'?desde=2026-08-03&hasta=2026-08-03&funcionario=0'
        .'&contratos[0][desde]=2026-01-01')
        ->assertOk()
        ->assertJsonPath('data.0.estado', 'atraso');
});

test('la lista vacía de contratos no es lo mismo que no mandarla', function () {
    funcionarioConAsistencia();
    fakeMamore(['7633685' => 'IGNACIO MOLINA GUZMAN']);

    // Vacía significa «no tuvo contrato», y eso excluye el día. Es distinto de
    // no mandar nada, que significa «no sé» y manda a preguntar.
    comoMamore('/api/v1/funcionarios/7633685/asistencia?desde=2026-08-03&hasta=2026-08-03&funcionario=0&contratos=')
        ->assertOk()
        ->assertJsonPath('data.0.estado', 'sin_contrato');

    Http::assertNothingSent();
});

test('sin contratos en el pedido se le siguen preguntando a Mamoré', function () {
    funcionarioConAsistencia();
    fakeMamore(['7633685' => ['nombre' => 'IGNACIO MOLINA GUZMAN', 'cargo' => 'DESARROLLADOR']]);

    // El comportamiento viejo no se toca: los consumidores que no los tengan
    // —y los reportes de acá— siguen andando igual.
    comoMamore('/api/v1/funcionarios/7633685/asistencia?desde=2026-08-03&hasta=2026-08-03&funcionario=0')
        ->assertOk();

    Http::assertSentCount(1);
});

test('un contrato con fecha inválida se rechaza', function () {
    funcionarioConAsistencia();

    comoMamore('/api/v1/funcionarios/7633685/asistencia?desde=2026-08-03&hasta=2026-08-03&contratos[0][desde]=ayer')
        ->assertStatus(422)
        ->assertJsonValidationErrors('contratos.0.desde');
});
