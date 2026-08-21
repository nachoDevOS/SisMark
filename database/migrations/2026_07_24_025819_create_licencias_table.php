<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla local (MySQL) que replica «Licencias» del SIA (SQL Server 2008 R2):
 * permisos/licencias de los funcionarios (comisiones, vacaciones, etc.).
 * Mismos campos que la tabla legada, en camelCase.
 *
 * Igual que el resto de la migración SIA→MySQL: id autoincremental, timestamps
 * y eliminación lógica propios. El carnet va en `ci` (en el SIA es IdPersona).
 * La clave natural (una licencia por funcionario, día y turno) pasa a un índice
 * único (ci + fecha + turno_id) para el upsert idempotente; `ci` se indexa aparte
 * para los joins con personas.
 *
 * `lEntra`/`lSale` son columnas `time`: guardan **solo la hora**. El SIA las
 * traía como datetime sobre la fecha base 1899-12-30 —herencia de Delphi, donde
 * una hora suelta es un datetime con el día en el origen del calendario—, pero
 * ese día no significa nada: la licencia ya tiene su `fecha` propia en la
 * columna de al lado. Guardarlo confundía a quien miraba la tabla y no aportaba
 * un dato. `MigrarLicenciasSia` recorta la fecha al copiar.
 *
 * El horario se referencia por la FK real `turno_id` → `turnos.id`, que el
 * comando de copia resuelve cruzando el IdTurno del SIA contra `turnos` (por eso
 * los horarios se migran antes). La columna `idTurno` se conserva **solo como
 * dato histórico** de lo que trajo el SIA: el sistema no la escribe, es nullable
 * y no entra en ningún índice ni en el `$fillable` del modelo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licencias', function (Blueprint $table): void {
            $table->id();
            $table->dateTime('fechaPedido');
            $table->string('usuario', 50);
            $table->date('fecha');
            $table->char('ci', 12);
            // Solo histórico (lo que trajo el SIA); el sistema no lo escribe.
            $table->char('idTurno', 3)->nullable();
            $table->foreignId('turno_id')->constrained('turnos');
            // Solo la hora: el día lo pone `fecha`.
            $table->time('lEntra')->nullable();
            $table->time('lSale')->nullable();
            $table->boolean('tCompleto');
            $table->string('motivo', 255)->nullable();
            $table->boolean('goceHaberes');

            $table->text('observacion')->nullable();
            // Estado de aprobación: «Pendiente», «Aprobado» o «Rechazado».
            //
            // El default es «Aprobado» porque es lo que corresponde a todo lo que
            // entra por los dos caminos que ya existen: lo que copia
            // `MigrarLicenciasSia` del SIA y lo que carga Recursos Humanos a mano.
            // Esas licencias surten efecto desde el momento en que se registran;
            // nadie las aprueba después.
            //
            // «Pendiente» es solo para lo que solicita el funcionario desde
            // Mamoré, que sí necesita el visto bueno de Recursos Humanos. Por eso
            // el default no puede ser «Pendiente»: dejaría sin efecto un millón de
            // licencias históricas, y `MigrarLicenciasSia` —que no escribe esta
            // columna— traería del SIA licencias ya otorgadas como si estuvieran
            // esperando aprobación.
            $table->string('estado', 12)->default('Aprobado');

            $table->timestamps();
            $table->foreignId('registerUser_id')->nullable()->constrained('users');

            $table->softDeletes();
            $table->foreignId('deleteUser_id')->nullable()->constrained('users');
            $table->text('deleteObservacion')->nullable();

            $table->unique(['ci', 'fecha', 'turno_id']);
            $table->index('ci');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licencias');
    }
};
