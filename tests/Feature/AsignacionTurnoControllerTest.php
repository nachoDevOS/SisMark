<?php

use App\Models\AsignacionTurno;
use App\Models\Persona;
use App\Models\Turno;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(asSuperAdmin());
});

test('la pantalla carga el shell del listado', function () {
    $this->get(route('turnos-asignados.index'))
        ->assertOk()
        ->assertSee('Turnos asignados')
        ->assertSee('id="div-results"', false);
});

test('el listado muestra el funcionario y su turno cruzados por CI y turno_id', function () {
    Persona::factory()->create(['ci' => '7633685', 'paterno' => 'Molina', 'nombres' => 'Ignacio']);
    $turno = Turno::factory()->create(['dia' => '2', 'nombreTurno' => 'LUN: 08:00 - 16:00']);

    AsignacionTurno::factory()->create(['ci' => '7633685', 'turno_id' => $turno->id]);

    $this->get(route('turnos-asignados.list'))
        ->assertOk()
        ->assertSee('Ignacio Molina')
        ->assertSee('7633685')
        ->assertSee('LUN: 08:00 - 16:00')
        ->assertSee('Lunes')
        ->assertSee('Vigente');
});

test('el listado muestra la foto de Mamoré del funcionario', function () {
    Persona::factory()->create(['ci' => '7633685', 'paterno' => 'Molina', 'nombres' => 'Ignacio']);
    $turno = Turno::factory()->create(['dia' => '2', 'nombreTurno' => 'LUN: 08:00 - 16:00']);
    AsignacionTurno::factory()->create(['ci' => '7633685', 'turno_id' => $turno->id]);

    fakeMamore(['7633685' => [
        'nombre' => 'IGNACIO MOLINA GUZMAN',
        'cargo' => 'DESARROLLADOR DE SISTEMAS',
        'image' => 'http://mamore.test/fotos/7633685.png',
    ]]);

    // Se pinta la miniatura; la original queda como respaldo del `onerror`.
    $this->get(route('turnos-asignados.list'))
        ->assertOk()
        ->assertSee('IGNACIO MOLINA GUZMAN')
        ->assertSee('http://mamore.test/fotos/7633685-cropped.png')
        ->assertSee('http://mamore.test/fotos/7633685.png');
});

test('el listado cae al ícono genérico cuando el funcionario no tiene foto', function () {
    Persona::factory()->create(['ci' => '7633685', 'paterno' => 'Molina', 'nombres' => 'Ignacio']);
    $turno = Turno::factory()->create(['dia' => '2', 'nombreTurno' => 'LUN: 08:00 - 16:00']);
    AsignacionTurno::factory()->create(['ci' => '7633685', 'turno_id' => $turno->id]);

    // Sin Mamoré la ficha sale de la base local, que no guarda fotos.
    $this->get(route('turnos-asignados.list'))
        ->assertOk()
        ->assertSee('Ignacio Molina')
        ->assertDontSee('-cropped.png');
});

test('el listado avisa cuando la asignación quedó sin turno vinculado', function () {
    // La copia del SIA deja `turno_id` en null si el código histórico no cruzó.
    AsignacionTurno::factory()->create(['ci' => '7633685', 'turno_id' => null, 'idTurno' => '999']);

    $this->get(route('turnos-asignados.list'))
        ->assertOk()
        ->assertSee('Sin turno vinculado');
});

test('la búsqueda filtra por CI, por nombre del funcionario y por nombre del turno', function () {
    Persona::factory()->create(['ci' => '1111111', 'paterno' => 'Molina', 'nombres' => 'Ignacio']);
    Persona::factory()->create(['ci' => '2222222', 'paterno' => 'Perez', 'nombres' => 'Juana']);

    $manana = Turno::factory()->create(['nombreTurno' => 'LUN: 08:00 - 16:00']);
    $tarde = Turno::factory()->create(['nombreTurno' => 'MAR: 14:00 - 22:00']);

    AsignacionTurno::factory()->create(['ci' => '1111111', 'turno_id' => $manana->id]);
    AsignacionTurno::factory()->create(['ci' => '2222222', 'turno_id' => $tarde->id]);

    $this->get(route('turnos-asignados.list', ['q' => '1111111']))
        ->assertOk()
        ->assertSee('1111111')
        ->assertDontSee('2222222');

    $this->get(route('turnos-asignados.list', ['q' => 'Molina']))
        ->assertOk()
        ->assertSee('1111111')
        ->assertDontSee('2222222');

    $this->get(route('turnos-asignados.list', ['q' => '14:00']))
        ->assertOk()
        ->assertSee('2222222')
        ->assertDontSee('1111111');
});

