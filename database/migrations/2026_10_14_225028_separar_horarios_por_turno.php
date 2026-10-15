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
 * Los datos los mueve {@see SeparadorHorariosDeTurnos}; después la pivote se
 * elimina. Volver atrás la reconstruye con los horarios de cada turno (las
 * copias): asignaciones y licencias ya apuntan a ellas, así que lo procesado
 * sigue dando lo mismo.
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

        // Dónde quedó la copia de cada par: el separador la usa para no
        // duplicar y para verificar. Se va con la pivote.
        Schema::table('horario_turno', function (Blueprint $table): void {
            $table->foreignId('horario_copia_id')->nullable()->constrained('horarios');
        });

        DB::transaction(fn () => app(SeparadorHorariosDeTurnos::class)->separar('horario_turno'));

        Schema::drop('horario_turno');
    }

    public function down(): void
    {
        Schema::create('horario_turno', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('turno_id')->constrained('turnos');
            $table->foreignId('horario_id')->constrained('horarios');
            $table->timestamps();
            $table->unique(['turno_id', 'horario_id']);
        });

        DB::table('horario_turno')->insertUsing(
            ['turno_id', 'horario_id', 'created_at', 'updated_at'],
            DB::table('horarios')->whereNotNull('turno_id')->select('turno_id', 'id', 'created_at', 'updated_at'),
        );

        Schema::table('horarios', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('turno_id');
        });
    }
};
