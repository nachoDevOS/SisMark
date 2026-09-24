<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Horarios copiados del SIA («DiaTurnos»).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('turnos', function (Blueprint $table): void {
            $table->id();
            $table->char('idTurno', 3)->unique();
            // Día de la semana del SIA: 1 = domingo … 7 = sábado.
            $table->char('dia', 1);
            $table->string('nombreTurno', 25);
            // Horas sobre la fecha base 1899-12-30, como las guarda el SIA.
            $table->dateTime('hEntrada');
            $table->dateTime('hSalida');
            $table->dateTime('hTolerancia');
            $table->dateTime('eMinima');
            $table->dateTime('eMaxima');
            $table->dateTime('sMinima');
            $table->dateTime('sMaxima');
            $table->dateTime('sTolerancia');
            $table->decimal('hTrabajadas', 19, 4);
            $table->boolean('siguienteDia');
            // Se ofrece al asignar turnos desde Mamoré.
            $table->boolean('sugerido')->default(false);
            $table->text('observacion')->nullable();
            $table->smallInteger('estado')->default(1);
            $table->timestamps();
            $table->foreignId('registerUser_id')->nullable()->constrained('users');
            $table->softDeletes();
            $table->foreignId('deleteUser_id')->nullable()->constrained('users');
            $table->text('deleteObservacion')->nullable();
            $table->index(['sugerido', 'dia'], 'turnos_sugerido_dia_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('turnos');
    }
};
