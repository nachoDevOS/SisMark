<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `idHorario` (el código del SIA) deja de escribirse: el horario va siempre por
 * la FK `horario_id`. Lo ya cargado conserva su código como dato histórico.
 *
 * La única de `asignacion_horarios` pasa de `(ci, idHorario, desde)` a
 * `(ci, horario_id, desde)`: con el código nulo, la vieja ya no protegería las
 * filas nuevas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('horarios', function (Blueprint $table): void {
            $table->char('idHorario', 3)->nullable()->change();
        });

        Schema::table('asignacion_horarios', function (Blueprint $table): void {
            $table->char('idHorario', 3)->nullable()->change();
            $table->dropUnique(['ci', 'idHorario', 'desde']);
            $table->unique(['ci', 'horario_id', 'desde']);
        });
    }

    public function down(): void
    {
        Schema::table('asignacion_horarios', function (Blueprint $table): void {
            $table->dropUnique(['ci', 'horario_id', 'desde']);
            $table->unique(['ci', 'idHorario', 'desde']);
            $table->char('idHorario', 3)->nullable(false)->change();
        });

        Schema::table('horarios', function (Blueprint $table): void {
            $table->char('idHorario', 3)->nullable(false)->change();
        });
    }
};
