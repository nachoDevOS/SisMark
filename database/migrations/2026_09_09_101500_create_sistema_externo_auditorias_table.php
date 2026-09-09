<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de los tokens de la API: quién emitió o revocó cuál, cuándo y desde
 * dónde.
 *
 * El token en sí no se puede auditar después de entregado —la base guarda solo
 * su hash y el texto se muestra una sola vez—, así que si no queda anotado acá
 * no queda en ningún lado. Entregar un token es dar acceso a la asistencia de
 * los ~4.600 funcionarios: tiene que haber un nombre atrás de esa decisión.
 *
 * Se escribe sola desde el controlador y no se edita ni se borra, igual que
 * `equipo_auditorias`. Por eso lleva `created_at` a secas y no `timestamps()`:
 * una fila que no se actualiza nunca no necesita `updated_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sistema_externo_auditorias', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->foreignId('sistema_externo_id')->constrained('sistemas_externos')->cascadeOnDelete();

            // El id del token, no su hash: sirve para seguir un token puntual a
            // lo largo de la bitácora —emitido acá, revocado allá— sin guardar
            // nada que se parezca a la credencial.
            //
            // Sin clave foránea a propósito: revocar borra la fila de
            // `personal_access_tokens`, y con FK la bitácora se borraría con
            // ella o impediría la revocación. La bitácora tiene que sobrevivir
            // al token del que habla.
            $tabla->unsignedBigInteger('token_id')->nullable();

            $tabla->string('accion', 20);

            // Los alcances con los que salió, en texto. Guardados y no
            // recalculados: si mañana se agrega o se renombra un alcance, la
            // fila tiene que seguir diciendo qué se entregó ese día.
            $tabla->string('alcances', 255)->nullable();

            $tabla->foreignId('user_id')->nullable()->constrained('users');
            $tabla->string('ip', 45)->nullable();

            $tabla->timestamp('created_at')->nullable();

            $tabla->index(['sistema_externo_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sistema_externo_auditorias');
    }
};
