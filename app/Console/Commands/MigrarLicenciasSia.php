<?php

namespace App\Console\Commands;

use App\Models\Licencia;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('sia:migrar-licencias {--chunk=500 : Filas por lote}')]
#[Description('Copia las licencias/permisos del SIA (SQL Server) a la base local, tal cual, sin tocar el origen.')]
class MigrarLicenciasSia extends Command
{
    /**
     * Mapa columna del SIA (PascalCase) → columna local (camelCase). El carnet
     * IdPersona pasa a `ci`.
     *
     * @var array<string, string>
     */
    private const MAPA = [
        'FechaPedido' => 'fechaPedido',
        'Usuario' => 'usuario',
        'Fecha' => 'fecha',
        'IdPersona' => 'ci',
        'IdTurno' => 'idTurno',
        'LEntra' => 'lEntra',
        'LSale' => 'lSale',
        'TCompleto' => 'tCompleto',
        'Motivo' => 'motivo',
        'GoceHaberes' => 'goceHaberes',
    ];

    /**
     * Copia las licencias del SIA a la tabla local `licencias`. Idempotente:
     * reejecutarlo no duplica (índice único por ci+fecha+turno_id). Recorta el padding char().
     *
     * Se lee con un cursor (stream de una sola consulta) en vez de paginar: el
     * ROW_NUMBER() del grammar 2008 haría que cada página reescanee (O(n²)) y en
     * tablas grandes el comando parecería colgado. Imprime progreso por lote.
     */
    public function handle(): int
    {
        $tamanoLote = max(1, (int) $this->option('chunk'));
        $destino = config('database.default');

        if ($destino === 'sia') {
            $this->error('La conexión por defecto es «sia»; no hay a dónde copiar.');

            return self::FAILURE;
        }

        // Mapa idTurno → id local de turnos, para resolver la FK turno_id sin
        // consultar la base por cada fila. Si turnos está vacío, todas quedan null.
        $turnosPorCodigo = DB::connection($destino)->table('turnos')->pluck('id', 'idTurno');

        if ($turnosPorCodigo->isEmpty()) {
            $this->error('La tabla «turnos» está vacía: sin ella no se puede resolver el turno de cada licencia. Corré «sia:migrar-horarios» antes.');

            return self::FAILURE;
        }

        $copiadas = 0;
        $salteadas = 0;
        $lote = [];

        try {
            $filas = DB::connection('sia')->table('Licencias')
                ->select(array_keys(self::MAPA))
                ->cursor();

            foreach ($filas as $fila) {
                $ahora = now();
                $local = $this->aLocal((array) $fila);

                // FK real: id de MySQL del turno cuyo idTurno coincide. El
                // `idTurno` se conserva en la fila solo como dato histórico.
                $turnoId = $turnosPorCodigo[$local['idTurno']] ?? null;

                // Sin turno la fila no identifica ningún horario y turno_id es
                // NOT NULL: se saltea y se informa al final.
                if ($turnoId === null) {
                    $salteadas++;

                    continue;
                }

                $local['turno_id'] = $turnoId;
                $lote[] = $local + ['created_at' => $ahora, 'updated_at' => $ahora];

                if (count($lote) >= $tamanoLote) {
                    $copiadas += $this->guardar($destino, $lote);
                    $lote = [];
                    $this->info("Copiadas {$copiadas} licencia(s)…");
                }
            }

            if ($lote !== []) {
                $copiadas += $this->guardar($destino, $lote);
            }
        } catch (Throwable $e) {
            $this->error("Falló la migración de licencias: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Listo. {$copiadas} licencia(s) migrada(s) del SIA a «{$destino}».");

        if ($salteadas > 0) {
            $this->warn("{$salteadas} licencia(s) salteada(s): su IdTurno no existe en «turnos».");
        }

        return self::SUCCESS;
    }

    /**
     * Guarda un lote sin duplicar.
     *
     * Va con `insertOrIgnore` y no con `upsert` porque la clave que deduplica
     * es una **expresión** —`COALESCE(solicitud, '')`, ver la migración
     * `create_licencias_table`— y el `upsert` de Laravel solo sabe
     * nombrar columnas: al pasarle `(ci, fecha, turno_id)` el motor no encuentra
     * ningún índice con esa forma exacta y falla.
     *
     * Lo que se pierde a cambio es refrescar una fila que haya cambiado en el
     * SIA. Es aceptable: son licencias ya otorgadas de años anteriores, y el
     * sistema viejo está congelado.
     *
     * @param  list<array<string, mixed>>  $lote
     */
    private function guardar(string $destino, array $lote): int
    {
        DB::connection($destino)->table('licencias')->insertOrIgnore($lote);

        return count($lote);
    }

    /**
     * Traduce una fila del SIA a la fila local (renombra columnas, recorta el
     * padding de los char() y descarta la hora de `fecha`).
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

        // En el SIA «Fecha» es datetime (siempre a las 00:00) pero acá la columna
        // es date: si alguna fila trajera hora, MySQL en modo estricto rechazaría
        // el insert por truncamiento.
        if ($local['fecha'] !== null) {
            $local['fecha'] = Carbon::parse($local['fecha'])->toDateString();
        }

        // Al revés con las horas de la licencia: el SIA las manda como datetime
        // sobre la fecha base 1899-12-30 y acá las columnas son `time`. Se
        // descarta el día, que no significa nada —la licencia ya tiene su
        // `fecha`—, y sin recortarlo MySQL rechazaría el insert.
        foreach (['lEntra', 'lSale'] as $hora) {
            if ($local[$hora] !== null) {
                $local[$hora] = Carbon::parse($local[$hora])->format('H:i:s');
            }
        }

        // `solicitud` queda en null a propósito: lo del SIA no fue un pedido.
        $local['origen'] = 'sia';
        // Lo del SIA es licencia ya otorgada: institucional, fuera del tope mensual.
        $local['tipo'] = Licencia::TIPO_INSTITUCIONAL;

        return $local;
    }
}
