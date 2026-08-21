<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('sia:migrar-marcaciones {--chunk=1000 : Filas por lote}')]
#[Description('Copia las marcaciones (Asistencia) del SIA (SQL Server) a la base local, tal cual, sin tocar el origen.')]
class MigrarMarcacionesSia extends Command
{
    /**
     * Mapa columna del SIA (PascalCase) → columna local (camelCase). El carnet
     * IdPersona pasa a `ci`.
     *
     * @var array<string, string>
     */
    private const MAPA = [
        'IdPersona' => 'ci',
        'Fecha' => 'fecha',
        'Hora' => 'hora',
        'Tipo' => 'tipo',
    ];

    /**
     * Clave natural de una marcación (en el SIA es la clave compuesta; acá el
     * índice único). Es por lo que deduplica el upsert.
     *
     * @var list<string>
     */
    private const CLAVE = ['ci', 'fecha', 'hora'];

    /**
     * Copia las marcaciones del SIA a la tabla local `asistencias`. Idempotente:
     * reejecutarlo no duplica (upsert por ci+fecha+hora).
     *
     * La tabla del SIA ronda los 4.4 millones de filas, por eso se lee con un
     * cursor (stream de una sola consulta) en vez de paginar: el ROW_NUMBER()
     * del grammar 2008 haría que cada página reescanee y el costo sea O(n²).
     */
    public function handle(): int
    {
        $tamanoLote = max(1, (int) $this->option('chunk'));
        $destino = config('database.default');

        if ($destino === 'sia') {
            $this->error('La conexión por defecto es «sia»; no hay a dónde copiar.');

            return self::FAILURE;
        }

        $copiadas = 0;
        $lote = [];

        try {
            $filas = DB::connection('sia')->table('Asistencia')
                ->select(array_keys(self::MAPA))
                ->cursor();

            foreach ($filas as $fila) {
                $ahora = now();

                $lote[] = $this->aLocal((array) $fila) + ['created_at' => $ahora, 'updated_at' => $ahora];

                if (count($lote) >= $tamanoLote) {
                    $copiadas += $this->guardar($destino, $lote);
                    $lote = [];
                    $this->info("Copiadas {$copiadas} marcación(es)…");
                }
            }

            if ($lote !== []) {
                $copiadas += $this->guardar($destino, $lote);
            }
        } catch (Throwable $e) {
            $this->error("Falló la migración de marcaciones: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Listo. {$copiadas} marcación(es) migrada(s) del SIA a «{$destino}».");

        return self::SUCCESS;
    }

    /**
     * Inserta/actualiza un lote en la tabla local. Al reejecutar refresca tipo
     * y updated_at; nunca toca created_at.
     *
     * @param  list<array<string, mixed>>  $lote
     */
    private function guardar(string $destino, array $lote): int
    {
        DB::connection($destino)->table('asistencias')
            ->upsert($lote, self::CLAVE, ['tipo', 'updated_at']);

        return count($lote);
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

        // En el SIA las dos son datetime: «Fecha» siempre a las 00:00 y «Hora»
        // sobre la fecha base 1899-12-30. Acá las columnas son `date` y `time`,
        // así que se recorta lo que sobra —si no, MySQL en modo estricto
        // rechaza el insert por truncamiento—.
        if ($local['fecha'] !== null) {
            $local['fecha'] = Carbon::parse($local['fecha'])->toDateString();
        }

        if ($local['hora'] !== null) {
            $local['hora'] = Carbon::parse($local['hora'])->format('H:i:s');
        }

        return $local;
    }
}
