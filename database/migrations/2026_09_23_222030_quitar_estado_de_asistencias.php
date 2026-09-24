<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quita `asistencias.estado`: nada la leía.
 *
 * Valía 1 en todas las filas —las 4,4 millones migradas del SIA y todo lo que
 * entró después—, y el único código que la tocaba era el insert, que la ponía
 * fija en 1. Ni el listado, ni los reportes, ni la API filtraban por ella.
 *
 * Sobre una tabla de este tamaño, MySQL 8 reconstruye la tabla al quitar una
 * columna: conviene desplegar fuera del horario de uso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asistencias', function (Blueprint $table): void {
            $table->dropColumn('estado');
        });
    }

    public function down(): void
    {
        Schema::table('asistencias', function (Blueprint $table): void {
            $table->smallInteger('estado')->default(1)->after('observacion');
        });
    }
};
