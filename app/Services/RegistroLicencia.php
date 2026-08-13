<?php

namespace App\Services;

use App\Models\AsignacionTurno;
use App\Models\Licencia;
use App\Models\Turno;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Anota licencias expandiendo un rango de fechas contra los turnos asignados de
 * cada funcionario: una fila de `licencias` por cada día del rango cuyo día de
 * la semana coincida con el turno y caiga dentro de su vigencia.
 *
 * Trabaja siempre en lote (uno o muchos funcionarios): los feriados alcanzan a
 * más de 400 personas, así que la existencia se consulta de una sola vez y las
 * altas van con `insert()` por bloques, en vez de una consulta por fila.
 *
 * La clave natural de la tabla es ci + fecha + turno_id, así que un mismo día y
 * turno nunca se duplica: si ya existe se informa, y si estaba eliminado
 * lógicamente se restaura con los datos nuevos. El código de turno del SIA
 * (`idTurno`) no se escribe: el horario va por la FK.
 */
class RegistroLicencia
{
    /**
     * Tope de días del rango, para que un error de tipeo en las fechas no genere
     * miles de filas.
     */
    public const MAX_DIAS = 366;

    /**
     * Filas por bloque de inserción.
     */
    private const LOTE = 500;

    /**
     * Anota las licencias y devuelve el conteo por resultado.
     *
     * @param  Collection<string, Collection<int, AsignacionTurno>>  $asignacionesPorCi  turnos a licenciar, agrupados por carnet
     * @param  array{tCompleto: bool, goceHaberes: bool, motivo: string, lEntra: ?string, lSale: ?string, adjunto?: ?string, adjuntoNombre?: ?string, usuario: string, usuarioId: ?int}  $datos
     * @return array{creadas: int, existentes: int, fueraDeVigencia: int, sinTurno: int, funcionarios: int}
     */
    public function anotar(Collection $asignacionesPorCi, Carbon $desde, Carbon $hasta, array $datos): array
    {
        $conteo = ['creadas' => 0, 'existentes' => 0, 'fueraDeVigencia' => 0, 'sinTurno' => 0, 'funcionarios' => 0];

        if ($asignacionesPorCi->isEmpty()) {
            return $conteo;
        }

        $completo = (bool) $datos['tCompleto'];
        $ahora = now();
        $comunes = [
            'fechaPedido' => $ahora,
            'usuario' => mb_substr($datos['usuario'], 0, 50),
            'lEntra' => $completo ? null : self::sobreFechaBase($datos['lEntra'] ?? null),
            'lSale' => $completo ? null : self::sobreFechaBase($datos['lSale'] ?? null),
            'tCompleto' => $completo,
            'motivo' => $datos['motivo'],
            'goceHaberes' => (bool) $datos['goceHaberes'],
            // El respaldo es uno solo para todo el alta: la misma ruta se copia
            // a cada fila del rango, así se llega al archivo desde cualquiera
            // de ellas sin un join en el listado.
            'adjunto' => $datos['adjunto'] ?? null,
            'adjuntoNombre' => $datos['adjuntoNombre'] ?? null,
        ];

        $candidatos = $this->candidatos($asignacionesPorCi, $desde, $hasta, $conteo);

        if ($candidatos === []) {
            return $conteo;
        }

        $existentes = $this->existentes($asignacionesPorCi->keys()->all(), $desde, $hasta);

        $aInsertar = [];
        $aRestaurar = [];
        $alcanzados = [];

        foreach ($candidatos as $clave => $candidato) {
            $existente = $existentes->get($clave);

            if ($existente && $existente->deleted_at === null) {
                $conteo['existentes']++;

                continue;
            }

            $alcanzados[$candidato['ci']] = true;

            if ($existente) {
                $aRestaurar[] = $existente->id;

                continue;
            }

            // insert() no dispara eventos de modelo, así que el autor y los
            // timestamps que normalmente pone el trait de auditoría van a mano.
            $aInsertar[] = $candidato + $comunes + [
                'created_at' => $ahora,
                'updated_at' => $ahora,
                'registerUser_id' => $datos['usuarioId'] ?? null,
            ];
        }

        foreach (array_chunk($aInsertar, self::LOTE) as $bloque) {
            Licencia::insert($bloque);
        }

        foreach (array_chunk($aRestaurar, self::LOTE) as $bloque) {
            Licencia::withTrashed()->whereIn('id', $bloque)->update($comunes + [
                'deleted_at' => null,
                'deleteUser_id' => null,
                'deleteObservacion' => null,
                'updated_at' => $ahora,
            ]);
        }

        $conteo['creadas'] = count($aInsertar) + count($aRestaurar);
        $conteo['funcionarios'] = count($alcanzados);

        return $conteo;
    }

