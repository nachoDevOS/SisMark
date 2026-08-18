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
