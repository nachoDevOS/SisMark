<?php

use App\Models\Licencia;
use App\Models\User;
use Illuminate\Support\Carbon;

/*
| Cada fila del listado de licencias trae debajo un detalle desplegable: quién
| la registró y cuándo, el horario, el alcance y la revisión.
*/
beforeEach(function (): void {
    Carbon::setTestNow('2026-10-20');
});

test('el detalle muestra quién la registró, cuándo, y quién la revisó', function (): void {
    $registrador = User::factory()->create(['name' => 'ANA REGISTRADORA']);
    $revisor = User::factory()->create(['name' => 'LUIS REVISOR']);

    // El autor lo pone el trait de auditoría con el usuario en sesión.
    $this->actingAs($registrador);
    Licencia::factory()->create([
        'ci' => '111', 'fecha' => '2026-10-12', 'tipo' => Licencia::TIPO_INSTITUCIONAL,
        'fechaPedido' => '2026-10-11 14:35:00', 'motivo' => 'Acto cívico',
        'estado' => Licencia::APROBADO, 'revisadoPor_id' => $revisor->id,
        'revisadoEn' => '2026-10-11 16:05:00', 'observacion' => 'Conforme',
    ]);

    $this->actingAs(superAdmin())
        ->get(route('licencias.list'))
        ->assertOk()
        ->assertSee('class="licencia-detalle" hidden', false)
        ->assertSee('Ver más información')
        ->assertSeeInOrder(['Registrado por', 'ANA REGISTRADORA', 'Fecha y hora del registro', '11/10/2026 14:35'])
        ->assertSeeInOrder(['Revisado por', 'LUIS REVISOR', 'Fecha y hora de la revisión', '11/10/2026 16:05', 'Observación', 'Conforme']);
});

test('lo pendiente dice que todavía no se revisó y lo del SIA sin hora muestra solo la fecha', function (): void {
    Licencia::factory()->delSia()->create([
        'ci' => '222', 'fecha' => '2026-10-12', 'tipo' => Licencia::TIPO_INSTITUCIONAL,
        'fechaPedido' => '2026-10-10 00:00:00', 'estado' => Licencia::PENDIENTE, 'usuario' => 'OPERADOR SIA',
    ]);

    $this->actingAs(superAdmin())
        ->get(route('licencias.list'))
        ->assertOk()
        ->assertSeeInOrder(['Registrado por', 'OPERADOR SIA', 'Fecha y hora del registro', '10/10/2026'])
        ->assertDontSee('10/10/2026 00:00')
        ->assertSee('Todavía sin revisar');
});
