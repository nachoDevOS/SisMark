<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Relojes biométricos ZKTeco.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipos', function (Blueprint $table): void {
            $table->id();
            $table->string('nombre');
            $table->string('ip');
            $table->unsignedInteger('puerto')->default(4370);
            $table->unsignedInteger('comm_key')->default(0);
            $table->string('ubicacion')->nullable();
            // Plataforma + firmware: define con qué equipos es compatible la huella.
            $table->string('algoritmo')->nullable();
            $table->boolean('es_master')->default(false);
            $table->boolean('en_linea')->default(false);
            $table->timestamp('ultima_sync')->nullable();
            $table->boolean('activo')->default(true);
            // Sincronización automática: horas y días (`Turno::DIAS`) en que corre.
            $table->boolean('sync_automatica')->default(false);
            $table->json('sync_horarios')->nullable();
            $table->json('sync_dias')->nullable();
            // Cuándo corrió por última vez y cuándo el reloj contestó.
            $table->timestamp('sync_ultimo_automatico')->nullable();
            $table->timestamp('sync_ultimo_exito')->nullable();
            $table->text('observacion')->nullable();
            $table->smallInteger('estado')->default(1);
            $table->timestamps();
            $table->foreignId('registerUser_id')->nullable()->constrained('users');
            $table->softDeletes();
            $table->foreignId('deleteUser_id')->nullable()->constrained('users');
            $table->text('deleteObservacion')->nullable();
            $table->unique(['ip', 'puerto']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipos');
    }
};
