<?php

namespace App\Console\Commands;

use App\Models\DiaExcepcional;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('sia:migrar-dias-excepcionales {--chunk=500 : Filas por lote de aviso}')]
#[Description('Copia los días excepcionales (Calendario) del SIA (SQL Server) a la base local, tal cual, sin tocar el origen.')]
class MigrarDiasExcepcionalesSia extends Command
{
    /**
     * Mapa columna del SIA (PascalCase) → columna local (camelCase). En el SIA
     * la tabla se llama Calendario.
     *
     * @var array<string, string>
     */
    private const MAPA = [
        'Fecha' => 'fecha',
        'MotivoInasistencia' => 'motivoInasistencia',
    ];

    /**
     * Copia los días excepcionales del SIA a la tabla local `dias_excepcionales`.
     * Idempotente: reejecutarlo no duplica (updateOrCreate por fecha, sin
     * depender de un índice único en la base). Recorta el padding char().
     */
    public function handle(): int
    {
        $tamanoAviso = max(1, (int) $this->option('chunk'));
        $destino = config('database.default');

        if ($destino === 'sia') {
            $this->error('La conexión por defecto es «sia»; no hay a dónde copiar.');

            return self::FAILURE;
        }

        $copiadas = 0;

        try {
            $filas = DB::connection('sia')->table('Calendario')
                ->select(array_keys(self::MAPA))
                ->cursor();

            foreach ($filas as $fila) {
                $local = $this->aLocal((array) $fila);

                DiaExcepcional::updateOrCreate(
                    ['fecha' => $local['fecha']],
                    ['motivoInasistencia' => $local['motivoInasistencia']],
                );

                $copiadas++;

                if ($copiadas % $tamanoAviso === 0) {
                    $this->info("Copiados {$copiadas} día(s)…");
                }
            }
        } catch (Throwable $e) {
            $this->error("Falló la migración de días excepcionales: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Listo. {$copiadas} día(s) excepcional(es) migrado(s) del SIA a «{$destino}».");

        return self::SUCCESS;
    }

    /**
     * Traduce una fila del SIA a la fila local (renombra columnas, recorta el
     * padding de los char() y deja la fecha sin hora: en el SIA es datetime).
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

        $local['fecha'] = Carbon::parse($local['fecha'])->toDateString();

        return $local;
    }
}
