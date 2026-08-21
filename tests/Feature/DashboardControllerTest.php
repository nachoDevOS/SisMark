<?php

use App\Models\AsignacionTurno;
use App\Models\Asistencia;
use App\Models\Equipo;
use App\Models\Turno;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(asSuperAdmin());
});

/**
 * Marcación de una persona en una fecha y hora concretas. La hora va sobre la
 * fecha base 1899-12-30, como en toda la tabla.
 */
function marcar(string $ci, string $hora, ?Carbon $fecha = null): Asistencia
{
    return Asistencia::factory()->create([
        'ci' => $ci,
        'fecha' => ($fecha ?? today())->copy()->startOfDay(),
        'hora' => $hora,
    ]);
}

test('muestra los conteos de equipos', function () {
    Equipo::factory()->create(['en_linea' => true]);
    Equipo::factory()->create(['en_linea' => false]);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Equipos en línea')
        ->assertSee('1/2');
});

test('lista solo los equipos activos fuera de línea', function () {
    Equipo::factory()->create(['nombre' => 'Reloj Caído', 'en_linea' => false, 'activo' => true]);
    Equipo::factory()->create(['nombre' => 'Reloj Sano', 'en_linea' => true, 'activo' => true]);
    Equipo::factory()->create(['nombre' => 'Reloj Retirado', 'en_linea' => false, 'activo' => false]);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Reloj Caído')
        ->assertDontSee('Reloj Retirado');
});

test('muestra el estado vacío cuando no hay equipos fuera de línea', function () {
    Equipo::factory()->create(['en_linea' => true, 'activo' => true]);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Todos los equipos están en línea');
});

test('cuenta las marcaciones y las personas del día', function () {
    marcar('111', '08:00:00');
    marcar('111', '16:00:00');
    marcar('222', '08:05:00');
    marcar('333', '08:00:00', today()->subDay());

    $respuesta = $this->get(route('dashboard'))->assertOk();

    $respuesta->assertSee('Marcaciones por hora');
    // 3 marcaciones de hoy, de 2 personas distintas. La de ayer no cuenta.
    expect($respuesta->getContent())
        ->toContain('<dd>3</dd>')
        ->toContain('<dd>2</dd>');
});

test('el histograma reparte las marcaciones de hoy por hora', function () {
    marcar('111', '08:15:00');
    marcar('222', '08:40:00');
    marcar('333', '16:05:00');

    $respuesta = $this->get(route('dashboard'))->assertOk();

    // Dos a las 08 y una a las 16; el pico es la hora 08.
    $respuesta->assertSee('08:00 · 2 marcación(es)', false);
    $respuesta->assertSee('16:00 · 1 marcación(es)', false);
    $respuesta->assertSee('pico 08:00');
});

test('reparte en horas y días el tiempo desde la última marcación', function () {
    // La hora se congela: la antigüedad se muestra con `intdiv($minutos, 1440)`,
    // así que una marcación de las 08:00 de hace tres días son «3 d» solo si
    // ahora es más tarde que las 08:00. Corriendo la prueba de madrugada daban
    // 2 d 23 h y decía «2 d».
    $this->travelTo(Carbon::parse('2026-08-20 16:00:00'));

    // Con la última marcación de hace días, la antigüedad deja de contarse en
    // minutos sueltos: `diffInMinutes()` devuelve float y el reparto en horas
    // usa intdiv(), que con un float aborta la petición.
    marcar('111', '08:00:00', today()->subDays(3));

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Desde la última marcación')
        ->assertSee('3 d');
});

test('avisa cuando todavía no entró ninguna marcación hoy', function () {
    marcar('111', '08:00:00', today()->subDay());

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Todavía no entró ninguna marcación hoy');
});

test('el gráfico de tendencia dibuja 30 barras, una por día', function () {
    $respuesta = $this->get(route('dashboard'))->assertOk();

    // 30 de la tendencia + 24 del histograma horario.
    expect(substr_count($respuesta->getContent(), 'mini-chart__barra'))->toBeGreaterThanOrEqual(30);
    $respuesta->assertSee('últimos 30 días');
});

test('avisa que no hay turnos asignados y deja en blanco lo que depende de ellos', function () {
    marcar('111', '08:00:00');

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('No hay turnos asignados a ningún funcionario')
        ->assertSee('quedan en blanco hasta que se carguen');
});

test('con turnos asignados cuenta quiénes tenían que marcar y quiénes no lo hicieron', function () {
    // Turno del día de la semana de hoy (1 = domingo … 7 = sábado).
    $turno = Turno::factory()->create(['dia' => (string) (today()->dayOfWeek + 1)]);

    foreach (['111', '222', '333'] as $ci) {
        AsignacionTurno::factory()->create([
            'ci' => $ci,
            'turno_id' => $turno->id,
            'desde' => today()->subMonth(),
            'hasta' => today()->addMonth(),
        ]);
    }

    // Solo dos de los tres marcaron.
    marcar('111', '08:00:00');
    marcar('222', '08:10:00');

    $respuesta = $this->get(route('dashboard'))->assertOk();

    $respuesta->assertDontSee('No hay turnos asignados a ningún funcionario');
    $respuesta->assertSee('Aún sin marcar');
    // 3 con turno, 1 sin marcar.
    expect($respuesta->getContent())
        ->toContain('<dd>3</dd>')
        ->toContain('<dd>1</dd>');
});

test('la portada no cuenta la tabla entera: el panel de calidad se pide aparte', function () {
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Contando marcaciones…')
        ->assertSee('id="panel-calidad"', false)
        // `@json()` escapa las barras, así que la URL viaja como
        // «escritorio\/ajax\/calidad».
        ->assertSee('escritorio\/ajax\/calidad', false);
});

test('el panel de calidad señala las fechas imposibles', function () {
    marcar('111', '08:00:00', today()->subYears(30));   // anterior al 2000
    marcar('222', '08:00:00', today()->addYears(40));   // fecha futura del reloj
    marcar('333', '08:00:00');

    $respuesta = $this->get(route('dashboard.calidad'))->assertOk();

    $respuesta->assertSee('Con fecha anterior al 2000');
    $respuesta->assertSee('Con fecha futura (reloj desajustado)');
    // Una de cada una, y las tres en el total.
    expect($respuesta->getContent())
        ->toContain('<dd>3</dd>')
        ->toContain('<dd>1</dd>');
});

test('un invitado no puede pedir el panel de calidad', function () {
    auth()->logout();

    $this->get(route('dashboard.calidad'))->assertRedirect();
});

test('el escritorio ya no habla del SIA', function () {
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Sin conexión')
        ->assertDontSee('servidor SIA');
});

test('un invitado es redirigido al intentar ver el escritorio', function () {
    auth()->logout();

    $this->get(route('dashboard'))->assertRedirect();
});