    /**
     * Pares (ci, fecha, turno) que corresponde licenciar, ya deduplicados por la
     * clave natural: dos asignaciones al mismo turno y día son una sola licencia.
     *
     * @param  Collection<string, Collection<int, AsignacionTurno>>  $asignacionesPorCi
     * @param  array{creadas: int, existentes: int, fueraDeVigencia: int, sinTurno: int, funcionarios: int}  $conteo
     * @return array<string, array{ci: string, fecha: string, turno_id: int}>
     */
    private function candidatos(Collection $asignacionesPorCi, Carbon $desde, Carbon $hasta, array &$conteo): array
    {
        $candidatos = [];
        $fin = $hasta->copy()->startOfDay();

        foreach ($asignacionesPorCi as $ci => $asignaciones) {
            $fecha = $desde->copy()->startOfDay();

            while ($fecha->lessThanOrEqualTo($fin)) {
                foreach ($asignaciones as $asignacion) {
                    $turno = $asignacion->turno;

                    if (! $turno instanceof Turno) {
                        $conteo['sinTurno']++;

                        continue;
                    }

                    if ((int) $turno->dia !== self::diaSia($fecha)) {
                        continue;
                    }

                    if (! self::dentroDeVigencia($asignacion, $fecha)) {
                        $conteo['fueraDeVigencia']++;

                        continue;
                    }

                    $candidatos[self::clave((string) $ci, $fecha->toDateString(), (int) $turno->id)] = [
                        'ci' => (string) $ci,
                        'fecha' => $fecha->toDateString(),
                        'turno_id' => (int) $turno->id,
                    ];
                }

                $fecha->addDay();
            }
        }

        return $candidatos;
    }

    /**
     * Licencias que ya existen para esos funcionarios en el rango, indexadas por
     * la clave natural. Incluye las eliminadas lógicamente: el índice único
     * también las cuenta, así que hay que reusarlas en vez de insertar.
     *
     * @param  list<string>  $cis
     * @return Collection<string, Licencia>
     */
    private function existentes(array $cis, Carbon $desde, Carbon $hasta): Collection
    {
        return Licencia::withTrashed()
            ->whereIn('ci', $cis)
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->get(['id', 'ci', 'fecha', 'turno_id', 'deleted_at'])
            ->keyBy(fn (Licencia $licencia): string => self::clave(
                trim((string) $licencia->ci),
                $licencia->fecha->toDateString(),
                (int) $licencia->turno_id,
            ));
    }

    /**
     * Arma el mensaje de resultado a partir del conteo.
     *
     * @param  array{creadas: int, existentes: int, fueraDeVigencia: int, sinTurno: int, funcionarios: int}  $conteo
     */
    public function mensaje(array $conteo): string
    {
        $partes = ["{$conteo['creadas']} licencia(s) anotada(s)"];

        if ($conteo['funcionarios'] > 1) {
            $partes[0] .= " para {$conteo['funcionarios']} funcionario(s)";
        }

        if ($conteo['existentes'] > 0) {
            $partes[] = "{$conteo['existentes']} ya existían";
        }

        if ($conteo['fueraDeVigencia'] > 0) {
            $partes[] = "{$conteo['fueraDeVigencia']} fuera de la vigencia del turno";
        }

        if ($conteo['sinTurno'] > 0) {
            $partes[] = "{$conteo['sinTurno']} sin horario vinculado";
        }

        return implode(', ', $partes).'.';
    }

    /**
     * Día de la semana en la convención del SIA (1 = Domingo … 7 = Sábado,
     * igual que DATEPART(dw)). Carbon numera el domingo como 0.
     */
    public static function diaSia(Carbon $fecha): int
    {
        return $fecha->dayOfWeek + 1;
    }

    /**
     * Clave natural de una licencia: ci + fecha + turno.
     */
    private static function clave(string $ci, string $fecha, int $turnoId): string
    {
        return $ci.'|'.$fecha.'|'.$turnoId;
    }

    /**
     * La fecha debe caer dentro del rango de vigencia de la asignación de turno.
     */
    private static function dentroDeVigencia(AsignacionTurno $asignacion, Carbon $fecha): bool
    {
        if ($asignacion->desde && $fecha->lessThan($asignacion->desde->copy()->startOfDay())) {
            return false;
        }

        return ! ($asignacion->hasta && $fecha->greaterThan($asignacion->hasta->copy()->endOfDay()));
    }

    /**
     * Convierte una hora «HH:MM» en un datetime sobre la fecha base 1899-12-30,
     * como guarda las horas el SIA (y el resto del sistema).
     */
    private static function sobreFechaBase(?string $hora): ?string
    {
        $hora = trim((string) $hora);

        return $hora === '' ? null : '1899-12-30 '.substr($hora, 0, 5).':00';
    }
}
