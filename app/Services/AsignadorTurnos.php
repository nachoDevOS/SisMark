<?php

namespace App\Services;

use App\Models\AsignacionHorario;
use App\Models\AsignacionTurno;
use App\Models\Horario;
use App\Models\Turno;
use Illuminate\Support\Carbon;
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
            ->where('desde', '>=', $desde)
            ->where('desde', '<=', $hasta)
            ->orderBy('desde')
            ->first();

        if ($heredado !== null) {
            return 'Tiene horarios asignados (del sistema anterior) que empiezan el '
                .$heredado->desde?->format('d/m/Y').'. Concluilos o eliminalos en «Horarios asignados» antes de asignarle el turno.';
        }

        // La única `(ci, idHorario, desde)` de `asignacion_horarios` incluye las
        // filas dadas de baja: se comprueba con `withTrashed()` para avisar en
        // vez de reventar.
        $repetido = AsignacionHorario::withTrashed()
            ->where('ci', $ci)
            ->whereIn('idHorario', $horarios->pluck('idHorario')->all())
            ->where('desde', $desde)
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
                    // Parte de la clave única de la tabla, como en lo del SIA.
                    'idHorario' => $horario->idHorario,
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
     * Corta lo heredado del SIA que sigue en pie el día que arranca el turno:
     * termina el día anterior. Lo que ya había vencido no se toca.
     */
    private function cortarHeredados(string $ci, Carbon $desde): void
    {
        AsignacionHorario::query()
            ->whereNull('asignacion_turno_id')
            ->where('ci', $ci)
            ->where('desde', '<', $desde)
            ->where('hasta', '>=', $desde)
            ->update(['hasta' => $desde->copy()->subDay()->toDateString()]);
    }
}
