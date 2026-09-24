<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turnos asignados a cada funcionario (SIA «AsignacionTurnos»).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asignacion_turnos', function (Blueprint $table): void {
            $table->id();
            $table->char('ci', 12);
            $table->char('idTurno', 3);
            $table->foreignId('turno_id')->nullable()->constrained('turnos');
            $table->dateTime('desde');
            $table->dateTime('hasta');
            // Contrato de Mamoré con el que se asignó.
            $table->unsignedBigInteger('contrato_id')->nullable();
            $table->text('observacion')->nullable();
            $table->smallInteger('estado')->default(1);
            $table->timestamps();
            $table->foreignId('registerUser_id')->nullable()->constrained('users');
            $table->softDeletes();
            $table->foreignId('deleteUser_id')->nullable()->constrained('users');
            $table->text('deleteObservacion')->nullable();
            $table->unique(['ci', 'idTurno', 'desde']);
            $table->index('ci');
            $table->index('contrato_id');
            $table->index(['hasta', 'desde'], 'asignacion_turnos_vigencia_index');
            $table->index(['desde', 'ci'], 'asignacion_turnos_desde_ci_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asignacion_turnos');
    }
};
