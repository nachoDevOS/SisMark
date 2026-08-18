<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla local (MySQL) que replica «Asistencia» del SIA (SQL Server 2008 R2):
 * las marcaciones de los funcionarios. Mismos campos, en camelCase.
 *
 * Suma id autoincremental y timestamps. A diferencia del resto de las tablas,
 * NO lleva eliminación lógica ni columnas de auditoría de baja: las marcaciones
 * no se dan de baja desde el sistema —entran del reloj o de un CSV y se
 * corrigen, no se borran—, y el `deleted_at IS NULL` que agregaba Eloquent
 * costaba caro sobre 4,4 millones de filas (le impide a MySQL usar la
 * optimización de MIN/MAX sobre el índice).
 *
 * El carnet va en `ci` (en el SIA es IdPersona). En el SIA la clave es compuesta
 * (IdPersona + Fecha + Hora); aquí eso pasa a un índice único (ci + fecha + hora)
 * que sirve de clave natural para el upsert idempotente. `ci` también se indexa
 * aparte para los joins con personas (sin FK: el legado tiene marcaciones
 * huérfanas). `hora` guarda solo la hora sobre la fecha base 1899-12-30.
 *
 * `equipo_id` dice de qué reloj salió la marcación, cuando salió de uno. Es lo
 * que permite auditar una corrida —«qué trajo este equipo el martes»— y separar
 * lo que registró un aparato de lo que cargó una persona a mano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asistencias', function (Blueprint $table): void {
            $table->id();
            $table->char('ci', 12);
            $table->dateTime('fecha');
            $table->dateTime('hora');
            $table->char('tipo', 1);

            // De qué reloj salió la marcación.
            //
            // Nullable porque la mayoría no vino de ninguno: los 4,4 millones
            // migrados del SIA, lo que se carga a mano por papeleta y lo que
            // entra por CSV —donde el archivo no dice de qué equipo se exportó—.
            // `nullOnDelete` para que dar de baja un equipo no se lleve puestas
            // sus marcaciones: la marcación es del funcionario, no del aparato.
            $table->foreignId('equipo_id')->nullable()->constrained('equipos')->nullOnDelete();

            $table->text('observacion')->nullable();
            $table->smallInteger('estado')->default(1);

            $table->timestamps();
            $table->foreignId('registerUser_id')->nullable()->constrained('users');

            $table->unique(['ci', 'fecha', 'hora']);
            $table->index('ci');
            // «Qué trajo este reloj y cuándo», que es como se lee la bitácora
            // cuando hay que auditar una corrida.
            $table->index(['equipo_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asistencias');
    }
};
