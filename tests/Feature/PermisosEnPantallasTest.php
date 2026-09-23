<?php

use App\Models\DiaExcepcional;
use App\Models\Equipo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/*
| Los controladores ya rechazaban con 403 lo que el rol no permite, pero las
| pantallas dibujaban igual los botones: quitarle a un rol «Vaciar» en
| Biométricos no le sacaba el «Borrar marcaciones» del menú. Estas pruebas
| cuidan que cada acción se muestre solo a quien la puede ejecutar.
*/

test('sin el permiso de vaciar no se ofrece borrar las marcaciones del reloj', function () {
    $equipo = Equipo::factory()->create();

    $this->actingAs(usuarioCon(['ViewAny:Equipo', 'View:Equipo', 'Update:Equipo', 'Delete:Equipo', 'Sync:Equipo']))
        ->get(route('equipos.index'))
        ->assertOk()
        ->assertDontSee('Borrar marcaciones')
        ->assertDontSee(route('equipos.marcaciones.limpiar', $equipo), escape: false)
        ->assertSee('Eliminar equipo');

    $this->actingAs(usuarioCon(['ViewAny:Equipo', 'Clear:Equipo']))
        ->get(route('equipos.index'))
        ->assertOk()
        ->assertSee('Borrar marcaciones')
        ->assertSee(route('equipos.marcaciones.limpiar', $equipo), escape: false);
});

test('con solo ver el listado de biométricos no aparece ninguna acción', function () {
    $equipo = Equipo::factory()->create();

    $this->actingAs(usuarioCon(['ViewAny:Equipo']))
        ->get(route('equipos.index'))
        ->assertOk()
        ->assertSee($equipo->nombre)
        ->assertDontSee(route('equipos.create'), escape: false)
        ->assertDontSee(route('equipos.auditoria'), escape: false)
        ->assertDontSee(route('equipos.edit', $equipo), escape: false)
        ->assertDontSee(route('equipos.show', $equipo), escape: false)
        ->assertDontSee(route('equipos.probar-conexion', $equipo), escape: false)
        ->assertDontSee(route('equipos.marcaciones.exportar', $equipo), escape: false)
        ->assertDontSee(route('equipos.marcaciones.sincronizar', $equipo), escape: false)
        ->assertDontSee(route('equipos.destroy', $equipo), escape: false);
});

test('cada opción de biométricos aparece con su propio permiso', function (string $permiso, string $ruta) {
    $equipo = Equipo::factory()->create();

    $this->actingAs(usuarioCon(['ViewAny:Equipo', $permiso]))
        ->get(route('equipos.index'))
        ->assertOk()
        ->assertSee(route($ruta, $equipo), escape: false);
})->with([
    'ficha' => ['View:Equipo', 'equipos.show'],
    'exportar' => ['View:Equipo', 'equipos.marcaciones.exportar'],
    'editar' => ['Update:Equipo', 'equipos.edit'],
    'probar conexión' => ['Update:Equipo', 'equipos.probar-conexion'],
    'sincronizar' => ['Sync:Equipo', 'equipos.marcaciones.sincronizar'],
    'eliminar' => ['Delete:Equipo', 'equipos.destroy'],
]);

test('vaciar el reloj sin el permiso sigue rechazándose en el servidor', function () {
    $equipo = Equipo::factory()->create();

    $this->actingAs(usuarioCon(['ViewAny:Equipo', 'Delete:Equipo']))
        ->post(route('equipos.marcaciones.limpiar', $equipo), ['motivo' => 'Memoria llena del equipo'])
        ->assertForbidden();
});

test('el botón de alta de cada módulo solo sale con el permiso de crear', function (string $modulo, string $listado, string $alta) {
    $this->actingAs(usuarioCon(["ViewAny:{$modulo}"]))
        ->get(route($listado))
        ->assertOk()
        ->assertDontSee(route($alta), escape: false);

    $this->actingAs(usuarioCon(["ViewAny:{$modulo}", "Create:{$modulo}"]))
        ->get(route($listado))
        ->assertOk()
        ->assertSee(route($alta), escape: false);
})->with([
    'días excepcionales' => ['DiaExcepcional', 'dias-excepcionales.index', 'dias-excepcionales.create'],
    'turnos' => ['DiaTurno', 'horarios.index', 'horarios.create'],
    'licencias' => ['Licencia', 'licencias.index', 'licencias.create'],
    'usuarios' => ['User', 'usuarios.index', 'usuarios.create'],
    'roles' => ['Role', 'roles.index', 'roles.create'],
]);

