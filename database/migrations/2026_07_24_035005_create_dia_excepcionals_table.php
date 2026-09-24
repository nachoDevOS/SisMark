<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feriados y tolerancias (SIA «Calendario»).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dias_excepcionales', function (Blueprint $table): void {
            $table->id();
            $table->dateTime('fecha');
            $table->string('motivoInasistencia', 255)->nullable();
            $table->string('adjunto', 255)->nullable();
            $table->string('adjuntoNombre', 255)->nullable();
            $table->text('observacion')->nullable();
            $table->smallInteger('estado')->default(1);
            $table->timestamps();
            $table->foreignId('registerUser_id')->nullable()->constrained('users');
            $table->softDeletes();
            $table->foreignId('deleteUser_id')->nullable()->constrained('users');
            $table->text('deleteObservacion')->nullable();
            $table->index('fecha');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dias_excepcionales');
    }
};
