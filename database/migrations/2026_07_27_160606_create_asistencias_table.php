<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marcaciones (SIA «Asistencia»). Sin eliminación lógica: una marcación no se da de baja.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asistencias', function (Blueprint $table): void {
            $table->id();
            $table->char('ci', 12);
            $table->date('fecha');
            $table->time('hora');
            $table->char('tipo', 1);
            // Reloj del que salió; null en lo del SIA, lo manual y lo importado por CSV.
            $table->foreignId('equipo_id')->nullable()->constrained('equipos')->nullOnDelete();
            // Sincronización o importación que la guardó.
            $table->foreignId('equipo_auditoria_id')->nullable()->constrained('equipo_auditorias')->nullOnDelete();
            $table->text('observacion')->nullable();
            $table->timestamps();
            $table->foreignId('registerUser_id')->nullable()->constrained('users');
            $table->unique(['ci', 'fecha', 'hora']);
            $table->index('ci');
            $table->index(['fecha', 'ci']);
            $table->index(['equipo_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asistencias');
    }
};
