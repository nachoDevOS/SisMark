<?php

use App\Models\Turno;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué días de la semana trabaja la sincronización automática de cada equipo.
 *
 * Hasta acá los horarios eran solo horas, así que un equipo configurado a las
 * 19:00 también hablaba con el reloj sábado y domingo, cuando no hay nadie
 * marcando. Con los días, la ficha del equipo describe la jornada real:
 * «lunes a viernes, 08:30 y 19:00».
 *
 * Va en JSON y al lado de `sync_horarios` por lo mismo que aquellos: son pocos
 * valores por equipo, se leen y se guardan siempre enteros, y nunca se consulta
 * por un día suelto —la tarea recorre los equipos y compara en PHP—.
 *
 * Los números son los de {@see Turno::DIAS} (1 = Domingo … 7 =
 * Sábado, como `DATEPART(dw)` del SIA), para no tener dos convenciones de día
 * de la semana conviviendo en el mismo sistema.
 *
 * `null` significa **todos los días**, que es como se venían comportando los
 * equipos ya configurados: la columna nace vacía y nada cambia de conducta
 * hasta que alguien elija días en la ficha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipos', function (Blueprint $tabla): void {
            $tabla->json('sync_dias')->nullable()->after('sync_horarios');
        });
    }

    public function down(): void
    {
        Schema::table('equipos', function (Blueprint $tabla): void {
            $tabla->dropColumn('sync_dias');
        });
    }
};
