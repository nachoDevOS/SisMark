<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Respaldo de la licencia: el certificado médico, el memorándum o la nota que
 * la justifica.
 *
 * Va en `licencias` y no en una tabla aparte porque un alta expande el rango a
 * una fila por día y turno, y todas comparten el mismo respaldo: guardar la
 * ruta en cada fila deja el archivo a mano desde cualquiera de ellas, sin un
 * join extra en el listado —que es donde se lo va a mirar—.
 *
 * Se guarda la **ruta** dentro del disco `s3`, no el archivo ni una URL
 * absoluta: el endpoint y el bucket viven en la configuración, así que mover el
 * almacenamiento o cambiar de entorno no obliga a reescribir un millón de
 * filas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licencias', function (Blueprint $table): void {
            $table->string('adjunto', 255)->nullable()->after('motivo');
            $table->string('adjuntoNombre', 255)->nullable()->after('adjunto');
        });
    }

    public function down(): void
    {
        Schema::table('licencias', function (Blueprint $table): void {
            $table->dropColumn(['adjunto', 'adjuntoNombre']);
        });
    }
};
