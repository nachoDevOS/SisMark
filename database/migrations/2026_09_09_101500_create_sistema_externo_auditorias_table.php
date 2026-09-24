<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quién emitió o revocó cada token de la API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sistema_externo_auditorias', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sistema_externo_id')->constrained('sistemas_externos')->cascadeOnDelete();
            $table->unsignedBigInteger('token_id')->nullable();
            // emitir | revocar
            $table->string('accion', 20);
            $table->string('alcances', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['sistema_externo_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sistema_externo_auditorias');
    }
};