test('el listado filtra por día del turno', function () {
    $lunes = Turno::factory()->create(['dia' => '2', 'nombreTurno' => 'Turno del lunes']);
    $martes = Turno::factory()->create(['dia' => '3', 'nombreTurno' => 'Turno del martes']);

    AsignacionTurno::factory()->create(['ci' => '1111111', 'turno_id' => $lunes->id]);
    AsignacionTurno::factory()->create(['ci' => '2222222', 'turno_id' => $martes->id]);

    $this->get(route('turnos-asignados.list', ['dia' => '2']))
        ->assertOk()
        ->assertSee('Turno del lunes')
        ->assertDontSee('Turno del martes');
});

test('el listado filtra por situación respecto de hoy', function () {
    $turno = Turno::factory()->create();

    AsignacionTurno::factory()->create(['ci' => '1111111', 'turno_id' => $turno->id]);
    AsignacionTurno::factory()->vencida()->create(['ci' => '2222222', 'turno_id' => $turno->id]);
    AsignacionTurno::factory()->create([
        'ci' => '3333333',
        'turno_id' => $turno->id,
        'desde' => today()->addMonth(),
        'hasta' => today()->addYear(),
    ]);

    $this->get(route('turnos-asignados.list', ['situacion' => 'vigentes']))
        ->assertOk()
        ->assertSee('1111111')
        ->assertDontSee('2222222')
        ->assertDontSee('3333333');

    $this->get(route('turnos-asignados.list', ['situacion' => 'vencidas']))
        ->assertOk()
        ->assertSee('2222222')
        ->assertSee('Vencida')
        ->assertDontSee('1111111');

    $this->get(route('turnos-asignados.list', ['situacion' => 'futuras']))
        ->assertOk()
        ->assertSee('3333333')
        ->assertSee('Aún no vigente')
        ->assertDontSee('1111111');
});

test('una situación desconocida no deja la tabla vacía', function () {
    $turno = Turno::factory()->create();
    AsignacionTurno::factory()->create(['ci' => '1111111', 'turno_id' => $turno->id]);

    $this->get(route('turnos-asignados.list', ['situacion' => 'cualquiera']))
        ->assertOk()
        ->assertSee('1111111');
});

test('el listado respeta el selector de registros por página', function () {
    $turno = Turno::factory()->create();
    AsignacionTurno::factory()->count(12)->create(['turno_id' => $turno->id]);

    $this->get(route('turnos-asignados.list', ['por_pagina' => 10]))
        ->assertOk()
        ->assertViewHas('asignaciones', fn ($asignaciones): bool => $asignaciones->count() === 10);
});

test('el formulario de asignación lista los turnos disponibles', function () {
    Turno::factory()->create(['dia' => '2', 'nombreTurno' => 'LUN: 08:00 - 16:00']);

    $this->get(route('turnos-asignados.create'))
        ->assertOk()
        ->assertSee('Asignar turno')
        ->assertSee('LUN: 08:00 - 16:00')
        ->assertSee('Lunes');
});

test('asigna un turno a un funcionario y vincula por turno_id', function () {
    $turno = Turno::factory()->create(['idTurno' => '007']);

    $this->post(route('turnos-asignados.store'), [
        'ci' => '7633685',
        'turno_id' => $turno->id,
        'desde' => today()->toDateString(),
        'hasta' => today()->addYear()->toDateString(),
        'observacion' => 'Turno de verano.',
    ])
        ->assertRedirect(route('turnos-asignados.index', ['buscar' => '7633685']))
        ->assertSessionHas('estado');

    $this->assertDatabaseHas('asignacion_turnos', [
        'ci' => '7633685',
        'turno_id' => $turno->id,
        // El código histórico se copia del turno, no llega del formulario.
        'idTurno' => '007',
        'observacion' => 'Turno de verano.',
    ]);
});

