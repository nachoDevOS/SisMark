<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personas copiadas del SIA («Personas»).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personas', function (Blueprint $table): void {
            $table->id();
            $table->char('ci', 12)->unique();
            $table->char('origenId', 3)->nullable();
            $table->string('paterno', 25);
            $table->string('materno', 25)->nullable();
            $table->string('nombres', 35);
            $table->dateTime('fechaNacimiento')->nullable();
            $table->string('lugarNacimiento', 25)->nullable();
            $table->char('sexo', 1)->nullable();
            $table->char('estadoCivil', 1)->nullable();
            $table->char('codigoProfesion', 2)->nullable();
            $table->string('nivelEstudio', 20)->nullable();
            $table->string('telefono', 20)->nullable();
            $table->string('direccion', 40)->nullable();
            $table->string('correo', 40)->nullable();
            $table->boolean('marcaDirecta');
            $table->string('pinReloj', 10)->nullable();
            $table->text('observacion')->nullable();
            $table->smallInteger('estado')->default(1);
            $table->timestamps();
            $table->foreignId('registerUser_id')->nullable()->constrained('users');
            $table->softDeletes();
            $table->foreignId('deleteUser_id')->nullable()->constrained('users');
            $table->text('deleteObservacion')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personas');
    }
};
