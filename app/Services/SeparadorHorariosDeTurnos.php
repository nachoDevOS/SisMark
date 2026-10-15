<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pasa los turnos de «elegir horarios de un catálogo» a «tener horarios propios».
 *
 * Antes un turno juntaba horarios sueltos a través de la tabla pivote
 * `horario_turno`, y un mismo horario podía estar en varios turnos. Ahora cada
 * horario de un turno es **suyo** (`horarios.turno_id`).
 *
 * La regla es una sola: **cada par (turno, horario) de la pivote recibe una
 * copia nueva del horario**, con `turno_id` y sin `idHorario` (el código del
 * SIA ya no se escribe; las filas repuntadas conservan el que tenían). Lo que
 * nació de ese turno —el detalle de sus asignaciones y las licencias de esas
 * personas en esas fechas— pasa a apuntar a la copia. Lo heredado del SIA no se
 * toca y sigue en el horario original.
 *
 * Asignaciones y licencias se repuntan **juntas**: el procesador cruza
 * `licencia.horario_id` con el horario del día, así que si solo se moviera una
 * de las dos, la licencia dejaría de justificar y el día saldría como falta.
 * Se incluyen las filas dadas de baja, para que un contrato que se revive vuelva
 * con el horario correcto.
 *
 * El original que queda sin nada que lo use se da de baja con una observación
 * que lo identifica; el que todavía usa algo del SIA queda vivo.
 *
 * Todo se verifica al final: si un turno no quedó con los mismos horarios, o
 * una asignación de turno apunta a un horario que no es de ese turno, se lanza
 * una excepción para que la transacción de quien llama deshaga todo.
 */
class SeparadorHorariosDeTurnos
{
    /**
     * Prefijo de la observación de baja de los originales que quedan sin uso. Es
     * también la marca con la que {@see self::revertir()} los reconoce.
     */
    public const OBSERVACION_BAJA = 'Reemplazado por su copia en el turno';

    /**
     * Columnas que tienen que ser iguales en el original y en la copia: las que
     * usa el procesador para calcular el día.
     *
     * @var list<string>
     */
    private const COLUMNAS_DEL_CALCULO = [
        'dia', 'nombreHorario', 'hEntrada', 'hSalida', 'hTolerancia', 'eMinima', 'eMaxima',
        'sMinima', 'sMaxima', 'sTolerancia', 'hTrabajadas', 'siguienteDia',
    ];

    /**
     * Copia los horarios de cada turno y repunta lo que nació de él.
     *
     * Solo trabaja las filas de `$pivote` que todavía no tienen copia
     * (`horario_copia_id` nulo), así que correrlo dos veces no duplica nada.
     *
     * @return array{copias: int, asignaciones: int, licencias: int, bajas: int}
     */
    public function separar(string $pivote): array
    {
        $resumen = ['copias' => 0, 'asignaciones' => 0, 'licencias' => 0, 'bajas' => 0];
        $antes = $this->conteos();

        $pares = DB::table($pivote)
            ->whereNull('horario_copia_id')
            ->orderBy('turno_id')
            ->orderBy('horario_id')
            ->get(['id', 'turno_id', 'horario_id']);

        foreach ($pares as $par) {
            $original = DB::table('horarios')->where('id', $par->horario_id)->first();

            if ($original === null) {
                throw new RuntimeException("La pivote apunta al horario #{$par->horario_id}, que no existe.");
            }

            $copiaId = $this->copiar($original, (int) $par->turno_id);
            DB::table($pivote)->where('id', $par->id)->update(['horario_copia_id' => $copiaId]);

            [$asignaciones, $licencias] = $this->repuntar((int) $par->turno_id, (int) $original->id, $copiaId);

            $resumen['copias']++;
            $resumen['asignaciones'] += $asignaciones;
            $resumen['licencias'] += $licencias;
        }

        foreach ($pares->groupBy('horario_id') as $horarioId => $deEseHorario) {
            if ($this->darDeBajaSiNoSeUsa((int) $horarioId, $deEseHorario->pluck('turno_id')->unique()->all())) {
                $resumen['bajas']++;
            }
        }

        $this->verificar($pivote, $antes);

        return $resumen;
    }

    /**
     * Deshace {@see self::separar()}: devuelve asignaciones y licencias al
     * original, revive los originales dados de baja y borra las copias.
     */
    public function revertir(string $pivote): void
    {
        $pares = DB::table($pivote)->whereNotNull('horario_copia_id')->get(['id', 'horario_id', 'horario_copia_id']);

        foreach ($pares as $par) {
            DB::table('asignacion_horarios')
                ->where('horario_id', $par->horario_copia_id)
                ->update(['horario_id' => $par->horario_id]);

            DB::table('licencias')
                ->where('horario_id', $par->horario_copia_id)
                ->update(['horario_id' => $par->horario_id]);

            DB::table('horarios')
                ->where('id', $par->horario_id)
                ->where('deleteObservacion', 'like', self::OBSERVACION_BAJA.'%')
                ->update(['deleted_at' => null, 'deleteObservacion' => null]);

            DB::table($pivote)->where('id', $par->id)->update(['horario_copia_id' => null]);
            DB::table('horarios')->where('id', $par->horario_copia_id)->delete();
        }
    }

