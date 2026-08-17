<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quién resolvió la licencia y cuándo.
 *
 * La columna `estado` ya existía —«Pendiente», «Aprobado», «Rechazado»—, pero
 * nada registraba quién la movió: una licencia aprobada justifica una ausencia,
 * así que sin autor la decisión no se le puede atribuir a nadie. `registerUser_id`
 * no sirve para esto: es quién dio el alta, y en las solicitudes que llegan de
 * Mamoré viene nulo justamente porque el funcionario no tiene usuario acá.
 *
 * El motivo del rechazo va en `observacion`, que ya existe en la tabla y hasta
 * ahora ninguna pantalla escribía. Es el texto que el funcionario necesita leer
 * para saber por qué le rechazaron el pedido.
 *
 * Las dos columnas son nullable porque lo que ya está en la tabla nunca pasó por
 * una revisión: el millón de filas migradas del SIA y lo que carga Recursos
 * Humanos a mano nacen «Aprobado» y surten efecto solas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licencias', function (Blueprint $table): void {
            $table->foreignId('revisadoPor_id')->nullable()->after('estado')->constrained('users');
            $table->dateTime('revisadoEn')->nullable()->after('revisadoPor_id');
        });
    }

    public function down(): void
    {
        Schema::table('licencias', function (Blueprint $table): void {
            $table->dropForeign(['revisadoPor_id']);
            $table->dropColumn(['revisadoPor_id', 'revisadoEn']);
        });
    }
};
