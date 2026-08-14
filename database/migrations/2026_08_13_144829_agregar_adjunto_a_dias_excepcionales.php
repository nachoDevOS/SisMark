<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Respaldo del día excepcional: el decreto, la resolución o el memorándum que
 * declara el feriado o la tolerancia.
 *
 * Mismas dos columnas que `licencias`, con el mismo criterio: se guarda la
 * **ruta** dentro del disco `s3`, no el archivo ni una URL absoluta, porque el
 * endpoint y el bucket viven en la configuración y mover el almacenamiento no
 * puede obligar a reescribir filas.
 *
 * Es opcional: hay tolerancias que se cargan por instrucción verbal y el
 * documento llega después, o nunca.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dias_excepcionales', function (Blueprint $table): void {
            $table->string('adjunto', 255)->nullable()->after('motivoInasistencia');
            $table->string('adjuntoNombre', 255)->nullable()->after('adjunto');
        });
    }

    public function down(): void
    {
        Schema::table('dias_excepcionales', function (Blueprint $table): void {
            $table->dropColumn(['adjunto', 'adjuntoNombre']);
        });
    }
};
