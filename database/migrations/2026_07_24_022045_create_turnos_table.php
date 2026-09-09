<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla local (MySQL) que replica «DiaTurnos» del SIA (SQL Server 2008 R2):
 * los horarios (turnos) por día de la semana. Mismos campos, en camelCase.
 *
 * Igual que el resto: id autoincremental, timestamps y eliminación lógica;
 * idTurno (PK en el SIA) pasa a columna única. Las horas van como datetime
 * sobre la fecha base 1899-12-30 (solo importa la hora), como el SIA real.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('turnos', function (Blueprint $table): void {
            $table->id();
            $table->char('idTurno', 3)->unique();
            $table->char('dia', 1);
            $table->string('nombreTurno', 25);
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

            // Cuál de los turnos forma el horario que se ofrece por defecto al
            // dar de alta un contrato. No existe en el SIA: es propio, y por eso
            // no lo toca `sia:migrar-horarios` —no está en su MAPA, así que
            // recopiar los horarios no borra lo marcado—.
            //
            // Son varias filas y no una: la tabla guarda un día por fila, así
            // que un horario de lunes a viernes son cinco turnos marcados. El
            // índice lleva `dia` adentro porque el único acceso por esta columna
            // es «traeme los sugeridos, ordenados por día».
            $table->boolean('sugerido')->default(false);
            $table->index(['sugerido', 'dia'], 'turnos_sugerido_dia_index');

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
        Schema::dropIfExists('turnos');
    }
};
