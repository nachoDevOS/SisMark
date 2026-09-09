<?php

use App\Models\Turno;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(asSuperAdmin());
});

function datosDeHorario(array $sobrescribir = []): array
{
    return array_merge([
        'Dia' => '2',
        'NombreTurno' => 'Turno de prueba',
        'HEntrada' => '08:00',
        'HTolerancia' => '08:10',
        'EMinima' => '07:00',
        'EMaxima' => '10:00',
        'HSalida' => '16:00',
        'STolerancia' => '16:00',
        'SMinima' => '16:00',
        'SMaxima' => '23:59',
        'HTrabajadas' => '8.00',
        'SiguienteDia' => '1',
    ], $sobrescribir);
}

/*
|--------------------------------------------------------------------------
| Listado
|--------------------------------------------------------------------------
|
| La pantalla `index` solo arma el marco: las filas las trae por AJAX la ruta
| `horarios.list`, que devuelve la tabla como vista parcial y busca por `q`.
| Por eso lo que se lista y se filtra se prueba contra `list` y no contra
| `index`.
*/

test('la pantalla del listado abre', function () {
    $this->get(route('horarios.index'))
        ->assertOk()
        ->assertSee('Buscar por nombre del turno');
});

test('el listado muestra los horarios registrados', function () {
    Turno::factory()->create(['nombreTurno' => 'Turno Mañana']);

    $this->get(route('horarios.list'))
        ->assertOk()
        ->assertSee('Turno Mañana');
});

test('muestra el formulario de alta', function () {
    $this->get(route('horarios.create'))
        ->assertOk()
        ->assertSee('Nuevo turno')
        ->assertSee('Nombre del turno');
});

test('muestra la ficha de un horario', function () {
    $horario = Turno::factory()->create(['dia' => '2', 'nombreTurno' => 'LUN: 08:00 - 16:00']);

    $this->get(route('horarios.show', $horario))
        ->assertOk()
        ->assertSee('LUN: 08:00 - 16:00')
        ->assertSee('Lunes')
        ->assertSee('Entrada')
        ->assertSee('Salida');
});

test('guarda un horario nuevo con código autogenerado y redirige al listado', function () {
    $this->post(route('horarios.store'), datosDeHorario())
        ->assertRedirect(route('horarios.index'))
        ->assertSessionHas('estado');

    $this->assertDatabaseHas('turnos', [
        'dia' => '2',
        'nombreTurno' => 'Turno de prueba',
    ]);

    $horario = Turno::query()->first();
    expect(trim($horario->idTurno))->toHaveLength(3)
        ->and($horario->hEntrada->format('H:i'))->toBe('08:00')
        ->and($horario->siguienteDia)->toBeTrue();
});

test('el alta valida los campos obligatorios', function () {
    $this->post(route('horarios.store'), [])
        ->assertSessionHasErrors(['Dia', 'NombreTurno', 'HEntrada', 'HSalida', 'HTrabajadas']);

    $this->assertDatabaseCount('turnos', 0);
});

test('el alta rechaza una hora mal formada', function () {
    $this->post(route('horarios.store'), datosDeHorario(['HEntrada' => '25:99']))
        ->assertSessionHasErrors('HEntrada');
});

test('el listado filtra por nombre del horario', function () {
    Turno::factory()->create(['nombreTurno' => 'LUN: 08:00 - 16:00']);
    Turno::factory()->create(['nombreTurno' => 'MAR: 14:00 - 22:00']);

    $this->get(route('horarios.list', ['q' => '08:00']))
        ->assertOk()
        ->assertSee('LUN: 08:00 - 16:00')
        ->assertDontSee('MAR: 14:00 - 22:00');
});

test('el listado filtra por día', function () {
    Turno::factory()->create(['dia' => '2', 'nombreTurno' => 'Turno del lunes']);
    Turno::factory()->create(['dia' => '3', 'nombreTurno' => 'Turno del martes']);

    $this->get(route('horarios.list', ['dia' => '2']))
        ->assertOk()
        ->assertSee('Turno del lunes')
        ->assertDontSee('Turno del martes');
});

test('el listado distingue con una etiqueta los horarios sugeridos', function () {
    Turno::factory()->create(['nombreTurno' => 'El sugerido', 'sugerido' => true]);

    $this->get(route('horarios.list'))
        ->assertOk()
        ->assertSee('El sugerido')
        ->assertSee('Sugerido');
});

