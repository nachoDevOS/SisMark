<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Borra los permisos que ya no chequea nada del sistema.
 *
 * El seeder solo crea permisos y nunca borra, así que quedaron en la base los
 * de funciones que se fueron: los cinco de la pantalla Horarios (desde el
 * 14/10/2026 los horarios se cargan dentro del turno) y el de la descarga de
 * la actividad. No habilitan nada, pero inflan el conteo de permisos de cada
 * rol en «Roles».
 *
 * Solo se quitan estos seis: los roles, sus demás permisos y los usuarios con
 * su rol quedan como están. La lista va escrita a mano y no se calcula contra
 * la matriz de RolePolicy, para que la migración haga siempre lo mismo.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const PERMISOS = [
        'ViewAny:Horario',
        'View:Horario',
        'Create:Horario',
        'Update:Horario',
        'Delete:Horario',
        'Export:Actividad',
    ];

    public function up(): void
    {
        // Uno por uno con el modelo: Spatie suelta también su asignación a los
        // roles (`role_has_permissions`) y a usuarios sueltos.
        Permission::query()
            ->whereIn('name', self::PERMISOS)
            ->where('guard_name', 'web')
            ->get()
            ->each->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Vuelven a existir, pero sin rol: no habilitaban nada.
     */
    public function down(): void
    {
        foreach (self::PERMISOS as $nombre) {
            Permission::firstOrCreate(['name' => $nombre, 'guard_name' => 'web']);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