test('al asignar desde la ficha vuelve a la ficha, en la solapa de turnos', function () {
    $turno = Turno::factory()->create();

    $this->post(route('turnos-asignados.store'), [
        'ci' => '7633685',
        'turno_id' => $turno->id,
        'desde' => today()->toDateString(),
        'hasta' => today()->addYear()->toDateString(),
        'origen' => 'mamore',
    ])->assertRedirect(route('funcionarios.mamore', ['ci' => '7633685']).'#turnos');
});

test('la solapa de turnos asigna desde un modal, sin salir de la ficha', function () {
    $persona = Persona::factory()->create(['ci' => '7633685']);
    Turno::factory()->create(['dia' => '2', 'nombreTurno' => 'LUN: 08:00 - 16:00']);

    $this->get(route('funcionarios.show', $persona))
        ->assertOk()
        ->assertSee('Asignar turno a CI 7633685')
        ->assertSee('<input type="hidden" name="_form" value="turno-asignado">', escape: false)
        ->assertSee('<input type="hidden" name="ci" value="7633685">', escape: false)
        ->assertSee('<input type="hidden" name="origen" value="local">', escape: false)
        // El turno se elige de una lista de tarjetas con su horario, filtrable
        // por día, en vez de un <select> donde no entra el detalle.
        ->assertSee('class="turno-picker"', escape: false)
        ->assertSee('LUN: 08:00 - 16:00')
        // Cada tarjeta lleva el día completo para el resumen de lo elegido.
        ->assertSee('nombreDia')
        ->assertSee('lunes')
        ->assertSee('class="turno-elegido"', escape: false)
        // Buscador del selector, con el texto plegado contra el que filtra.
        ->assertSee('class="turno-picker__buscador"', escape: false)
        ->assertSee('Buscar por turno, día u hora (ej. 08:00, lunes)')
        ->assertSee('busca');
});

test('el buscador del selector de turnos indexa día, nombre y horas sin tildes', function () {
    $persona = Persona::factory()->create(['ci' => '7633685']);
    Turno::factory()->create([
        'dia' => '4',
        'nombreTurno' => 'MIÉ: 08:00 - 16:00',
        'hEntrada' => '08:00',
        'hSalida' => '16:00',
    ]);

    $this->get(route('funcionarios.show', $persona))
        ->assertOk()
        // «miercoles» sin tilde tiene que encontrar al turno del miércoles.
        ->assertSee('miercoles mie: 08:00 - 16:00 08:00 16:00');
});

test('un origen desconocido no saca al usuario del listado', function () {
    $turno = Turno::factory()->create();

    $this->post(route('turnos-asignados.store'), [
        'ci' => '7633685',
        'turno_id' => $turno->id,
        'desde' => today()->toDateString(),
        'hasta' => today()->addYear()->toDateString(),
        'origen' => 'https://otro-sitio.test',
    ])->assertRedirect(route('turnos-asignados.index', ['buscar' => '7633685']));
});

test('el alta valida los campos obligatorios y el orden de las fechas', function () {
    $this->post(route('turnos-asignados.store'), [])
        ->assertSessionHasErrors(['ci', 'turno_id', 'desde', 'hasta']);

    $turno = Turno::factory()->create();

    $this->post(route('turnos-asignados.store'), [
        'ci' => '7633685',
        'turno_id' => $turno->id,
        'desde' => today()->toDateString(),
        'hasta' => today()->subDay()->toDateString(),
    ])->assertSessionHasErrors('hasta');

    $this->assertDatabaseCount('asignacion_turnos', 0);
});

