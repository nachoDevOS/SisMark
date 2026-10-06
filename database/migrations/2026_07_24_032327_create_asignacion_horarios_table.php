<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Horarios asignados a cada funcionario (SIA «AsignacionTurnos»). Lo nuevo entra
 * como detalle de un turno asignado (`asignacion_turno_id`); lo del SIA queda
 * con la columna en null, como historia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asignacion_horarios', function (Blueprint $table): void {
            $table->id();
            $table->char('ci', 12);
            $table->char('idHorario', 3);
            $table->foreignId('horario_id')->nullable()->constrained('horarios');
            $table->foreignId('asignacion_turno_id')->nullable()->constrained('asignacion_turnos');
            $table->date('desde');
            $table->date('hasta');
            // Contrato de Mamoré con el que se asignó.
            $table->unsignedBigInteger('contrato_id')->nullable();
            $table->text('observacion')->nullable();
            $table->smallInteger('estado')->default(1);
            $table->timestamps();
            $table->foreignId('registerUser_id')->nullable()->constrained('users');
            $table->softDeletes();
            $table->foreignId('deleteUser_id')->nullable()->constrained('users');
            $table->text('deleteObservacion')->nullable();
            $table->unique(['ci', 'idHorario', 'desde']);
            $table->index('ci');
            $table->index('contrato_id');
            $table->index(['hasta', 'desde'], 'asignacion_horarios_vigencia_index');
            $table->index(['desde', 'ci'], 'asignacion_horarios_desde_ci_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asignacion_horarios');
    }
};