test('los días excepcionales no ofrecen editar ni eliminar sin esos permisos', function () {
    $dia = DiaExcepcional::factory()->create();

    $this->actingAs(usuarioCon(['ViewAny:DiaExcepcional']))
        ->get(route('dias-excepcionales.list'))
        ->assertOk()
        ->assertDontSee(route('dias-excepcionales.edit', $dia), escape: false)
        ->assertDontSee(route('dias-excepcionales.destroy', $dia), escape: false);

    $this->actingAs(usuarioCon(['ViewAny:DiaExcepcional', 'Update:DiaExcepcional', 'Delete:DiaExcepcional']))
        ->get(route('dias-excepcionales.list'))
        ->assertOk()
        ->assertSee(route('dias-excepcionales.edit', $dia), escape: false)
        ->assertSee(route('dias-excepcionales.destroy', $dia), escape: false);
});

test('los usuarios no ofrecen editar ni eliminar sin esos permisos', function () {
    $otro = User::factory()->create();

    $this->actingAs(usuarioCon(['ViewAny:User']))
        ->get(route('usuarios.index'))
        ->assertOk()
        ->assertDontSee(route('usuarios.edit', $otro), escape: false)
        ->assertDontSee(route('usuarios.destroy', $otro), escape: false);
});

test('los roles no ofrecen editar ni eliminar sin esos permisos', function () {
    $rol = Role::create(['name' => 'consulta', 'guard_name' => 'web']);

    $this->actingAs(usuarioCon(['ViewAny:Role']))
        ->get(route('roles.index'))
        ->assertOk()
        ->assertDontSee(route('roles.edit', $rol), escape: false)
        ->assertDontSee(route('roles.destroy', $rol), escape: false);
});

test('sin el escritorio el inicio lleva a la primera pantalla permitida', function () {
    $this->actingAs(usuarioCon(['ViewAny:Equipo']))
        ->get(route('dashboard'))
        ->assertRedirect(route('equipos.index'));

    $this->actingAs(usuarioCon([]))
        ->get(route('dashboard'))
        ->assertForbidden();

    $this->actingAs(usuarioCon(['ViewAny:Escritorio']))
        ->get(route('dashboard'))
        ->assertOk();
});

test('la ficha de mamoré exige ver la ficha y no solo el listado', function () {
    fakeMamore(['7633685' => 'Juan Pérez']);

    $this->actingAs(usuarioCon(['ViewAny:Persona']))
        ->get(route('funcionarios.mamore', ['ci' => '7633685']))
        ->assertForbidden();

    $this->actingAs(usuarioCon(['ViewAny:Persona', 'View:Persona']))
        ->get(route('funcionarios.mamore', ['ci' => '7633685']))
        ->assertOk();
});

test('quitarle un permiso a un rol rige en el pedido siguiente', function () {
    $usuario = usuarioCon(['ViewAny:Equipo', 'Clear:Equipo']);
    $equipo = Equipo::factory()->create();

    $this->actingAs($usuario)
        ->get(route('equipos.index'))
        ->assertSee(route('equipos.marcaciones.limpiar', $equipo), escape: false);

    $usuario->roles->first()->revokePermissionTo('Clear:Equipo');

    $this->actingAs($usuario->fresh())
        ->get(route('equipos.index'))
        ->assertOk()
        ->assertDontSee(route('equipos.marcaciones.limpiar', $equipo), escape: false);
});

test('eliminar un rol le quita sus permisos a quien lo tenía', function () {
    $usuario = usuarioCon(['ViewAny:Equipo']);
    $rol = $usuario->roles->first();

    $this->actingAs($usuario)->get(route('equipos.index'))->assertOk();

    $this->actingAs(asSuperAdmin())
        ->delete(route('roles.destroy', $rol), ['deleteObservacion' => 'Rol que ya no se usa'])
        ->assertRedirect(route('roles.index'));

    $this->actingAs($usuario->fresh())
        ->get(route('equipos.index'))
        ->assertForbidden();
});