test('el alta rechaza un turno inexistente o eliminado', function () {
    $borrado = Turno::factory()->create();
    $borrado->delete();

    foreach ([99999, $borrado->id] as $turnoId) {
        $this->post(route('turnos-asignados.store'), [
            'ci' => '7633685',
            'turno_id' => $turnoId,
            'desde' => today()->toDateString(),
            'hasta' => today()->addYear()->toDateString(),
        ])->assertSessionHasErrors('turno_id');
    }

    $this->assertDatabaseCount('asignacion_turnos', 0);
});

test('el alta avisa si el funcionario ya tiene ese turno desde esa fecha', function () {
    $turno = Turno::factory()->create(['idTurno' => '007']);
    AsignacionTurno::factory()->create([
        'ci' => '7633685',
        'turno_id' => $turno->id,
        'idTurno' => '007',
        'desde' => today(),
    ]);

    $this->post(route('turnos-asignados.store'), [
        'ci' => '7633685',
        'turno_id' => $turno->id,
        'desde' => today()->toDateString(),
        'hasta' => today()->addYear()->toDateString(),
    ])->assertSessionHasErrors('turno_id');

    $this->assertDatabaseCount('asignacion_turnos', 1);
});

test('la asignación repetida se detecta aunque la anterior esté eliminada', function () {
    // La clave única de la tabla incluye las filas borradas lógicamente: sin
    // esta comprobación el alta reventaría contra la base.
    $turno = Turno::factory()->create(['idTurno' => '007']);
    $asignacion = AsignacionTurno::factory()->create([
        'ci' => '7633685',
        'turno_id' => $turno->id,
        'idTurno' => '007',
        'desde' => today(),
    ]);
    $asignacion->delete();

    $this->post(route('turnos-asignados.store'), [
        'ci' => '7633685',
        'turno_id' => $turno->id,
        'desde' => today()->toDateString(),
        'hasta' => today()->addYear()->toDateString(),
    ])->assertSessionHasErrors('turno_id');
});

test('un usuario sin permiso no puede asignar turnos', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('turnos-asignados.create'))->assertForbidden();
    $this->post(route('turnos-asignados.store'), [])->assertForbidden();
    $this->get(route('turnos-asignados.funcionarios', ['q' => 'perez']))->assertForbidden();
});

test('concluir una asignación le pone fecha de fin sin borrarla', function () {
    $turno = Turno::factory()->create();
    $asignacion = AsignacionTurno::factory()->create([
        'ci' => '7633685',
        'turno_id' => $turno->id,
        'desde' => today()->subMonth(),
        'hasta' => today()->addYear(),
    ]);

    $this->patch(route('turnos-asignados.concluir', $asignacion), ['hasta' => today()->toDateString()])
        ->assertRedirect()
        ->assertSessionHas('estado');

    $asignacion->refresh();

    expect($asignacion->hasta->toDateString())->toBe(today()->toDateString())
        ->and($asignacion->trashed())->toBeFalse()
        ->and($asignacion->situacion)->toBe('vigente');

    // Al día siguiente ya no cuenta como vigente: eso es concluir.
    $this->travel(1)->day();
    expect($asignacion->fresh()->situacion)->toBe('vencida');
});

test('concluir desde la ficha vuelve a la solapa de turnos', function () {
    $asignacion = AsignacionTurno::factory()->create(['ci' => '7633685', 'turno_id' => Turno::factory()]);

    $this->patch(route('turnos-asignados.concluir', $asignacion), [
        'hasta' => today()->toDateString(),
        'origen' => 'mamore',
    ])->assertRedirect(route('funcionarios.mamore', ['ci' => '7633685']).'#turnos');
});

test('no se puede concluir una asignación antes de que empiece', function () {
    $asignacion = AsignacionTurno::factory()->create([
        'turno_id' => Turno::factory(),
        'desde' => today(),
        'hasta' => today()->addYear(),
    ]);

    $this->patch(route('turnos-asignados.concluir', $asignacion), ['hasta' => today()->subDay()->toDateString()])
        ->assertSessionHasErrors('hasta');

    expect($asignacion->fresh()->hasta->toDateString())->toBe(today()->addYear()->toDateString());
});

