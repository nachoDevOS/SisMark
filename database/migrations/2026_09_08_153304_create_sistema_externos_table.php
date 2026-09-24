<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sistemas que consumen la API v1 con token (Sanctum).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sistemas_externos', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 50)->unique();
            $table->string('nombre', 100);
            $table->text('observaciones')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->foreignId('registerUser_id')->nullable()->constrained('users');
            $table->softDeletes();
            $table->foreignId('deleteUser_id')->nullable()->constrained('users');
            $table->text('deleteObservacion')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sistemas_externos');
    }
};
