<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turnos: la jornada semanal con sus propios horarios. Solo se crean y se
 * eliminan.
 *
 * Acá también va `horarios.turno_id`: `horarios` se crea antes que `turnos`, así
 * que la FK no puede declararse allá.
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

        Schema::table('horarios', function (Blueprint $table): void {
            // El turno dueño del horario; null en lo heredado del SIA.
            $table->foreignId('turno_id')->nullable()->after('id')->constrained('turnos');
        });
    }

    public function down(): void
    {
        Schema::table('horarios', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('turno_id');
        });

        Schema::dropIfExists('turnos');
    }
};
