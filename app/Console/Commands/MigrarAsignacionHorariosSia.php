<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('sia:migrar-asignacion-horarios {--chunk=500 : Filas por lote}')]
#[Description('Copia las asignaciones de horario del SIA (SQL Server) a la base local, resolviendo la FK horario_id.')]
class MigrarAsignacionHorariosSia extends Command
{
    /**
     * Mapa columna del SIA (PascalCase) → columna local (camelCase). El carnet
     * IdPersona pasa a `ci`; IdTurno se conserva como `idHorario`. La FK `horario_id`
     * no viene del SIA: se resuelve cruzando idHorario contra `horarios` (ver handle()).
     *
     * @var array<string, string>
     */
    private const MAPA = [
        'IdPersona' => 'ci',
        'IdTurno' => 'idHorario',
        'Desde' => 'desde',
        'Hasta' => 'hasta',
    ];

    /**
     * Clave natural (upsert): una asignación por funcionario, horario y fecha de
     * inicio. Solo columnas NOT NULL.
     *
     * @var list<string>
     */
    private const CLAVE = ['ci', 'idHorario', 'desde'];

    /**
     * Copia las asignaciones del SIA a la tabla local `asignacion_horarios`.
     * Idempotente (upsert por ci+idHorario+desde). Además de renombrar columnas,
     * resuelve `horario_id` cruzando el idHorario de cada fila contra la tabla
     * local `horarios` (usa su id de MySQL). Por eso conviene migrar los horarios
     * antes; si un idHorario no cruza, horario_id queda null.
     */
    public function handle(): int
    {
        $tamanoLote = max(1, (int) $this->option('chunk'));
        $destino = config('database.default');

        if ($destino === 'sia') {
            $this->error('La conexión por defecto es «sia»; no hay a dónde copiar.');

            return self::FAILURE;
        }

        // Mapa idHorario → id local de horarios, para resolver la FK sin consultar
        // la base por cada fila. Si horarios está vacío, todas las FK quedan null.
        $horariosPorCodigo = DB::connection($destino)->table('horarios')->pluck('id', 'idHorario');

        if ($horariosPorCodigo->isEmpty()) {
            $this->warn('La tabla «horarios» está vacía: horario_id quedará null. Corré «sia:migrar-horarios» antes.');
        }

        $actualizables = ['horario_id', 'hasta', 'updated_at'];
        $copiadas = 0;
        $lote = [];

        try {
            $filas = DB::connection('sia')->table('AsignacionTurnos')
                ->select(array_keys(self::MAPA))
                ->cursor();

            foreach ($filas as $fila) {
                $ahora = now();
                $local = $this->aLocal((array) $fila);
                // FK real: id de MySQL del horario cuyo idHorario coincide.
                $local['horario_id'] = $horariosPorCodigo[$local['idHorario']] ?? null;
                $lote[] = $local + ['created_at' => $ahora, 'updated_at' => $ahora];

                if (count($lote) >= $tamanoLote) {
                    DB::connection($destino)->table('asignacion_horarios')->upsert($lote, self::CLAVE, $actualizables);
                    $copiadas += count($lote);
                    $lote = [];
                    $this->info("Copiadas {$copiadas} asignación(es)…");
                }
            }

            if ($lote !== []) {
                DB::connection($destino)->table('asignacion_horarios')->upsert($lote, self::CLAVE, $actualizables);
                $copiadas += count($lote);
            }
        } catch (Throwable $e) {
            $this->error("Falló la migración de asignaciones de horario: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Listo. {$copiadas} asignación(es) migrada(s) del SIA a «{$destino}».");

        return self::SUCCESS;
    }

    /**
     * Traduce una fila del SIA a la fila local (renombra columnas y recorta el
     * padding de los char()).
     *
     * @param  array<string, mixed>  $fila
     * @return array<string, mixed>
     */
    private function aLocal(array $fila): array
    {
        $local = [];

        foreach (self::MAPA as $origen => $destino) {
            $valor = $fila[$origen] ?? null;
            $local[$destino] = is_string($valor) ? trim($valor) : $valor;
        }

        // El SIA guarda `datetime` y acá las columnas son `date`: se deja solo
        // el día, en vez de depender de que MySQL trunque la hora.
        foreach (['desde', 'hasta'] as $campo) {
            if (is_string($local[$campo] ?? null)) {
                $local[$campo] = substr($local[$campo], 0, 10);
            }
        }

        return $local;
    }
}
