<?php

use App\Services\SeparadorHorariosDeTurnos;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cada turno pasa a tener sus propios horarios (`horarios.turno_id`), en vez de
 * elegirlos de un catálogo compartido a través de `horario_turno`.
 *
 * Los datos los mueve {@see SeparadorHorariosDeTurnos}. La pivote no se borra:
 * queda como `horario_turno_respaldo`, con la copia que le tocó a cada par, para
 * poder volver atrás. Se elimina en una migración posterior, cuando producción
 * esté verificada.
 *
 * Los cambios de estructura van fuera de la transacción porque en MySQL cierran
 * la que esté abierta; la transacción cubre solo el movimiento de datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('horarios', function (Blueprint $table): void {
            // Nulo en lo heredado del SIA, que no pertenece a ningún turno.
            $table->foreignId('turno_id')->nullable()->after('id')->constrained('turnos');
        });

        Schema::table('horario_turno', function (Blueprint $table): void {
            $table->foreignId('horario_copia_id')->nullable()->constrained('horarios');
        });

        DB::transaction(fn () => app(SeparadorHorariosDeTurnos::class)->separar('horario_turno'));

        Schema::rename('horario_turno', 'horario_turno_respaldo');
    }

    public function down(): void
    {
        Schema::rename('horario_turno_respaldo', 'horario_turno');

        DB::transaction(fn () => app(SeparadorHorariosDeTurnos::class)->revertir('horario_turno'));

        Schema::table('horario_turno', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('horario_copia_id');
        });

        Schema::table('horarios', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('turno_id');
        });
    }
};
