<?php

use App\Policies\RolePolicy;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reestructura los permisos por módulo y le conserva a cada rol el acceso que
 * ya tenía.
 *
 * Cuatro pantallas no tenían permiso propio y viajaban colgadas del de otra:
 * el Escritorio no pedía ninguno, Turnos asignados usaba el de los turnos,
 * los Reportes el de las marcaciones y la bitácora el de los equipos. Además,
 * sincronizar un reloj y vaciarle el historial —que es irreversible— compartían
 * permiso con editar y con dar de baja el equipo.
 *
 * La migración siembra los permisos nuevos y se los asigna a cada rol según lo
 * que ya tenía, para que nadie pierda acceso al desplegar. Un rol que hoy ve
 * los turnos, mañana sigue viendo los turnos asignados; el que veía las
 * marcaciones sigue entrando a los reportes.
 */
return new class extends Migration
{
    /**
     * Permiso nuevo => permiso que ya lo habilitaba de hecho.
     *
     * Cada rol que tenga el de la derecha recibe el de la izquierda.
     *
     * @var array<string, string>
     */
    private const EQUIVALENCIAS = [
        // Turnos asignados salía del catálogo de turnos.
        'ViewAny:AsignacionTurno' => 'ViewAny:DiaTurno',
        'Create:AsignacionTurno' => 'Create:DiaTurno',
        'Update:AsignacionTurno' => 'Update:DiaTurno',
        'Delete:AsignacionTurno' => 'Delete:DiaTurno',
        // Los reportes salían del listado de marcaciones.
        'ViewAny:Reporte' => 'ViewAny:Asistencia',
        'Export:Reporte' => 'ViewAny:Asistencia',
        // La bitácora salía del listado de equipos.
        'ViewAny:EquipoAuditoria' => 'ViewAny:Equipo',
        // Sincronizar pedía poder registrar marcaciones.
        'Sync:Equipo' => 'Create:Asistencia',
        // Vaciar el reloj pedía poder dar de baja el equipo.
        'Clear:Equipo' => 'Delete:Equipo',
    ];

    /**
     * Permisos que dejan de existir: ninguna pantalla los exigía.
     *
     * @var list<string>
     */
    private const OBSOLETOS = [
        'View:Asistencia', 'Update:Asistencia', 'Delete:Asistencia',
        'Create:Persona', 'Update:Persona', 'Delete:Persona',
        'View:Licencia', 'Update:Licencia',
        'View:DiaExcepcional',
        'View:User',
        'View:Role',
    ];

    public function up(): void
    {
        foreach (RolePolicy::nombresDePermiso() as $nombre) {
            Permission::firstOrCreate(['name' => $nombre, 'guard_name' => 'web']);
        }

        foreach (Role::all() as $rol) {
            $tenia = $rol->permissions->pluck('name')->all();
            $nuevos = [];

            // El Escritorio no pedía permiso: lo veía cualquiera con sesión, así
            // que todos los roles lo conservan.
            $nuevos[] = 'ViewAny:Escritorio';

            foreach (self::EQUIVALENCIAS as $nuevo => $equivalente) {
                if (in_array($equivalente, $tenia, true)) {
                    $nuevos[] = $nuevo;
                }
            }

            $rol->givePermissionTo(array_unique($nuevos));
        }

        Permission::query()->whereIn('name', self::OBSOLETOS)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Devuelve los permisos obsoletos y quita los nuevos.
     *
     * El reparto por rol no se puede deshacer con exactitud —no queda registro
     * de si un rol tenía el permiso antes de la migración o se lo dio ella—, así
     * que la vuelta atrás borra los permisos nuevos por completo. Los roles
     * quedan como estaban en todo lo demás.
     */
    public function down(): void
    {
        foreach (self::OBSOLETOS as $nombre) {
            Permission::firstOrCreate(['name' => $nombre, 'guard_name' => 'web']);
        }

        Permission::query()->whereIn('name', [
            ...array_keys(self::EQUIVALENCIAS),
            'ViewAny:Escritorio',
        ])->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
