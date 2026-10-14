<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `cambiar_password` marca a quien entra con una contraseña puesta por
     * otro; `activo` dice si el usuario puede entrar. Los usuarios que ya
     * existen quedan sin cambio pendiente y habilitados.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('cambiar_password')->default(false)->after('password');
            $table->boolean('activo')->default(true)->after('cambiar_password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['cambiar_password', 'activo']);
        });
    }
};
