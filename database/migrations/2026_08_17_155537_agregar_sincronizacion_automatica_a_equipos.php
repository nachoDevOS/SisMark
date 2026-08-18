<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sincronización automática por equipo: a qué horas del día el sistema baja
 * solo las marcaciones de cada reloj, sin que nadie apriete el botón.
 *
 * Los horarios van en JSON (lista de «HH:MM») y no en una tabla aparte: son
 * unos pocos por equipo, se leen y se guardan siempre enteros, y nunca se
 * consultan por hora suelta —la tarea recorre los equipos y compara en PHP—.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipos', function (Blueprint $tabla): void {
            // Arranca apagada: un equipo recién registrado no empieza a hablar
            // con el reloj solo hasta que alguien lo decide.
            $tabla->boolean('sync_automatica')->default(false)->after('activo');
            $tabla->json('sync_horarios')->nullable()->after('sync_automatica');
            // Última corrida automática. Separada de `ultima_sync` (que marca
            // cualquier contacto con el equipo, incluido «probar conexión»)
            // para poder mostrar en la ficha cuándo trabajó sola la tarea y
            // para no repetir la misma hora dos veces en el mismo minuto.
            $tabla->timestamp('sync_ultimo_automatico')->nullable()->after('sync_horarios');
        });
    }

    public function down(): void
    {
        Schema::table('equipos', function (Blueprint $tabla): void {
            $tabla->dropColumn(['sync_automatica', 'sync_horarios', 'sync_ultimo_automatico']);
        });
    }
};
