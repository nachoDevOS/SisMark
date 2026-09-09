<?php

use App\Models\AsignacionTurno;
use App\Models\Equipo;
use App\Models\Turno;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// `usuarioCon()` vive en tests/Pest.php: la comparten esta suite y la de
// «Tokens de API», que prueba lo mismo sobre su propio módulo.

test('el escritorio exige su propio permiso', function () {
    // Antes no pedía ninguno: lo veía cualquiera con sesión abierta.
    $this->actingAs(usuarioCon(['ViewAny:Persona']))
        ->get(route('dashboard'))
        ->assertForbidden();

    $this->actingAs(usuarioCon(['ViewAny:Escritorio']))
        ->get(route('dashboard'))
        ->assertOk();
});

test('los turnos asignados ya no salen con el permiso de los turnos', function () {
    // Antes `AsignacionTurnoPolicy` reutilizaba `*:DiaTurno`, así que dar el
    // catálogo de turnos daba también a quién está asignado cada uno.
    $this->actingAs(usuarioCon(['ViewAny:DiaTurno']))
        ->get(route('turnos-asignados.index'))
        ->assertForbidden();

    $this->actingAs(usuarioCon(['ViewAny:AsignacionTurno']))
        ->get(route('turnos-asignados.index'))
        ->assertOk();
});

test('los turnos siguen viéndose sin el permiso de los turnos asignados', function () {
    $this->actingAs(usuarioCon(['ViewAny:DiaTurno']))
        ->get(route('horarios.index'))
        ->assertOk();
});

test('los reportes ya no salen con el permiso de las marcaciones', function () {
    $this->actingAs(usuarioCon(['ViewAny:Asistencia']))
        ->get(route('reportes.marcaciones.procesado'))
        ->assertForbidden();

    $this->actingAs(usuarioCon(['ViewAny:Reporte']))
        ->get(route('reportes.marcaciones.procesado'))
        ->assertOk();
});

test('descargar el reporte exige el permiso de exportar', function () {
    $ruta = route('reportes.marcaciones.sin-procesar.generar', [
        'persona' => '7633685',
        'desde' => '2026-08-01',
        'hasta' => '2026-08-10',
        'print' => 2,
    ]);

    $this->actingAs(usuarioCon(['ViewAny:Reporte']))
        ->get($ruta)
        ->assertForbidden();

    // Con el permiso pasa el control; después redirige porque la cédula no
    // existe, que es otra cosa y no un 403.
    $this->actingAs(usuarioCon(['ViewAny:Reporte', 'Export:Reporte']))
        ->get($ruta)
        ->assertRedirect(route('reportes.marcaciones.sin-procesar'));
});

test('la bitácora ya no sale con el permiso de los biométricos', function () {
    $this->actingAs(usuarioCon(['ViewAny:Equipo']))
        ->get(route('equipos.auditoria'))
        ->assertForbidden();

    $this->actingAs(usuarioCon(['ViewAny:EquipoAuditoria']))
        ->get(route('equipos.auditoria'))
        ->assertOk();
});

test('vaciar el reloj exige un permiso aparte de dar de baja el equipo', function () {
    $equipo = Equipo::factory()->create();
    $ruta = route('equipos.marcaciones.limpiar', $equipo);
    $motivo = ['motivo' => 'Prueba de mantenimiento del reloj'];

    // Borrar el historial del aparato es irreversible: no alcanza con poder
    // dar de baja el equipo, que sí se puede revertir.
    $this->actingAs(usuarioCon(['ViewAny:Equipo', 'Delete:Equipo']))
        ->post($ruta, $motivo)
        ->assertForbidden();

    $this->actingAs(usuarioCon(['ViewAny:Equipo', 'Clear:Equipo']))
        ->post($ruta, $motivo)
        ->assertRedirect();
});

test('sincronizar el reloj exige un permiso aparte de editar el equipo', function () {
    $equipo = Equipo::factory()->create();
    $ruta = route('equipos.marcaciones.sincronizar', $equipo);

    $this->actingAs(usuarioCon(['ViewAny:Equipo', 'Update:Equipo', 'Create:Asistencia']))
        ->post($ruta)
        ->assertForbidden();

    $this->actingAs(usuarioCon(['ViewAny:Equipo', 'Sync:Equipo']))
        ->post($ruta)
        ->assertRedirect();
});

test('el menú lateral solo muestra las opciones que el rol puede abrir', function () {
    Turno::factory()->create();
    AsignacionTurno::factory()->create();

    $this->actingAs(usuarioCon(['ViewAny:Escritorio', 'ViewAny:Persona']))
        ->get(route('dashboard'))
        ->assertOk()
        // Se mira el `title` del enlace, que solo lo tiene el menú: el texto
        // suelto también aparece en los rótulos del propio escritorio.
        ->assertSee('title="Funcionarios"', escape: false)
        // Sin permiso no aparece la opción: antes se veían todas y el 403
        // llegaba recién al hacer clic.
        ->assertDontSee('title="Marcaciones"', escape: false)
        ->assertDontSee('title="Biométricos"', escape: false)
        ->assertDontSee('title="Roles"', escape: false);
});

test('la matriz de roles solo ofrece las habilidades de cada módulo', function () {
    $this->actingAs(asSuperAdmin())
        ->get(route('roles.create'))
        ->assertOk()
        // El escritorio solo se ve: no se crea ni se elimina.
        ->assertSee('value="ViewAny:Escritorio"', escape: false)
        ->assertDontSee('value="Create:Escritorio"', escape: false)
        ->assertDontSee('value="Delete:Escritorio"', escape: false)
        // Los biométricos suman sincronizar y vaciar.
        ->assertSee('value="Sync:Equipo"', escape: false)
        ->assertSee('value="Clear:Equipo"', escape: false);
});
