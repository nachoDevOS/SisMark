<?php

namespace App\Services;

use App\Models\AsignacionHorario;
use App\Models\AsignacionTurno;
use App\Models\Horario;
use App\Models\Turno;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Único lugar que escribe asignaciones de turno.
 *
 * Una asignación de turno es una cabecera (`asignacion_turnos`) y su detalle:
 * una fila en `asignacion_horarios` por cada horario del turno, con las mismas
 * fechas. El procesador, las licencias y los reportes leen el detalle, así que
 * cabecera y detalle tienen que moverse siempre juntos: por eso todo pasa por acá
 * —la pantalla y la API— y nadie toca el detalle por su cuenta.
 *
 * Lo heredado del SIA (detalle sin cabecera) queda como historia. Lo único que
 * se le hace es cortarle la vigencia cuando a la persona se le asigna un turno
 * que arranca mientras seguía en pie, para que no convivan dos jornadas.
 */
class AsignadorTurnos
{
    /**
     * Motivo por el que no se puede asignar el turno en ese rango, o `null` si
     * se puede. Lo usan el request de la pantalla y la API, así los dos avisan
     * lo mismo y nunca llega a la base algo que reviente contra la única.
     */
    public function conflicto(string $ci, Turno $turno, Carbon $desde, Carbon $hasta, ?AsignacionTurno $ignorar = null): ?string
    {
        $ci = trim($ci);
        $desde = $desde->copy()->startOfDay();
        $hasta = $hasta->copy()->startOfDay();
        $horarios = $turno->horarios;

        if ($horarios->isEmpty()) {
            return "El turno «{$turno->nombre}» no tiene horarios.";
        }

        // Un horario dado de baja no se procesa: la persona quedaría sin
        // control ese día sin que nadie lo note.
        if ($horarios->contains(fn (Horario $horario): bool => $horario->trashed())) {
            return "El turno «{$turno->nombre}» tiene horarios eliminados. Creá otro turno con horarios vigentes.";
        }

        $otro = AsignacionTurno::query()
            ->with('turno')
            ->where('ci', $ci)
            ->solapadas($desde, $hasta)
            ->when($ignorar !== null, fn ($query) => $query->whereKeyNot($ignorar->getKey()))
            ->orderBy('desde')
            ->first();

        if ($otro !== null) {
            return 'Ya tiene asignado el turno «'.$otro->turno?->nombre.'» del '
                .$otro->desde?->format('d/m/Y').' al '.$otro->hasta?->format('d/m/Y')
                .'. Concluilo antes de asignarle otro.';
        }

        // Lo heredado que arranca dentro del rango no se puede cortar —quedaría
        // terminando antes de empezar—: se avisa para que lo resuelvan a mano.
        $heredado = AsignacionHorario::query()
            ->whereNull('asignacion_turno_id')
            ->where('ci', $ci)
            ->where('desde', '>=', $desde->toDateString())
            ->where('desde', '<=', $hasta->toDateString())
            ->orderBy('desde')
            ->first();

        if ($heredado !== null) {
            return 'Tiene horarios asignados (del sistema anterior) que empiezan el '
                .$heredado->desde?->format('d/m/Y').'. Concluilos o eliminalos en la ficha del funcionario (panel «Horarios») antes de asignarle el turno.';
        }

        // La única `(ci, horario_id, desde)` de `asignacion_horarios` incluye las
        // filas dadas de baja: se comprueba con `withTrashed()` para avisar en
        // vez de reventar.
        $repetido = AsignacionHorario::withTrashed()
            ->where('ci', $ci)
            ->whereIn('horario_id', $horarios->pluck('id')->all())
            ->where('desde', $desde->toDateString())
            ->when($ignorar !== null, fn ($query) => $query->where(fn ($sub) => $sub
                ->whereNull('asignacion_turno_id')
                ->orWhere('asignacion_turno_id', '!=', $ignorar->getKey())))
            ->exists();

        if ($repetido) {
            return 'Ya hubo una asignación con alguno de esos horarios que arranca el '
                .$desde->format('d/m/Y').' (aunque esté eliminada). Elegí otra fecha de inicio.';
        }

        return null;
    }