    /**
     * Inserta la copia del horario para el turno, sin código del SIA, y devuelve
     * su id.
     */
    private function copiar(object $original, int $turnoId): int
    {
        $datos = collect((array) $original)->except('id')->all();
        $datos['idHorario'] = null;
        $datos['turno_id'] = $turnoId;
        $datos['created_at'] = now();
        $datos['updated_at'] = now();

        return (int) DB::table('horarios')->insertGetId($datos);
    }

    /**
     * Pasa a la copia el detalle de las asignaciones de ese turno que usaban el
     * original, y las licencias de esas personas en esas fechas. Solo cambia
     * `horario_id`: el `idHorario` de cada fila queda como estaba.
     *
     * @return array{0: int, 1: int} filas de asignación y de licencia movidas
     */
    private function repuntar(int $turnoId, int $originalId, int $copiaId): array
    {
        $detalle = DB::table('asignacion_horarios')
            ->where('horario_id', $originalId)
            ->whereIn('asignacion_turno_id', DB::table('asignacion_turnos')->where('turno_id', $turnoId)->select('id'))
            ->get(['id', 'ci', 'desde', 'hasta']);

        $licencias = 0;

        foreach ($detalle as $fila) {
            $licencias += DB::table('licencias')
                ->where('ci', $fila->ci)
                ->where('horario_id', $originalId)
                ->whereBetween('fecha', [$fila->desde, $fila->hasta])
                ->update(['horario_id' => $copiaId]);
        }

        $asignaciones = $detalle->isEmpty() ? 0 : DB::table('asignacion_horarios')
            ->whereIn('id', $detalle->pluck('id')->all())
            ->update(['horario_id' => $copiaId]);

        return [$asignaciones, $licencias];
    }

    /**
     * Da de baja el original si ya no lo usa nada (ni dado de baja). Un horario
     * dado de baja no se procesa, así que no se toca el que todavía usa algo.
     *
     * @param  list<int>  $turnoIds
     */
    private function darDeBajaSiNoSeUsa(int $horarioId, array $turnoIds): bool
    {
        $enUso = DB::table('asignacion_horarios')->where('horario_id', $horarioId)->exists()
            || DB::table('licencias')->where('horario_id', $horarioId)->exists();

        if ($enUso) {
            return false;
        }

        return DB::table('horarios')
            ->where('id', $horarioId)
            ->whereNull('deleted_at')
            ->update([
                'deleted_at' => now(),
                'deleteObservacion' => self::OBSERVACION_BAJA.' #'.implode(', #', $turnoIds).'.',
            ]) > 0;
    }

    /**
     * Comprueba que la separación no cambió nada de lo que se calcula.
     *
     * @param  array{asignaciones: int, licencias: int}  $antes
     */
    private function verificar(string $pivote, array $antes): void
    {
        if ($this->conteos() !== $antes) {
            throw new RuntimeException('Cambió la cantidad de asignaciones o licencias: solo se debían repuntar.');
        }

        $pares = DB::table($pivote)->get(['turno_id', 'horario_id', 'horario_copia_id']);

        foreach ($pares as $par) {
            $original = DB::table('horarios')->where('id', $par->horario_id)->first(self::COLUMNAS_DEL_CALCULO);
            $copia = DB::table('horarios')
                ->where('id', $par->horario_copia_id)
                ->where('turno_id', $par->turno_id)
                ->first(self::COLUMNAS_DEL_CALCULO);

            if ($copia === null || (array) $copia != (array) $original) {
                throw new RuntimeException("El horario #{$par->horario_id} del turno #{$par->turno_id} no quedó copiado igual.");
            }
        }

        foreach ($pares->groupBy('turno_id') as $turnoId => $deEseTurno) {
            $propios = DB::table('horarios')->where('turno_id', $turnoId)->count();

            if ($propios !== $deEseTurno->count()) {
                throw new RuntimeException("El turno #{$turnoId} tenía {$deEseTurno->count()} horario(s) y quedó con {$propios}.");
            }
        }

        $ajenas = DB::table('asignacion_horarios')
            ->join('asignacion_turnos', 'asignacion_turnos.id', '=', 'asignacion_horarios.asignacion_turno_id')
            ->join('horarios', 'horarios.id', '=', 'asignacion_horarios.horario_id')
            ->where(fn ($query) => $query
                ->whereNull('horarios.turno_id')
                ->orWhereColumn('horarios.turno_id', '!=', 'asignacion_turnos.turno_id'))
            ->count();

        if ($ajenas > 0) {
            throw new RuntimeException("{$ajenas} fila(s) de asignaciones de turno apuntan a un horario que no es de su turno.");
        }
    }

    /**
     * @return array{asignaciones: int, licencias: int}
     */
    private function conteos(): array
    {
        return [
            'asignaciones' => DB::table('asignacion_horarios')->count(),
            'licencias' => DB::table('licencias')->count(),
        ];
    }
}
