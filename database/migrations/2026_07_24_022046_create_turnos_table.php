<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turnos: la jornada semanal que agrupa horarios. Solo se crean y se eliminan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('turnos', function (Blueprint $table): void {
            $table->id();
            $table->string('nombre', 60);
            // Se ofrece al asignar desde Mamoré.
            $table->boolean('sugerido')->default(false);
            $table->text('observacion')->nullable();
            $table->smallInteger('estado')->default(1);
            $table->timestamps();
            $table->foreignId('registerUser_id')->nullable()->constrained('users');
            $table->softDeletes();
            $table->foreignId('deleteUser_id')->nullable()->constrained('users');
            $table->text('deleteObservacion')->nullable();
            $table->index('sugerido');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('turnos');
    }
};