    /**
     * Asigna el turno: crea la cabecera y su detalle, y corta lo heredado que
     * seguía en pie. Quien llama ya comprobó {@see self::conflicto()}.
     */
    public function asignar(string $ci, Turno $turno, Carbon $desde, Carbon $hasta, ?int $contratoId = null, ?string $observacion = null): AsignacionTurno
    {
        $ci = trim($ci);
        $desde = $desde->copy()->startOfDay();
        $hasta = $hasta->copy()->startOfDay();

        return DB::transaction(function () use ($ci, $turno, $desde, $hasta, $contratoId, $observacion): AsignacionTurno {
            $this->cortarHeredados($ci, $desde);

            $asignacion = AsignacionTurno::create([
                'ci' => $ci,
                'turno_id' => $turno->id,
                'desde' => $desde,
                'hasta' => $hasta,
                'contrato_id' => $contratoId,
                'observacion' => $observacion,
            ]);

            foreach ($turno->horarios as $horario) {
                AsignacionHorario::create([
                    'ci' => $ci,
                    'horario_id' => $horario->id,
                    'asignacion_turno_id' => $asignacion->id,
                    'desde' => $desde,
                    'hasta' => $hasta,
                    'contrato_id' => $contratoId,
                    'observacion' => $observacion,
                ]);
            }

            return $asignacion;
        });
    }

    /**
     * Le pone fecha de fin al turno asignado y a su detalle.
     */
    public function concluir(AsignacionTurno $asignacion, Carbon $hasta): void
    {
        $this->moverVigencia($asignacion, $asignacion->desde, $hasta);
    }

