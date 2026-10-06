<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los horarios que forman cada turno.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('horario_turno', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('turno_id')->constrained('turnos');
            $table->foreignId('horario_id')->constrained('horarios');
            $table->timestamps();
            $table->unique(['turno_id', 'horario_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('horario_turno');
    }
};
