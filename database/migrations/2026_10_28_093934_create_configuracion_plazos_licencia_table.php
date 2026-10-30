<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plazo para registrar licencias: cuántos días hacia atrás y hacia adelante de
 * hoy se puede pedir (ver `ConfiguracionPlazoLicencia`), con vigencia por día. Cada cambio
 * agrega una fila; la que rige hoy tiene `vigente_hasta` en null y cargar la
 * siguiente la cierra sola. Tabla nueva: no toca nada de lo que ya existe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuracion_plazos_licencia', function (Blueprint $table): void {
            $table->id();
            // 0: sin límite.
            $table->unsignedSmallInteger('dias_anticipacion')->default(0);
            $table->unsignedSmallInteger('dias_atraso')->default(0);
            $table->boolean('dias_habiles')->default(false);
            $table->date('vigente_desde')->unique();
            // Null: rige en adelante, hasta que se cargue otro.
            $table->date('vigente_hasta')->nullable();
            $table->text('motivo');
            $table->timestamps();
            $table->foreignId('registerUser_id')->nullable()->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracion_plazos_licencia');
    }
};
