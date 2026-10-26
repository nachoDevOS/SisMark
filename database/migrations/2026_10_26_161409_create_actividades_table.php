<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Actividad de usuarios: cada pedido al sistema (menos contraseñas y tokens),
 * y los eventos importantes con su frase legible. Ver docs/ACTIVIDAD-DE-USUARIOS.md.
 *
 * Sin `updated_at` ni baja lógica: la fila no cambia nunca.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('actividades', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('sistema_externo_id')->nullable()->constrained('sistemas_externos')->nullOnDelete();
            // El correo escrito en un intento de ingreso fallido o rechazado.
            $table->string('email', 150)->nullable();
            $table->boolean('importante')->default(false);
            $table->string('accion', 30);
            $table->string('modulo', 40);
            $table->string('descripcion', 500)->nullable();
            $table->string('ci', 20)->nullable();
            $table->json('datos')->nullable();
            $table->string('ruta', 120)->nullable();
            $table->string('url', 500);
            $table->string('metodo', 10);
            $table->unsignedSmallInteger('estado');
            $table->unsignedInteger('duracion_ms');
            $table->string('ip', 45)->nullable();
            $table->string('navegador', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['importante', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['ci', 'created_at']);
            $table->index(['accion', 'created_at']);
            $table->index(['sistema_externo_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('actividades');
    }
};
