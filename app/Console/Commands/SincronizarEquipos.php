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
 * Rango que se baja: desde el día de la última corrida automática (o hoy, si
 * nunca corrió) hasta hoy. Así una corrida que falló ayer se recupera sola en
 * la siguiente, sin volver a leer el historial entero del reloj.
 */
#[Signature('sismark:sincronizar-equipos
    {--equipo= : ID de un equipo puntual, en vez de los que tocan por horario}
    {--forzar : Corre aunque no sea la hora configurada}
    {--desde= : Fecha inicial (Y-m-d); por defecto, la de la última corrida automática}
    {--hasta= : Fecha final (Y-m-d); por defecto, hoy}')]
#[Description('Sincroniza las marcaciones de los equipos biométricos en los horarios configurados en cada uno.')]
class SincronizarEquipos extends Command
{
    /**
     * Hasta cuántos días hacia atrás se le pide al reloj cuando estuvo mucho
     * tiempo sin dar señales.
     *
     * Sin tope, un equipo apagado seis meses volvería pidiendo medio año de
     * historial: el reloj tarda minutos en responder por el protocolo ZK, la
     * corrida se encima con la del minuto siguiente y el equipo queda inservible
     * mientras dura. Un mes cubre cualquier caída real —un feriado largo, un
     * equipo en reparación— y lo que quede afuera se baja a mano con
     * `--desde`, que no tiene tope.
     */
    private const MAX_DIAS_ATRAS = 30;

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
            $desde = (string) ($this->option('desde') ?: $this->desdeDe($equipo, $ahora));
            $hasta = (string) ($this->option('hasta') ?: $ahora->toDateString());

            $this->info("→ {$equipo->nombre} ({$equipo->ip}) · {$desde} a {$hasta}");

            $resultado = $sincronizador->sincronizar($equipo, $desde, $hasta);

            // «Cuándo corrió la tarea», no «cuándo anduvo el reloj»: se escribe
            // ande o no, y es lo que evita repetir la corrida dentro del mismo
            // minuto.
            $equipo->forceFill(['sync_ultimo_automatico' => $ahora])->save();

            if (! $resultado['exito']) {
                $fallados++;
                $this->error("   {$resultado['mensaje']}");

                continue;
            }

            // Hasta acá se trajo información. Va aparte de la marca de corrida
            // y **solo en el éxito**: es de esta columna que sale el «desde»,
            // así que mientras el equipo esté caído no se mueve y el hueco se
            // recupera entero cuando vuelva.
            $equipo->forceFill(['sync_ultimo_exito' => $ahora])->save();

            $this->line("   {$resultado['mensaje']}");
        }

        // Un equipo caído no tumba al resto —ya se sincronizaron—, pero el
        // comando termina en fallo para que quede visible en el log de la tarea.
        return $fallados > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Desde qué día se le piden las marcaciones al equipo.
     *
     * Es el día de la última corrida que **trajo datos**, no el de la última
     * que se intentó: así una caída de varios días se recupera entera en el
     * primer momento en que el reloj vuelve a contestar. Un equipo que nunca
     * se sincronizó arranca por hoy —el historial viejo se baja a mano, con
     * `--desde`, y no de sorpresa en la primera corrida automática—.
     *
     * El resultado se acota a {@see MAX_DIAS_ATRAS} por lo que explica esa
     * constante.
     */
    private function desdeDe(Equipo $equipo, Carbon $ahora): string
    {
        $desde = $equipo->sync_ultimo_exito ?? $ahora;
        $tope = $ahora->copy()->subDays(self::MAX_DIAS_ATRAS);

        if ($desde->lessThan($tope)) {
            $this->warn("   El equipo no sincroniza desde {$desde->toDateString()}; se piden los últimos ".self::MAX_DIAS_ATRAS.' días.');

            $desde = $tope;
        }

        return $desde->toDateString();
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
