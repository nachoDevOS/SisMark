<?php

use App\Policies\RolePolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

test('todo permiso que exige el código está en la matriz de roles, y no sobra ninguno', function (): void {
    $exigidos = collect(File::allFiles(base_path('app')))
        ->merge(File::allFiles(resource_path('views')))
        ->merge(File::allFiles(base_path('routes')))
        ->flatMap(fn ($archivo) => preg_match_all(
            "/'((?:ViewAny|View|Create|Update|Delete|Approve|Export|Sync|Import|Clear|Token):[A-Za-z]+)'/",
            $archivo->getContents(),
            $coincidencias,
        ) ? $coincidencias[1] : [])
        ->unique()
        ->sort()
        ->values();

    $definidos = collect(RolePolicy::nombresDePermiso())->sort()->values();

    expect($exigidos->diff($definidos)->values()->all())->toBe([])
        ->and($definidos->diff($exigidos)->values()->all())->toBe([]);
});

test('el seeder crea todos los permisos y se los da al super_admin', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(Permission::query()->count())->toBe(count(RolePolicy::nombresDePermiso()))
        ->and(Role::findByName('super_admin')->permissions)->toHaveCount(count(RolePolicy::nombresDePermiso()))
        ->and(Permission::query()->where('name', 'Create:AsignacionHorario')->exists())->toBeFalse();
});

test('cada pantalla pide su propio permiso', function (string $ruta, string $permiso): void {
    $this->actingAs(usuarioCon($permiso))->get(route($ruta))->assertSuccessful();
    $this->actingAs(usuarioCon('ViewAny:Licencia'))->get(route($ruta))->assertForbidden();
})->with([
    'turnos' => ['turnos.index', 'ViewAny:Turno'],
    'turnos asignados' => ['turnos-asignados.index', 'ViewAny:AsignacionTurno'],
    'horarios asignados' => ['horarios-asignados.index', 'ViewAny:AsignacionHorario'],
    'horarios' => ['horarios.index', 'ViewAny:Horario'],
]);

test('la solapa de la ficha alcanza con cualquiera de los dos permisos de listado', function (string $permiso): void {
    $this->actingAs(usuarioCon($permiso))
        ->get(route('funcionarios.horarios.list', ['ci' => '111']))
        ->assertSuccessful();
})->with(['ViewAny:AsignacionTurno', 'ViewAny:AsignacionHorario']);

test('sin ninguno de los dos, la solapa no se abre', function (): void {
    $this->actingAs(usuarioCon('ViewAny:Licencia'))
        ->get(route('funcionarios.horarios.list', ['ci' => '111']))
        ->assertForbidden();
});

test('marcar el sugerido pide Update:Turno', function (): void {
    $turno = turnoLunesAViernes();

    $this->actingAs(usuarioCon('ViewAny:Turno', 'View:Turno'))
        ->patch(route('turnos.sugerido', $turno))
        ->assertForbidden();

    $this->actingAs(usuarioCon('Update:Turno'))
        ->patch(route('turnos.sugerido', $turno))
        ->assertRedirect();

    expect($turno->fresh()->sugerido)->toBeTrue();
});

test('asignar un turno pide Create:AsignacionTurno', function (): void {
    $turno = turnoLunesAViernes();
    $datos = ['ci' => '111', 'turno_id' => $turno->id, 'desde' => '2026-10-20', 'hasta' => '2026-12-31'];

    $this->actingAs(usuarioCon('ViewAny:AsignacionTurno'))
        ->post(route('turnos-asignados.store'), $datos)
        ->assertForbidden();
});
