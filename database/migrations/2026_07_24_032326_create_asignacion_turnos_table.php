<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turno asignado a cada funcionario en un rango de fechas. Es la cabecera: sus
 * horarios día por día quedan como detalle en `asignacion_horarios`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asignacion_turnos', function (Blueprint $table): void {
            $table->id();
            $table->char('ci', 12);
            $table->foreignId('turno_id')->constrained('turnos');
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
            $table->index('ci');
            $table->index('contrato_id');
            $table->index(['hasta', 'desde'], 'asignacion_turnos_vigencia_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asignacion_turnos');
    }
};
