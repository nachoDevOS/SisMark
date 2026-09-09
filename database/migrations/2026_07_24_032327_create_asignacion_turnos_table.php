<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla local (MySQL) que replica «AsignacionTurnos» del SIA (SQL Server 2008 R2):
 * qué turno tiene asignado cada funcionario en un rango de fechas. Campos en
 * camelCase, con id/timestamps/eliminación lógica propios.
 *
 * El carnet va en `ci` (en el SIA es IdPersona). Se conserva `idTurno` (el
 * código de turno del SIA) y además se agrega la FK real `turno_id` → `turnos.id`,
 * que el comando de copia resuelve cruzando `idTurno` contra la tabla `turnos`
 * ya migrada. Por eso `turnos` debe migrarse antes; si un idTurno no cruza,
 * `turno_id` queda null. Clave natural (upsert): ci + idTurno + desde.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asignacion_turnos', function (Blueprint $table): void {
            $table->id();
            $table->char('ci', 12);
            $table->char('idTurno', 3);
            $table->foreignId('turno_id')->nullable()->constrained('turnos');
            $table->dateTime('desde');
            $table->dateTime('hasta');

            // El contrato de Mamoré que originó la asignación, cuando la creó él
            // desde la API y no una carga a mano ni la copia del SIA.
            //
            // Es el vínculo que permite corregirla después. Un contrato se
            // renueva por adenda, se concluye antes de tiempo o se le mueve la
            // fecha de fin, y sin esta columna no había forma de saber cuáles de
            // las asignaciones de un funcionario había que mover con él: la única
            // referencia era el texto de `observacion`, que cualquiera puede
            // editar desde la pantalla de Turnos.
            //
            // Guarda el **id** y no el código del contrato porque Mamoré
            // regenera el código cuando cambia el año de inicio o la dirección
            // administrativa; el id no cambia nunca.
            //
            // No lleva clave foránea: los contratos viven en la base de Mamoré,
            // no acá. Y va nullable porque las ~14.000 filas que arrastra el SIA
            // y todo lo que Recursos Humanos carga a mano no tienen contrato que
            // apuntar.
            $table->unsignedBigInteger('contrato_id')->nullable();
            $table->index('contrato_id');

            $table->text('observacion')->nullable();
            $table->smallInteger('estado')->default(1);

            $table->timestamps();
            $table->foreignId('registerUser_id')->nullable()->constrained('users');

            $table->softDeletes();
            $table->foreignId('deleteUser_id')->nullable()->constrained('users');
            $table->text('deleteObservacion')->nullable();

            $table->unique(['ci', 'idTurno', 'desde']);
            $table->index('ci');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asignacion_turnos');
    }
};