    /**
     * Cambia las fechas del turno asignado y de su detalle. Quien cambie
     * `desde` ya comprobó {@see self::conflicto()} pasando esta asignación
     * como `$ignorar`.
     */
    public function moverVigencia(AsignacionTurno $asignacion, Carbon $desde, Carbon $hasta): void
    {
        $desde = $desde->copy()->startOfDay();
        $hasta = $hasta->copy()->startOfDay();

        DB::transaction(function () use ($asignacion, $desde, $hasta): void {
            // Si el inicio se adelanta, lo heredado que seguía en pie esos días
            // también se corta, igual que al asignar.
            $this->cortarHeredados(trim((string) $asignacion->ci), $desde);

            $asignacion->update(['desde' => $desde, 'hasta' => $hasta]);
            // En bloque no pasa por el accessor del modelo: va la fecha sola,
            // como la guarda `soloFecha()`.
            $asignacion->horariosAsignados()->update(['desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString()]);
        });
    }

    /**
     * Baja lógica del turno asignado y de su detalle.
     *
     * Con `$observacion` (la API) el motivo se graba antes y con su propio
     * `save()`, porque la baja lógica escribe solo `deleted_at`. Sin él (la
     * pantalla), lo graba RegistersUserEvents desde el modal de baja.
     */
    public function eliminar(AsignacionTurno $asignacion, ?string $observacion = null): void
    {
        DB::transaction(function () use ($asignacion, $observacion): void {
            foreach ([...$asignacion->horariosAsignados()->get(), $asignacion] as $fila) {
                if ($observacion !== null) {
                    $fila->forceFill(['deleteObservacion' => $observacion])->save();
                }

                $fila->delete();
            }
        });
    }

    /**
     * Revive un turno asignado dado de baja, con su detalle, y le pone la
     * vigencia nueva. Es para el contrato que se anula y se vuelve a cargar.
     */
    public function restaurar(AsignacionTurno $asignacion, Carbon $hasta, ?string $observacion = null): void
    {
        DB::transaction(function () use ($asignacion, $hasta, $observacion): void {
            $asignacion->restore();
            $asignacion->update(['hasta' => $hasta->copy()->startOfDay(), 'observacion' => $observacion]);

            $asignacion->horariosAsignados()->onlyTrashed()->get()->each->restore();
            $asignacion->horariosAsignados()->update(['hasta' => $hasta->toDateString(), 'observacion' => $observacion]);
        });
    }

    /**
     * Los períodos en que la persona tuvo horarios asignados —heredados del SIA
     * o detalle de un turno—, vencidos o no, en orden, cada uno con los días de
     * la semana que trabajaba (`dia` de {@see Horario::DIAS}). Se piden una vez
     * y se cruzan con cada contrato en {@see self::cobertura()}.
     *
     * Entran los turnos porque un contrato se puede regularizar de a tramos:
     * lo que ya se regularizó cuenta como cubierto para el siguiente.
     *
     * @return Collection<int, array{desde: Carbon, hasta: Carbon, dias: list<int>}>
     */
    public function periodosAsignados(string $ci): Collection
    {
        return AsignacionHorario::query()
            ->join('horarios', 'horarios.id', '=', 'asignacion_horarios.horario_id')
            // El join saltea el borrado lógico de horarios: hay que excluirlos a mano.
            ->whereNull('horarios.deleted_at')
            ->where('asignacion_horarios.ci', trim($ci))
            ->select('asignacion_horarios.desde', 'asignacion_horarios.hasta', 'horarios.dia')
            ->orderBy('asignacion_horarios.desde')
            ->get()
            ->filter(fn (AsignacionHorario $fila): bool => $fila->desde !== null && $fila->hasta !== null)
            ->groupBy(fn (AsignacionHorario $fila): string => $fila->clave_periodo)
            ->map(fn (Collection $filas): array => [
                'desde' => $filas->first()->desde->copy(),
                'hasta' => $filas->first()->hasta->copy(),
                'dias' => $filas->pluck('dia')->map(fn ($dia): int => (int) $dia)->unique()->values()->all(),
            ])
            ->values();
    }

    /**
     * Qué falta controlar de un período (el de un contrato que se regulariza),
     * según los horarios que la persona ya tenía asignados.
     *
     * Se buscan los tramos sin cubrir y se ofrece **el más antiguo**: el turno
     * va de `libreDesde` a `libreHasta`, justo el hueco, sin pisar nada. Los
     * demás quedan en `tramosPendientes` y aparecen al volver a regularizar.
     *
     * - `libre`: no tenía nada asignado; se regulariza el período entero.
     * - `parcial`: tenía algo; se regulariza el primer tramo sin control.
     * - `completa`: no queda un día sin cubrir; no hay nada que regularizar.
     *
     * Un tramo cuenta solo si tiene algún día que la persona trabajaba: el SIA
     * cortaba un período el viernes y arrancaba el siguiente el lunes, y ese
     * fin de semana no es un hueco para quien trabaja de lunes a viernes. Con
     * `$hasta` en `null` (contrato abierto) el último tramo no termina.
     *
     * @param  Collection<int, array{desde: Carbon, hasta: Carbon, dias: list<int>}>  $periodos  de {@see self::periodosAsignados()}
     * @return array{estado: 'libre'|'parcial'|'completa', libreDesde: ?Carbon, libreHasta: ?Carbon, tramosPendientes: int}
     */
    public function cobertura(Collection $periodos, Carbon $desde, ?Carbon $hasta): array
    {
        $desde = $desde->copy()->startOfDay();
        $hasta = $hasta?->copy()->startOfDay();

        $enRango = $periodos
            ->filter(fn (array $periodo): bool => $periodo['hasta']->greaterThanOrEqualTo($desde)
                && ($hasta === null || $periodo['desde']->lessThanOrEqualTo($hasta)))
            ->sortBy(fn (array $periodo): int => $periodo['desde']->getTimestamp())
            ->values();

        if ($enRango->isEmpty()) {
            return ['estado' => 'libre', 'libreDesde' => $desde, 'libreHasta' => $hasta, 'tramosPendientes' => 0];
        }

        $diasQueTrabajaba = $enRango->pluck('dias')->flatten()->unique()->all();
        $tramos = [];
        $anotar = function (Carbon $inicio, ?Carbon $fin) use (&$tramos, $diasQueTrabajaba): void {
            if ($this->tieneDiaLaborable($inicio, $fin, $diasQueTrabajaba)) {
                $tramos[] = [$inicio->copy(), $fin?->copy()];
            }
        };

        // Se recorre el período con un cursor: cada horario que arranca más
        // allá del cursor deja un tramo sin cubrir antes. Los períodos pegados
        // o encimados se funden solos.
        $cursor = $desde->copy();

        foreach ($enRango as $periodo) {
            if ($periodo['desde']->greaterThan($cursor)) {
                $anotar($cursor, $periodo['desde']->copy()->subDay());
            }

            if ($periodo['hasta']->greaterThanOrEqualTo($cursor)) {
                $cursor = $periodo['hasta']->copy()->addDay();
            }
        }

        if ($hasta === null || $cursor->lessThanOrEqualTo($hasta)) {
            $anotar($cursor, $hasta);
        }

        if ($tramos === []) {
            return ['estado' => 'completa', 'libreDesde' => null, 'libreHasta' => null, 'tramosPendientes' => 0];
        }

        return ['estado' => 'parcial', 'libreDesde' => $tramos[0][0], 'libreHasta' => $tramos[0][1], 'tramosPendientes' => count($tramos) - 1];
    }

    /**
     * Qué queda sin turno de un contrato **vigente que ya tiene turno**, desde
     * su primer turno hasta el fin del contrato, con la misma cuenta de tramos
     * que la regularización ({@see self::cobertura()}): se ofrece el hueco más
     * antiguo.
     *
     * Cubre el cambio de jornada (se concluyó el turno y falta el siguiente),
     * el hueco que queda en el medio al concluir un turno antes de que empiece
     * el próximo, y la adenda que no se pudo aplicar sola.
     *
     * No mira antes del primer turno: esos días no se ofrecen, para no
     * controlar meses que nadie marcó con ese turno.
     *
     * `null` si el contrato no tiene turnos: ahí se asigna como siempre.
     *
     * @param  Collection<int, array{desde: Carbon, hasta: Carbon, dias: list<int>}>  $periodos  de {@see self::periodosAsignados()}
     * @param  Collection<int, AsignacionTurno>  $turnosDelContrato
     * @return array{estado: 'libre'|'parcial'|'completa', libreDesde: ?Carbon, libreHasta: ?Carbon, tramosPendientes: int}|null
     */
    public function coberturaDelVigente(Collection $periodos, Collection $turnosDelContrato, ?Carbon $hastaContrato): ?array
    {
        if ($turnosDelContrato->isEmpty()) {
            return null;
        }

        $primerDia = $turnosDelContrato
            ->map(fn (AsignacionTurno $asignacion): Carbon => $asignacion->desde)
            ->sort()
            ->first();

        return $this->cobertura($periodos, $primerDia, $hastaContrato);
    }

    /**
     * ¿Hay en `[$inicio, $fin]` algún día de la semana de `$dias`? Con `$fin`
     * en `null` el tramo no termina, así que alcanza con que trabaje algún día.
     *
     * @param  array<int, int>  $dias  como {@see Horario::DIAS}: 1 = Domingo … 7 = Sábado
     */
    private function tieneDiaLaborable(Carbon $inicio, ?Carbon $fin, array $dias): bool
    {
        // Una semana entera ya pasa por todos los días.
        $ultimo = $fin === null ? $inicio->copy()->addDays(6) : $fin->copy()->min($inicio->copy()->addDays(6));

        for ($dia = $inicio->copy(); $dia->lessThanOrEqualTo($ultimo); $dia->addDay()) {
            if (in_array($dia->dayOfWeek + 1, $dias, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * La cobertura de {@see self::cobertura()} explicada en una frase, o `null`
     * si no tenía nada asignado en el período. La misma frase la muestra la
     * ficha y la devuelve la validación, así nunca se contradicen.
     *
     * @param  array{estado: string, libreDesde: ?Carbon, libreHasta: ?Carbon, tramosPendientes: int}  $cobertura
     */
    public function motivoCobertura(array $cobertura): ?string
    {
        if ($cobertura['estado'] === 'completa') {
            return 'Ya tenía horarios asignados en todo el contrato: esos días ya se controlaban, no hace falta regularizar.';
        }

        if ($cobertura['estado'] !== 'parcial') {
            return null;
        }

        $tramo = $cobertura['libreHasta'] === null
            ? 'desde el '.$cobertura['libreDesde']->format('d/m/Y')
            : 'del '.$cobertura['libreDesde']->format('d/m/Y').' al '.$cobertura['libreHasta']->format('d/m/Y');
        $pendientes = $cobertura['tramosPendientes'];

        return 'Tiene horarios asignados en parte del contrato. Quedó sin control '.$tramo.': se regulariza ese tramo.'
            .match (true) {
                $pendientes === 1 => ' Después queda otro tramo sin control, que se regulariza aparte.',
                $pendientes > 1 => " Después quedan {$pendientes} tramos más sin control, que se regularizan de a uno.",
                default => '',
            };
    }

    /**
     * Corta lo heredado del SIA que sigue en pie el día que arranca el turno:
     * termina el día anterior. Lo que ya había vencido no se toca.
     */
    private function cortarHeredados(string $ci, Carbon $desde): void
    {
        AsignacionHorario::query()
            ->whereNull('asignacion_turno_id')
            ->where('ci', $ci)
            ->where('desde', '<', $desde->toDateString())
            ->where('hasta', '>=', $desde->toDateString())
            ->update(['hasta' => $desde->copy()->subDay()->toDateString()]);
    }
}
