<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Sincronización automática de los equipos biométricos
|--------------------------------------------------------------------------
|
| Corre cada minuto y el comando decide qué equipos trabajan: los horarios se
| configuran equipo por equipo desde el sistema, así que el planificador no
| puede saberlos de antemano (cambian sin tocar el código).
|
| Requiere que el servidor dispare `php artisan schedule:run` cada minuto. En
| el Windows del sistema se configura como tarea del Programador de tareas;
| sin eso, la sincronización automática no ocurre por más que esté marcada en
| la ficha del equipo.
|
| `withoutOverlapping` evita que un reloj lento haga que se encimen dos
| corridas; `runInBackground` deja que el minuto siguiente arranque sin
| esperar a que termine la lectura de los equipos.
*/
Schedule::command('sismark:sincronizar-equipos')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
