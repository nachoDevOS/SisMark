<?php

declare(strict_types=1);

namespace App\Policies;

use App\Http\Controllers\DashboardController;
use App\Models\Role;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Fuente única de los permisos del sistema. La usan el seeder, la matriz de
 * checkboxes de `/roles`, la validación de los Request y las demás policies.
 *
 * Convención del nombre: `Habilidad:Modulo`, separador `:`, case pascal.
 *
 * Cada módulo declara **solo las habilidades que le aplican**, y no las cinco
 * de siempre. Antes la matriz era el producto de todos los módulos por todas
 * las habilidades, y eso ofrecía casillas que no significaban nada —«Crear» en
 * el Escritorio, «Eliminar» en un reporte—: quien arma un rol no tiene cómo
 * saber cuáles hacen algo y cuáles no. Las que están acá son las que un
 * controlador realmente exige.
 */
class RolePolicy
{
    use HandlesAuthorization;

    /**
     * Etiqueta legible de cada habilidad, para la cabecera de la matriz.
     *
     * @var array<string, string>
     */
    public const HABILIDADES = [
        'ViewAny' => 'Ver listado',
        'View' => 'Ver ficha',
        'Create' => 'Crear',
        'Update' => 'Editar',
        'Delete' => 'Eliminar',
        // Resolver una solicitud (aprobarla o rechazarla). Va aparte de
        // `Update` porque no es editar el registro: una licencia aprobada
        // justifica una ausencia, y quién puede decidir eso no tiene por qué
        // ser quien puede corregir datos.
        'Approve' => 'Aprobar',
        'Export' => 'Exportar',
        'Sync' => 'Sincronizar',
        'Clear' => 'Vaciar',
    ];

    /**
     * Módulos del sistema con su etiqueta y las habilidades que admiten.
     *
     * Las claves no son todas modelos de Eloquent: `Escritorio` y `Reporte` son
     * pantallas sin tabla propia, y se autorizan por nombre de permiso en vez
     * de por policy de modelo (ver {@see DashboardController}).
     *
     * @var array<string, array{etiqueta: string, habilidades: list<string>}>
     */
    public const MODULOS = [
        'Escritorio' => [
            'etiqueta' => 'Escritorio',
            'habilidades' => ['ViewAny'],
        ],
        'Persona' => [
            'etiqueta' => 'Funcionarios',
            // Solo lectura: el alta y la baja son de los sistemas de origen.
            'habilidades' => ['ViewAny', 'View'],
        ],
        'Asistencia' => [
            'etiqueta' => 'Marcaciones',
            // `Create` cubre las tres formas de que entre una marcación:
            // registro manual, importación de CSV y sincronización del reloj.
            'habilidades' => ['ViewAny', 'Create'],
        ],
        'Reporte' => [
            'etiqueta' => 'Reportes',
            // `Export` es la descarga (CSV y Excel), que saca los datos del
            // sistema y por eso se puede dar por separado de verlos en pantalla.
            'habilidades' => ['ViewAny', 'Export'],
        ],
        'DiaTurno' => [
            'etiqueta' => 'Turnos',
            'habilidades' => ['ViewAny', 'View', 'Create', 'Update', 'Delete'],
        ],
        'AsignacionTurno' => [
            'etiqueta' => 'Turnos asignados',
            // Módulo propio: antes compartía los permisos de `DiaTurno`, así que
            // no había forma de dar los turnos sin dar también a quién están
            // asignados.
            'habilidades' => ['ViewAny', 'Create', 'Update', 'Delete'],
        ],
        'Licencia' => [
            'etiqueta' => 'Licencias',
            // Sin edición: una licencia se anota o se elimina. `View` es la
            // ficha de la solicitud, y `Approve` la decisión sobre las que
            // piden los funcionarios desde Mamoré y llegan «Pendiente».
            'habilidades' => ['ViewAny', 'View', 'Create', 'Approve', 'Delete'],
        ],
        'DiaExcepcional' => [
            'etiqueta' => 'Días excepcionales',
            'habilidades' => ['ViewAny', 'Create', 'Update', 'Delete'],
        ],
        'Equipo' => [
            'etiqueta' => 'Biométricos',
            // `Sync` y `Clear` van aparte de `Update`/`Delete` porque no son lo
            // mismo: sincronizar escribe marcaciones en la base, y vaciar borra
            // el historial entero del reloj y es irreversible.
            'habilidades' => ['ViewAny', 'View', 'Create', 'Update', 'Delete', 'Sync', 'Clear'],
        ],
        'EquipoAuditoria' => [
            'etiqueta' => 'Bitácora de biométricos',
            // Quién exportó, sincronizó, vació un reloj o dio de baja un equipo.
            // Solo se consulta: la escriben los propios controladores.
            'habilidades' => ['ViewAny'],
        ],
        'User' => [
            'etiqueta' => 'Usuarios',
            'habilidades' => ['ViewAny', 'Create', 'Update', 'Delete'],
        ],
        'Role' => [
            'etiqueta' => 'Roles',
            'habilidades' => ['ViewAny', 'Create', 'Update', 'Delete'],
        ],
    ];

    /**
     * Todos los nombres de permiso posibles, en el orden Módulo → Habilidad.
     *
     * @return list<string>
     */
    public static function nombresDePermiso(): array
    {
        $nombres = [];

        foreach (self::MODULOS as $modulo => $definicion) {
            foreach ($definicion['habilidades'] as $habilidad) {
                $nombres[] = "{$habilidad}:{$modulo}";
            }
        }

        return $nombres;
    }

    /**
     * Habilidades que admite un módulo, con su etiqueta legible.
     *
     * @return array<string, string>
     */
    public static function habilidadesDe(string $modulo): array
    {
        $habilidades = self::MODULOS[$modulo]['habilidades'] ?? [];

        return array_intersect_key(self::HABILIDADES, array_flip($habilidades));
    }

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Role');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Role');
    }

    public function update(AuthUser $authUser, Role $role): bool
    {
        return $authUser->can('Update:Role');
    }

    public function delete(AuthUser $authUser, Role $role): bool
    {
        return $authUser->can('Delete:Role');
    }
}
