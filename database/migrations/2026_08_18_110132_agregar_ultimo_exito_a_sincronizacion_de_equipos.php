<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hasta dónde llegó la última sincronización automática que **trajo datos**.
 *
 * Antes había una sola marca de tiempo, `sync_ultimo_automatico`, que se
 * escribía corriera bien o mal y de la que además salía el «desde» del próximo
 * pedido al reloj. Con las dos cosas en la misma columna, un equipo caído
 * perdía marcaciones para siempre:
 *
 *     día 1  falla   → marca = día 1
 *     día 2  falla   → pide desde el día 1, marca = día 2
 *     día 3  anda    → pide desde el día 2   ← el día 1 no lo baja nadie
 *
 * Un reloj caído cinco días volvía con los últimos dos y los otros tres
 * quedaban en su buffer sin que nadie los pidiera. Ahora son dos columnas:
 *
 * - `sync_ultimo_automatico`: cuándo **corrió** la tarea. Se escribe siempre,
 *   ande o no el reloj. Es lo que evita repetir la corrida dentro del mismo
 *   minuto y lo que la ficha muestra como «última corrida».
 * - `sync_ultimo_exito` (esta): hasta cuándo se **trajo** información. Solo se
 *   escribe cuando el reloj contestó, y es de acá que sale el «desde». Mientras
 *   el equipo esté caído no se mueve, así que al volver se recupera todo el
 *   hueco de una.
 *
 * Se copia el valor que ya tenían los equipos configurados para que la primera
 * corrida después de esta migración no salga a pedir el historial entero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipos', function (Blueprint $tabla): void {
            $tabla->timestamp('sync_ultimo_exito')->nullable()->after('sync_ultimo_automatico');
        });

        DB::table('equipos')
            ->whereNotNull('sync_ultimo_automatico')
            ->update(['sync_ultimo_exito' => DB::raw('sync_ultimo_automatico')]);
    }

    public function down(): void
    {
        Schema::table('equipos', function (Blueprint $tabla): void {
            $tabla->dropColumn('sync_ultimo_exito');
        });
    }
};
