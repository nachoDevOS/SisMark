<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('sia:migrar-horarios {--chunk=500 : Filas por lote}')]
#[Description('Copia los horarios (DiaTurnos) del SIA (SQL Server) a la base local, tal cual, sin tocar el origen.')]
class MigrarHorariosSia extends Command
{
    /**
     * Mapa columna del SIA (PascalCase) → columna local (camelCase).
     *
     * @var array<string, string>
     */
    private const MAPA = [
        'IdTurno' => 'idHorario',
        'Dia' => 'dia',
        'NombreTurno' => 'nombreHorario',
        'HEntrada' => 'hEntrada',
        'HSalida' => 'hSalida',
        'HTolerancia' => 'hTolerancia',
        'EMinima' => 'eMinima',
        'EMaxima' => 'eMaxima',
        'SMinima' => 'sMinima',
        'SMaxima' => 'sMaxima',
        'STolerancia' => 'sTolerancia',
        'HTrabajadas' => 'hTrabajadas',
        'SiguienteDia' => 'siguienteDia',
    ];

    /**
     * Copia los horarios del SIA a la tabla local `horarios`. Idempotente:
     * reejecutarlo no duplica (upsert por idHorario). Recorta el padding char().
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
        $actualizables = [...array_values(array_diff(self::MAPA, ['idHorario'])), 'updated_at'];

        try {
            DB::connection('sia')->table('DiaTurnos')
                ->select(array_keys(self::MAPA))
                ->orderBy('IdTurno')
                ->chunk($tamanoLote, function ($filas) use (&$copiadas, $actualizables, $destino): void {
                    $ahora = now();

                    $registros = $filas
                        ->map(fn ($fila): array => $this->aLocal((array) $fila) + ['created_at' => $ahora, 'updated_at' => $ahora])
                        ->all();

                    DB::connection($destino)->table('horarios')
                        ->upsert($registros, ['idHorario'], $actualizables);

                    $copiadas += count($registros);
                });
        } catch (Throwable $e) {
            $this->error("Falló la migración de horarios: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Listo. {$copiadas} horario(s) migrado(s) del SIA a «{$destino}».");

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

        return $local;
    }
}
