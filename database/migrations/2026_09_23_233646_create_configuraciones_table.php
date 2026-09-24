<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parámetros del sistema, clave → valor (ver `Configuracion::PARAMETROS`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuraciones', function (Blueprint $table): void {
            $table->id();
            $table->string('clave', 100)->unique();
            $table->text('valor')->nullable();
            $table->timestamps();
            $table->foreignId('registerUser_id')->nullable()->constrained('users');
            $table->foreignId('updateUser_id')->nullable()->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuraciones');
    }
};
