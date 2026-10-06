<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parámetros del sistema, clave → valor (ver `Configuracion::PARAMETROS`), con
 * vigencia por mes: cada cambio agrega una fila que rige desde `vigente_desde`
 * (siempre el día 1) hasta `vigente_hasta` (el último día de un mes). La que
 * rige hoy tiene `vigente_hasta` en null; al cargar la siguiente, se cierra
 * sola. No se pisa nada: queda el registro de qué regía cada mes, quién lo
 * cargó y por qué.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuraciones', function (Blueprint $table): void {
            $table->id();
            $table->string('clave', 100);
            $table->text('valor')->nullable();
            $table->date('vigente_desde');
            // Null: rige en adelante, hasta que se cargue otra.
            $table->date('vigente_hasta')->nullable();
            $table->text('motivo');
            $table->timestamps();
            $table->foreignId('registerUser_id')->nullable()->constrained('users');
            $table->unique(['clave', 'vigente_desde']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuraciones');
    }
};
