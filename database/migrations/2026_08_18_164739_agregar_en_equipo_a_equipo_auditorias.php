<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuántas marcaciones dice el reloj que tiene guardadas, al momento de la
 * sincronización.
 *
 * Hasta ahora la bitácora solo sabía cuántas habían **llegado**
 * (`total_marcaciones`), y la pantalla las rotulaba «en el equipo». No es lo
 * mismo: si el reloj tiene 1500 y la lectura se corta en la 1499, se registran
 * 1499 y nada delata que falta una. La corrida se anota como exitosa, la marca
 * perdida no se vuelve a pedir nunca y desaparece en cuanto alguien limpia el
 * buffer del equipo.
 *
 * Con las dos cifras la bitácora responde dos preguntas distintas:
 *
 *     en_equipo          = total_marcaciones + lo que se perdió en el camino
 *     total_marcaciones  = nuevas + repetidas + sin_funcionario + fallidas + fuera_de_rango
 *
 * La primera mide el transporte (reloj → SisMark), la segunda el destino
 * (SisMark → base de datos). Antes solo existía la segunda, y arrancaba de un
 * número que nadie había comprobado.
 *
 * Nullable porque no siempre hay dato: las acciones que no leen el buffer
 * (exportar, limpiar, eliminar) no lo tienen, y un reloj que no contesta
 * tampoco. Ausente significa «no se pudo comprobar», que es distinto de cero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipo_auditorias', function (Blueprint $table): void {
            $table->unsignedInteger('en_equipo')->nullable()->after('datos_equipo');
        });
    }

    public function down(): void
    {
        Schema::table('equipo_auditorias', function (Blueprint $table): void {
            $table->dropColumn('en_equipo');
        });
    }
};
