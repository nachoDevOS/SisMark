<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índice `(desde, ci)` para el orden del listado de turnos asignados.
 *
 * La pantalla ordena por `desde DESC, ci` y ninguno de los índices que había
 * empieza por `desde`: el único que lo tiene es `(ci, idTurno, desde)`, donde va
 * tercero, y `(hasta, desde)` lo tiene detrás de `hasta`. Sin un índice que
 * arranque ahí, MySQL ordena las 420.721 filas en memoria para devolver diez
 * —medido, 396 ms por carga— y el costo lo paga toda página del listado, con
 * filtro o sin él.
 *
 * Las dos columnas y en ese orden: `desde` resuelve el orden principal y `ci`
 * el desempate, así que el índice lo cubre entero y no queda un `filesort`
 * residual para las filas que comparten fecha (son muchas: una asignación
 * semanal deja cinco filas con el mismo `desde`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asignacion_turnos', function (Blueprint $tabla): void {
            $tabla->index(['desde', 'ci'], 'asignacion_turnos_desde_ci_index');
        });
    }

    public function down(): void
    {
        Schema::table('asignacion_turnos', function (Blueprint $tabla): void {
            $tabla->dropIndex('asignacion_turnos_desde_ci_index');
        });
    }
};
