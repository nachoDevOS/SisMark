<?php

namespace App\Console\Commands;

use App\Models\Equipo;
use App\Services\SincronizadorEquipos;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Baja las marcaciones de los equipos a los que les toca según su horario.
 *
 * La tarea programada la dispara cada minuto (routes/console.php) y acá se
 * decide qué equipos trabajan: los activos, con la sincronización automática
 * encendida, cuyo día de la semana está entre los elegidos y cuya lista de
 * horas contiene el «HH:MM» de este minuto. Esa es la razón de comparar en PHP
 * y no filtrar en SQL: los días y las horas son listas por equipo y son pocas.
 *
 * Solo **lee** el reloj: las marcaciones se copian a `asistencias` y el equipo
 * conserva su historial. Vaciarlo es otra acción, a mano y con otro permiso.
 *
 * **Se baja el buffer completo, sin rango.** El protocolo ZK vuelca todo el
 * historial en cada lectura, así que pedir un rango no le ahorraba trabajo al
 * reloj: solo descartaba después. Sin rango no queda nada afuera, lo que ya
 * está en la base se descarta por la terna `(ci, fecha, hora)`, y la corrida es
 * idempotente. Con eso desaparece también el tope de días hacia atrás que hacía
 * falta cuando el rango arrancaba en la última corrida exitosa: un equipo que
 * estuvo meses caído se pone al día en la primera lectura, sin cálculo previo.
 */
#[Signature('sismark:sincronizar-equipos
    {--equipo= : ID de un equipo puntual, en vez de los que tocan por horario}
    {--forzar : Corre aunque no sea la hora configurada}')]
#[Description('Sincroniza las marcaciones de los equipos biométricos en los horarios configurados en cada uno.')]
class SincronizarEquipos extends Command
{
    public function handle(SincronizadorEquipos $sincronizador): int
    {
        $ahora = now();
        $equipos = $this->equipos($ahora);

        if ($equipos->isEmpty()) {
            $this->line("Sin equipos para sincronizar a las {$ahora->format('H:i')}.");

            return self::SUCCESS;
        }

        $fallados = 0;

        foreach ($equipos as $equipo) {
            $this->info("→ {$equipo->nombre} ({$equipo->ip})");

            $resultado = $sincronizador->sincronizar($equipo);

            // «Cuándo corrió la tarea», no «cuándo anduvo el reloj»: se escribe
            // ande o no, y es lo que evita repetir la corrida dentro del mismo
            // minuto.
            $equipo->forceFill(['sync_ultimo_automatico' => $ahora])->save();

            if (! $resultado['exito']) {
                $fallados++;
                $this->error("   {$resultado['mensaje']}");

                continue;
            }

            // La lectura se cortó por el medio: el reloj declaró más
            // marcaciones de las que llegaron. Lo que llegó ya se guardó —no se
            // tira nada—, pero la corrida no cuenta como buena y el equipo
            // queda marcado para que se note en la ficha y en la bitácora.
            if ($resultado['completa'] === false) {
                $fallados++;
                $this->warn("   {$resultado['mensaje']}");

                continue;
            }

            // Hasta acá se trajo todo lo que el reloj tenía. Va aparte de la
            // marca de corrida y **solo cuando la transferencia cerró**: es la
            // fecha que la ficha muestra como «última vez que trajo datos», y
            // sirve para delatar al reloj que responde pero entrega a medias.
            $equipo->forceFill(['sync_ultimo_exito' => $ahora])->save();

            $this->line("   {$resultado['mensaje']}");
        }

        // Un equipo caído no tumba al resto —ya se sincronizaron—, pero el
        // comando termina en fallo para que quede visible en el log de la tarea.
        return $fallados > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Equipos que trabajan en esta corrida.
     *
     * @return Collection<int, Equipo>
     */
    private function equipos(Carbon $ahora): Collection
    {
        $id = $this->option('equipo');

        if ($id) {
            return Equipo::query()->whereKey($id)->get();
        }

        return Equipo::query()
            ->where('activo', true)
            ->where('sync_automatica', true)
            ->get()
            ->filter(fn (Equipo $equipo): bool => $this->option('forzar')
                || ($equipo->tocaSincronizar($ahora) && ! $equipo->yaSincronizoEn($ahora)));
    }
}
