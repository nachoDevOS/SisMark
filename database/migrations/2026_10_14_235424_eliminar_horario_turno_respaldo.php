<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Elimina `horario_turno_respaldo`, la pivote vieja que dejó en producción la
 * primera versión de `separar_horarios_por_turno` (la renombraba en vez de
 * borrarla). El sistema ya no la usa: los horarios de cada turno van por
 * `horarios.turno_id`.
 *
 * En una base que migró con la versión actual la tabla no existe, por eso
 * `dropIfExists`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('horario_turno_respaldo');
    }

    /**
     * No se recrea: no guardaba nada que el sistema use, y volver atrás
     * `separar_horarios_por_turno` rearma `horario_turno` desde `horarios.turno_id`.
     */
    public function down(): void {}
};