test('el listado no pone la etiqueta cuando ningún horario está marcado', function () {
    Turno::factory()->count(3)->create(['sugerido' => false]);

    $this->get(route('horarios.list'))
        ->assertOk()
        ->assertDontSee('>Sugerido<', false);
});

test('el listado filtra por horario sugerido', function () {
    Turno::factory()->create(['nombreTurno' => 'El sugerido', 'sugerido' => true]);
    Turno::factory()->create(['nombreTurno' => 'El comun', 'sugerido' => false]);

    $this->get(route('horarios.list', ['sugerido' => '1']))
        ->assertOk()
        ->assertSee('El sugerido')
        ->assertDontSee('El comun');

    $this->get(route('horarios.list', ['sugerido' => '0']))
        ->assertOk()
        ->assertSee('El comun')
        ->assertDontSee('El sugerido');
});

test('un valor raro en el filtro de sugeridos no filtra nada', function () {
    Turno::factory()->create(['nombreTurno' => 'El sugerido', 'sugerido' => true]);
    Turno::factory()->create(['nombreTurno' => 'El comun', 'sugerido' => false]);

    // Cualquier cosa que no sea «0» ni «1» se trata como «sin filtrar», así un
    // valor manipulado por la URL no cambia el listado por un camino no previsto.
    $this->get(route('horarios.list', ['sugerido' => 'todos']))
        ->assertOk()
        ->assertSee('El sugerido')
        ->assertSee('El comun');
});

test('el listado pone los sugeridos primero', function () {
    // El sugerido va un día después, así que sin el orden propio saldría segundo.
    Turno::factory()->create(['dia' => '2', 'nombreTurno' => 'El comun', 'sugerido' => false]);
    Turno::factory()->create(['dia' => '3', 'nombreTurno' => 'El sugerido', 'sugerido' => true]);

    $html = $this->get(route('horarios.list'))->assertOk()->getContent();

    expect(strpos($html, 'El sugerido'))->toBeLessThan(strpos($html, 'El comun'));
});

test('el alta guarda si el horario es sugerido', function () {
    $this->post(route('horarios.store'), datosDeHorario(['Sugerido' => '1']))
        ->assertRedirect(route('horarios.index'));

    expect(Turno::query()->first()->sugerido)->toBeTrue();
});

test('sin marcar la casilla, el horario nuevo no queda sugerido', function () {
    $this->post(route('horarios.store'), datosDeHorario())
        ->assertRedirect(route('horarios.index'));

    expect(Turno::query()->first()->sugerido)->toBeFalse();
});

test('la edición puede desmarcar un horario sugerido', function () {
    $horario = Turno::factory()->create(['sugerido' => true]);

    $this->put(route('horarios.update', $horario), datosDeHorario())
        ->assertRedirect(route('horarios.index'));

    expect($horario->fresh()->sugerido)->toBeFalse();
});

test('muestra el formulario de edición con los datos actuales', function () {
    $horario = Turno::factory()->create(['nombreTurno' => 'Turno Tarde']);

    $this->get(route('horarios.edit', $horario))
        ->assertOk()
        ->assertSee('Turno Tarde');
});

test('actualiza un horario existente', function () {
    $horario = Turno::factory()->create(['nombreTurno' => 'Viejo']);

    $this->put(route('horarios.update', $horario), datosDeHorario(['NombreTurno' => 'Nuevo nombre']))
        ->assertRedirect(route('horarios.index'));

    expect(trim($horario->refresh()->nombreTurno))->toBe('Nuevo nombre');
});

test('elimina un horario (lógicamente)', function () {
    $horario = Turno::factory()->create();

    $this->delete(route('horarios.destroy', $horario), ['deleteObservacion' => 'Turno que ya no se usa.'])
        ->assertRedirect(route('horarios.index'));

    $this->assertSoftDeleted('turnos', [
        'id' => $horario->id,
        'deleteObservacion' => 'Turno que ya no se usa.',
    ]);
});

test('un invitado no puede entrar al listado', function () {
    auth()->logout();

    $this->get(route('horarios.index'))->assertRedirect();
});

test('un usuario sin permiso no puede entrar al listado', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('horarios.index'))->assertForbidden();
});