test('eliminar una asignación la borra lógicamente con su motivo', function () {
    $asignacion = AsignacionTurno::factory()->create(['ci' => '7633685', 'turno_id' => Turno::factory()]);

    $this->delete(route('turnos-asignados.destroy', $asignacion), [
        'deleteObservacion' => 'Se cargó al funcionario equivocado.',
    ])->assertRedirect();

    $this->assertSoftDeleted('asignacion_turnos', [
        'id' => $asignacion->id,
        'deleteObservacion' => 'Se cargó al funcionario equivocado.',
    ]);
});

test('la tabla ofrece concluir en las vigentes y eliminar en todas', function () {
    $turno = Turno::factory()->create(['nombreTurno' => 'LUN: 08:00 - 16:00']);
    $vigente = AsignacionTurno::factory()->create(['ci' => '7633685', 'turno_id' => $turno->id]);
    $vencida = AsignacionTurno::factory()->vencida()->create(['ci' => '7633685', 'turno_id' => $turno->id]);

    $respuesta = $this->get(route('funcionarios.turnos.list', ['ci' => '7633685']))->assertOk();

    // Concluir solo tiene sentido en lo que sigue en pie.
    $respuesta->assertSee(str_replace('/', '\/', route('turnos-asignados.concluir', $vigente)), escape: false)
        ->assertDontSee(str_replace('/', '\/', route('turnos-asignados.concluir', $vencida)), escape: false)
        // La baja está disponible en las dos, para lo cargado por error.
        ->assertSee(str_replace('/', '\/', route('turnos-asignados.destroy', $vigente)), escape: false)
        ->assertSee(str_replace('/', '\/', route('turnos-asignados.destroy', $vencida)), escape: false);
});

test('un usuario sin permiso no puede concluir ni eliminar asignaciones', function () {
    $asignacion = AsignacionTurno::factory()->create(['turno_id' => Turno::factory()]);

    $this->actingAs(User::factory()->create());

    $this->patch(route('turnos-asignados.concluir', $asignacion), ['hasta' => today()->toDateString()])
        ->assertForbidden();
    $this->delete(route('turnos-asignados.destroy', $asignacion), ['deleteObservacion' => 'Motivo cualquiera.'])
        ->assertForbidden();
});

test('un invitado no puede ver los turnos asignados', function () {
    auth()->logout();

    $this->get(route('turnos-asignados.index'))->assertRedirect();
});

test('un usuario sin permiso no puede ver los turnos asignados', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('turnos-asignados.index'))->assertForbidden();
    $this->get(route('turnos-asignados.list'))->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Los bordes del día
|--------------------------------------------------------------------------
|
| `desde` y `hasta` son `datetime`, y el filtro por situación las compara por
| rango y no con `whereDate()`: envolver la columna en `DATE()` anula el índice
| `(hasta, desde)` y manda a recorrer las 420.721 filas de la tabla.
|
| La trampa del rango es la hora. Una asignación que arranca hoy a las 18:00
| está vigente hoy —el día es el que manda, no la hora—, pero un `desde <= hoy`
| escrito de la forma obvia la deja afuera, porque «hoy» como `datetime` es hoy a
| las cero. Lo mismo del otro lado con `hasta`.
|
| Estas pruebas fijan las cuatro esquinas. Sin ellas, el error no se ve: la
| pantalla sigue andando y solo pierde las asignaciones que empiezan o terminan
| justo hoy.
|
*/

/**
 * Asignación con vigencia puesta a mano, para parar las fechas sobre el borde.
 */
function asignacionVigente(string $ci, string $desde, string $hasta): AsignacionTurno
{
    return AsignacionTurno::factory()->create([
        'ci' => $ci,
        'turno_id' => Turno::factory()->create()->id,
        'desde' => $desde,
        'hasta' => $hasta,
    ]);
}

test('sigue vigente la asignación que empieza hoy, aunque arranque pasado el mediodía', function () {
    asignacionVigente('1111111', today()->setTime(18, 30)->toDateTimeString(), today()->addYear()->toDateTimeString());

    $this->get(route('turnos-asignados.list', ['situacion' => 'vigentes']))
        ->assertOk()
        ->assertSee('1111111');
});

