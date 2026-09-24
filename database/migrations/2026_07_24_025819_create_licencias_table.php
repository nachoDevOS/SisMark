<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Licencias y permisos, una fila por día y turno.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licencias', function (Blueprint $table): void {
            $table->id();
            $table->dateTime('fechaPedido');
            // Agrupa las filas de un mismo pedido (una por día y turno).
            $table->char('solicitud', 26)->nullable();
            // Por dónde entró: propio | mamore | sia.
            $table->string('origen', 10)->default('propio');
            // personal | institucional. Solo el personal cuenta contra el tope mensual.
            $table->string('tipo', 15);
            $table->string('usuario', 50);
            $table->date('fecha');
            $table->char('ci', 12);
            // Código del SIA, solo histórico: el horario va por `turno_id`.
            $table->char('idTurno', 3)->nullable();
            $table->foreignId('turno_id')->constrained('turnos');
            $table->time('lEntra')->nullable();
            $table->time('lSale')->nullable();
            $table->boolean('tCompleto');
            $table->string('motivo', 255)->nullable();
            $table->string('adjunto', 255)->nullable();
            $table->string('adjuntoNombre', 255)->nullable();
            $table->boolean('goceHaberes');
            $table->text('observacion')->nullable();
            $table->string('estado', 12)->default('Aprobado');
            $table->foreignId('revisadoPor_id')->nullable()->constrained('users');
            $table->dateTime('revisadoEn')->nullable();
            $table->timestamps();
            $table->foreignId('registerUser_id')->nullable()->constrained('users');
            $table->softDeletes();
            $table->foreignId('deleteUser_id')->nullable()->constrained('users');
            $table->text('deleteObservacion')->nullable();
            $table->index('ci');
            $table->index('fecha');
            $table->index(['solicitud', 'fecha']);
            $table->index(['estado', 'fecha']);
        });

        // Un turno se licencia una vez por pedido. Lo del SIA va sin solicitud y
        // queda único por (ci, fecha, turno_id); dos pedidos del mismo día conviven.
        DB::statement(
            'CREATE UNIQUE INDEX licencias_clave_natural_unique'
            ." ON licencias (ci, fecha, turno_id, (COALESCE(solicitud, '')))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('licencias');
    }
};
