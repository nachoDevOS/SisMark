<?php

namespace App\Services;

use App\Models\User;

/**
 * Ayuda: los videos tutoriales que abre el botón «Ayuda» de la barra superior.
 * Cada uno muestra un trámite y se ofrece solo a quien tiene los permisos para
 * hacerlo.
 *
 * Los archivos viven en resources/manual/ y no en public/: muestran el sistema
 * por dentro. Cómo se graban, en docs/manual/README.md.
 */
class ManualUsuario
{
    /**
     * Los capítulos llevan el segundo del video donde empieza cada paso.
     *
     * @var array<string, array{titulo: string, descripcion: string, modulo: string, permisos: list<string>, capitulos: list<array{segundo: int, titulo: string}>}>
     */
    public const TUTORIALES = [
        'localizar-funcionario' => [
            'titulo' => 'Localizar a un funcionario y ver su ficha',
            'descripcion' => 'Cómo encontrar a una persona por su cédula o nombre y revisar sus datos, marcaciones, licencias y horarios.',
            'modulo' => 'Funcionarios',
            'permisos' => ['ViewAny:Persona', 'View:Persona'],
            'capitulos' => [
                ['segundo' => 0, 'titulo' => 'Presentación'],
                ['segundo' => 4, 'titulo' => 'Entrar a Funcionarios'],
                ['segundo' => 13, 'titulo' => 'Buscar por cédula o nombre'],
                ['segundo' => 26, 'titulo' => 'Abrir la ficha'],
                ['segundo' => 39, 'titulo' => 'Datos personales'],
                ['segundo' => 44, 'titulo' => 'Contrato vigente'],
                ['segundo' => 49, 'titulo' => 'Marcaciones'],
                ['segundo' => 63, 'titulo' => 'Licencias'],
                ['segundo' => 70, 'titulo' => 'Turnos y horarios'],
                ['segundo' => 77, 'titulo' => 'Asistencia procesada'],
                ['segundo' => 82, 'titulo' => 'Volver al listado'],
            ],
        ],
        'crear-y-asignar-turno' => [
            'titulo' => 'Crear un turno y asignárselo a quien no tiene',
            'descripcion' => 'Cómo registrar la jornada de la semana de un turno nuevo, encontrar a los funcionarios con contrato y sin turno, y asignárselo.',
            'modulo' => 'Turnos',
            'permisos' => ['Create:Turno', 'ViewAny:Persona', 'Create:AsignacionTurno'],
            'capitulos' => [
                ['segundo' => 0, 'titulo' => 'Presentación'],
                ['segundo' => 4, 'titulo' => 'Entrar a Turnos'],
                ['segundo' => 18, 'titulo' => 'Días y horario'],
                ['segundo' => 40, 'titulo' => 'Revisar la semana'],
                ['segundo' => 45, 'titulo' => 'Nombre del turno'],
                ['segundo' => 64, 'titulo' => 'Buscar a quien no tiene turno'],
                ['segundo' => 93, 'titulo' => 'Asignar el turno'],
                ['segundo' => 107, 'titulo' => 'Fechas'],
                ['segundo' => 121, 'titulo' => 'Listo'],
            ],
        ],
        'cambiar-turno' => [
            'titulo' => 'Cambiar de turno: concluir el actual y asignar otro',
            'descripcion' => 'Cómo cerrar el turno que tiene un funcionario desde su ficha y asignarle el nuevo, que sigue desde el día siguiente sin dejar días sueltos.',
            'modulo' => 'Turnos',
            'permisos' => ['ViewAny:Persona', 'View:Persona', 'ViewAny:AsignacionTurno', 'Update:AsignacionTurno', 'Create:AsignacionTurno'],
            'capitulos' => [
                ['segundo' => 0, 'titulo' => 'Presentación'],
                ['segundo' => 5, 'titulo' => 'Abrir su ficha'],
                ['segundo' => 20, 'titulo' => 'Concluir el turno'],
                ['segundo' => 46, 'titulo' => 'Asignar el turno nuevo'],
                ['segundo' => 78, 'titulo' => 'Listo'],
            ],
        ],
        'licencia-desde-ficha' => [
            'titulo' => 'Registrar una licencia desde la ficha',
            'descripcion' => 'Cómo anotar un permiso por horas a un funcionario desde su ficha, sin salir de ella, y cómo aprobarlo para que justifique la ausencia.',
            'modulo' => 'Licencias',
            'permisos' => ['ViewAny:Persona', 'View:Persona', 'Create:Licencia'],
            'capitulos' => [
                ['segundo' => 0, 'titulo' => 'Presentación'],
                ['segundo' => 4, 'titulo' => 'Abrir su ficha'],
                ['segundo' => 26, 'titulo' => 'Registrar la licencia'],
                ['segundo' => 35, 'titulo' => 'Fecha y horas'],
                ['segundo' => 56, 'titulo' => 'Motivo'],
                ['segundo' => 73, 'titulo' => 'Aprobarla'],
                ['segundo' => 93, 'titulo' => 'Listo'],
            ],
        ],
        'licencia-desde-licencias' => [
            'titulo' => 'Licenciar desde la pantalla Licencias',
            'descripcion' => 'Cómo anotar una licencia eligiendo cuándo y a quién (uno, varios, una dirección o todos), y cómo aprobar las pendientes desde el listado.',
            'modulo' => 'Licencias',
            'permisos' => ['ViewAny:Licencia', 'Create:Licencia'],
            'capitulos' => [
                ['segundo' => 0, 'titulo' => 'Presentación'],
                ['segundo' => 5, 'titulo' => 'Nueva licencia'],
                ['segundo' => 14, 'titulo' => 'Cuándo'],
                ['segundo' => 24, 'titulo' => 'A quién'],
                ['segundo' => 41, 'titulo' => 'Tipo y motivo'],
                ['segundo' => 65, 'titulo' => 'Aprobarla'],
                ['segundo' => 86, 'titulo' => 'Listo'],
            ],
        ],
        'reporte-procesado' => [
            'titulo' => 'Reporte procesado de un funcionario',
            'descripcion' => 'Cómo sacar la asistencia de un funcionario día por día en un rango —entradas, salidas, atrasos, faltas y licencias— e imprimirla o bajarla en Excel.',
            'modulo' => 'Reportes',
            'permisos' => ['ViewAny:Reporte'],
            'capitulos' => [
                ['segundo' => 0, 'titulo' => 'Presentación'],
                ['segundo' => 4, 'titulo' => 'Entrar al reporte'],
                ['segundo' => 17, 'titulo' => 'Elegir funcionario y fechas'],
                ['segundo' => 37, 'titulo' => 'Leer el reporte'],
                ['segundo' => 51, 'titulo' => 'Imprimir o Excel'],
                ['segundo' => 55, 'titulo' => 'Listo'],
            ],
        ],
        'reporte-direccion' => [
            'titulo' => 'Reporte por dirección',
            'descripcion' => 'Cómo sacar los totales de asistencia de todos los funcionarios de una dirección o unidad en un rango, e imprimirlos o bajarlos en Excel.',
            'modulo' => 'Reportes',
            'permisos' => ['ViewAny:Reporte'],
            'capitulos' => [
                ['segundo' => 0, 'titulo' => 'Presentación'],
                ['segundo' => 5, 'titulo' => 'Entrar al reporte'],
                ['segundo' => 16, 'titulo' => 'Dirección y unidad'],
                ['segundo' => 33, 'titulo' => 'Fechas y filtro'],
                ['segundo' => 49, 'titulo' => 'Leer el reporte'],
                ['segundo' => 57, 'titulo' => 'Imprimir o Excel'],
                ['segundo' => 61, 'titulo' => 'Listo'],
            ],
        ],
        'reporte-sin-turno' => [
            'titulo' => 'Reporte de funcionarios sin turno',
            'descripcion' => 'Cómo ver quién tiene contrato y hoy no tiene turno ni horario —y por eso no se le controla la asistencia— para asignárselo.',
            'modulo' => 'Reportes',
            'permisos' => ['ViewAny:Reporte'],
            'capitulos' => [
                ['segundo' => 0, 'titulo' => 'Presentación'],
                ['segundo' => 5, 'titulo' => 'Entrar al reporte'],
                ['segundo' => 12, 'titulo' => 'Filtrar'],
                ['segundo' => 22, 'titulo' => 'Leer el reporte'],
                ['segundo' => 32, 'titulo' => 'Imprimir o Excel'],
                ['segundo' => 37, 'titulo' => 'Listo'],
            ],
        ],
    ];

    /**
     * Los tutoriales que el usuario puede ver y cuyo video ya está grabado.
     *
     * @return array<string, array{titulo: string, descripcion: string, modulo: string, permisos: list<string>, capitulos: list<array{segundo: int, titulo: string}>}>
     */
    public static function paraUsuario(User $usuario): array
    {
        return array_filter(
            self::TUTORIALES,
            fn (string $clave): bool => self::puedeVer($usuario, $clave) && is_file(self::archivo($clave, 'mp4')),
            ARRAY_FILTER_USE_KEY,
        );
    }

    public static function puedeVer(User $usuario, string $clave): bool
    {
        $tutorial = self::TUTORIALES[$clave] ?? null;

        return $tutorial !== null
            && collect($tutorial['permisos'])->every(fn (string $permiso): bool => $usuario->can($permiso));
    }

    /**
     * La ruta del archivo del tutorial: `mp4` el video, `jpg` la portada y
     * `previa.mp4` el clip en bucle de su tarjeta (docs/manual/vista-previa.py).
     */
    public static function archivo(string $clave, string $extension): string
    {
        return resource_path("manual/{$clave}.{$extension}");
    }
}
