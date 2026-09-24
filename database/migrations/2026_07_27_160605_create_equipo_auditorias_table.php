<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de acciones sobre las marcaciones de los equipos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipo_auditorias', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('equipo_id')->nullable()->constrained('equipos')->nullOnDelete();
            // exportar | sincronizar | importar | limpiar | eliminar
            $table->string('accion', 20);
            $table->text('motivo')->nullable();
            // Foto del equipo al momento de la acción, sin comm_key. Vacía al importar CSV.
            $table->json('datos_equipo');
            // Cuántas marcaciones tenía el reloj, si se pudo leer.
            $table->unsignedInteger('en_equipo')->nullable();
            // Lo que llegó y qué pasó con cada marcación; las cuatro suman el total.
            $table->unsignedInteger('total_marcaciones')->nullable();
            $table->unsignedInteger('nuevas')->nullable();
            $table->unsignedInteger('repetidas')->nullable();
            $table->unsignedInteger('sin_funcionario')->nullable();
            $table->unsignedInteger('fallidas')->nullable();
            $table->string('desde', 10)->nullable();
            $table->string('hasta', 10)->nullable();
            $table->text('detalle')->nullable();
            $table->boolean('exito')->default(true);
            $table->string('ip_usuario', 45)->nullable();
            $table->timestamps();
            $table->foreignId('registerUser_id')->nullable()->constrained('users');
            $table->index(['equipo_id', 'accion']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipo_auditorias');
    }
};
