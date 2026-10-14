<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de cambios de cada usuario: foto legible antes y después.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_historials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // creado | actualizado | cambio_password | eliminado
            $table->string('accion', 20);
            $table->json('antes')->nullable();
            $table->json('despues')->nullable();
            $table->timestamps();
            $table->foreignId('registerUser_id')->nullable()->constrained('users');
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_historials');
    }
};
