<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de todo lo que se hace con las marcaciones de un equipo biométrico:
 * quién exportó el CSV, quién las mandó a la base del SIA, quién vació el reloj
 * y quién dio de baja el equipo.
 *
 * Guarda una copia de los datos del equipo (`datos_equipo`) en vez de depender
 * del join: si después le cambian la IP, la ubicación o lo eliminan, la fila de
 * la bitácora sigue mostrando cómo estaba el equipo en ese momento.
 *
 * De cada sincronización guarda el desglose completo —cuántas trajo el reloj,
 * cuántas se guardaron, cuántas ya estaban, cuántas no cruzaron con ningún
 * funcionario y cuántas fallaron— porque «se sincronizó» no alcanza para saber
 * si el equipo está aportando algo o repitiendo lo mismo todos los días.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipo_auditorias', function (Blueprint $table): void {
            $table->id();

            // Nullable: si algún día se borra físicamente un equipo, la bitácora
            // no se va con él (sus datos quedan igual en `datos_equipo`).
            $table->foreignId('equipo_id')->nullable()->constrained('equipos')->nullOnDelete();

            $table->string('accion', 20); // exportar | sincronizar | limpiar | eliminar
            $table->text('motivo')->nullable(); // Obligatorio en limpiar y eliminar.

            // Foto del equipo al momento de la acción (nombre, ip, puerto,
            // ubicación, algoritmo…). Sin comm_key: es la clave del reloj.
            $table->json('datos_equipo');

            // Cuántas marcaciones entregó el equipo para el rango pedido. Es el
            // total contra el que tienen que cerrar las cuatro columnas de
            // abajo: si no suman, algo se perdió en el camino.
            $table->unsignedInteger('total_marcaciones')->nullable();

            // Desglose de qué pasó con cada una de esas marcaciones. Sin esto la
            // bitácora decía «se sincronizó» y nada más: no había forma de saber
            // si un equipo trae 400 marcaciones nuevas por día o 400 repetidas,
            // ni de detectar que un funcionario marca todos los días y ninguna
            // de sus marcas entra porque su ID de reloj no cruza con el padrón.
            $table->unsignedInteger('nuevas')->nullable();          // Se guardaron.
            $table->unsignedInteger('repetidas')->nullable();       // Ya estaban en la base.
            $table->unsignedInteger('sin_funcionario')->nullable(); // El ID del reloj no cruza con `personas.ci`.
            $table->unsignedInteger('fallidas')->nullable();        // Fecha basura del RTC o error al insertar.
            // El reloj manda de más: el protocolo ZK no siempre respeta el rango
            // pedido. Se cuentan igual para que el desglose cierre contra el
            // total y ninguna marcación desaparezca sin dejar rastro.
            $table->unsignedInteger('fuera_de_rango')->nullable();

            $table->string('desde', 10)->nullable(); // Rango pedido, si hubo.
            $table->string('hasta', 10)->nullable();
            $table->text('detalle')->nullable(); // Mensaje de resultado o de error.
            $table->boolean('exito')->default(true);

            $table->string('ip_usuario', 45)->nullable(); // Desde qué máquina se hizo.

            $table->timestamps();

            // Misma convención de auditoría que el resto de las tablas.
            $table->foreignId('registerUser_id')->nullable()->constrained('users');

            $table->index(['equipo_id', 'accion']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipo_auditorias');
    }
};