test('sigue vigente la asignación que termina hoy, tanto a las cero como al filo de la medianoche', function () {
    asignacionVigente('1111111', today()->subYear()->toDateTimeString(), today()->toDateTimeString());
    asignacionVigente('2222222', today()->subYear()->toDateTimeString(), today()->setTime(23, 59, 59)->toDateTimeString());

    $this->get(route('turnos-asignados.list', ['situacion' => 'vigentes']))
        ->assertOk()
        ->assertSee('1111111')
        ->assertSee('2222222');
});

test('la que terminó ayer ya no está vigente y cuenta como vencida', function () {
    asignacionVigente('1111111', today()->subYear()->toDateTimeString(), today()->subDay()->setTime(23, 59, 59)->toDateTimeString());

    $this->get(route('turnos-asignados.list', ['situacion' => 'vigentes']))
        ->assertOk()
        ->assertDontSee('1111111');

    $this->get(route('turnos-asignados.list', ['situacion' => 'vencidas']))
        ->assertOk()
        ->assertSee('1111111');
});

test('la que empieza mañana es futura y no vigente, ni siquiera arrancando a las cero', function () {
    asignacionVigente('1111111', today()->addDay()->toDateTimeString(), today()->addYear()->toDateTimeString());

    $this->get(route('turnos-asignados.list', ['situacion' => 'futuras']))
        ->assertOk()
        ->assertSee('1111111');

    $this->get(route('turnos-asignados.list', ['situacion' => 'vigentes']))
        ->assertOk()
        ->assertDontSee('1111111');
});

test('la que empieza hoy no cuenta como futura', function () {
    asignacionVigente('1111111', today()->setTime(9, 0)->toDateTimeString(), today()->addYear()->toDateTimeString());

    $this->get(route('turnos-asignados.list', ['situacion' => 'futuras']))
        ->assertOk()
        ->assertDontSee('1111111');
});

test('la búsqueda por nombre no trae a los demás funcionarios ni pierde a los suyos', function () {
    // Dos apellidos que comparten prefijo: el término tiene que cruzar por
    // coincidencia parcial, que es lo que hacía el `whereHas` que se reemplazó.
    Persona::factory()->create(['ci' => '1111111', 'paterno' => 'MOLINA', 'nombres' => 'IGNACIO']);
    Persona::factory()->create(['ci' => '2222222', 'paterno' => 'MOLINAS', 'nombres' => 'ROSA']);
    Persona::factory()->create(['ci' => '3333333', 'paterno' => 'PEREZ', 'nombres' => 'JUANA']);

    foreach (['1111111', '2222222', '3333333'] as $ci) {
        AsignacionTurno::factory()->create(['ci' => $ci, 'turno_id' => Turno::factory()->create()->id]);
    }

    $this->get(route('turnos-asignados.list', ['q' => 'MOLINA']))
        ->assertOk()
        ->assertSee('1111111')
        ->assertSee('2222222')
        ->assertDontSee('3333333');
});

test('la búsqueda de dos palabras exige las dos, cada una donde sea', function () {
    Persona::factory()->create(['ci' => '1111111', 'paterno' => 'MOLINA', 'nombres' => 'IGNACIO']);
    Persona::factory()->create(['ci' => '2222222', 'paterno' => 'MOLINA', 'nombres' => 'ROSA']);

    $turno = Turno::factory()->create(['nombreTurno' => 'LUN: 08:00 - 16:00']);

    AsignacionTurno::factory()->create(['ci' => '1111111', 'turno_id' => $turno->id]);
    AsignacionTurno::factory()->create(['ci' => '2222222', 'turno_id' => $turno->id]);

    // «MOLINA» cruza por el apellido de las dos; «IGNACIO» solo por el nombre de
    // una. Con las dos palabras tiene que quedar una sola.
    $this->get(route('turnos-asignados.list', ['q' => 'MOLINA IGNACIO']))
        ->assertOk()
        ->assertSee('1111111')
        ->assertDontSee('2222222');
});
