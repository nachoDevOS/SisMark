<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cada marcación guarda qué sincronización la trajo, y la bitácora deja de
 * tener la columna `fuera_de_rango`.
 *
 * `asistencias.equipo_auditoria_id` apunta a la entrada de la bitácora de la
 * corrida que insertó la fila. Con eso, desde la bitácora se llega a las
 * marcaciones nuevas de cada sincronización, y desde una marcación a la corrida
 * que la trajo. Queda nula en lo migrado del SIA, en el alta manual y en el
 * CSV: ninguna de esas fuentes es una sincronización.
 *
 * Las repetidas no se vinculan: el reloj entrega su historial entero en cada
 * lectura, así que guardarlas una por una sumaría el buffer completo por cada
 * corrida. Una repetida ya tiene su propio `equipo_auditoria_id`, el de la
 * corrida que la trajo primero.
 *
 * `nullOnDelete` por si alguna vez se borra una entrada de la bitácora: la
 * marcación es del funcionario y no se va con el registro de auditoría.
 *
 * `fuera_de_rango` se quita porque la sincronización baja el buffer completo
 * sin pedir rango, así que siempre valía 0.
 *
 * Sobre la tabla de 4,4 millones de filas, agregar la columna es instantáneo
 * en MySQL 8, pero el índice de la clave foránea se construye leyendo la tabla
 * entera: conviene desplegar fuera del horario de uso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asistencias', function (Blueprint $tabla): void {
            $tabla->foreignId('equipo_auditoria_id')
                ->nullable()
                ->after('equipo_id')
                ->constrained('equipo_auditorias')
                ->nullOnDelete();
        });

        Schema::table('equipo_auditorias', function (Blueprint $tabla): void {
            $tabla->dropColumn('fuera_de_rango');
        });
    }

    public function down(): void
    {
        Schema::table('equipo_auditorias', function (Blueprint $tabla): void {
            $tabla->unsignedInteger('fuera_de_rango')->nullable()->after('fallidas');
        });

        Schema::table('asistencias', function (Blueprint $tabla): void {
            $tabla->dropConstrainedForeignId('equipo_auditoria_id');
        });
    }
};
