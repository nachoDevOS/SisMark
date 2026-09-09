<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los sistemas que consumen la API de asistencia de SisMark.
 *
 * Antes había **una sola clave** en `SISMARK_API_KEY`, compartida por todos los
 * consumidores. Con una sola clave no se puede cortarle el acceso a uno sin
 * cortárselo a todos, ni saber cuál de ellos pidió qué, ni rotarla sin
 * coordinar con cada equipo el mismo día.
 *
 * Cada consumidor pasa a ser una fila con su propio token de Sanctum, su propio
 * interruptor `activo` y su propio rastro. Hoy el único es Mamoré.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sistemas_externos', function (Blueprint $tabla): void {
            $tabla->id();

            // Nombre corto y estable con el que se lo nombra en la consola:
            // `php artisan sismark:token mamore`.
            $tabla->string('slug', 50)->unique();
            $tabla->string('nombre', 100);
            $tabla->text('observaciones')->nullable();

            // El interruptor. Apagarlo corta el acceso en el próximo pedido sin
            // borrar el token, así una falsa alarma se revierte volviendo a
            // encenderlo y no coordinando una credencial nueva.
            $tabla->boolean('activo')->default(true);

            $tabla->timestamps();
            $tabla->foreignId('registerUser_id')->nullable()->constrained('users');

            $tabla->softDeletes();
            $tabla->foreignId('deleteUser_id')->nullable()->constrained('users');
            $tabla->text('deleteObservacion')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sistemas_externos');
    }
};
