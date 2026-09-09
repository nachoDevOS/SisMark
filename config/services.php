<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | Microservicio de dispositivos (device-service en Python/FastAPI).
    | Es la única pieza que habla el protocolo ZKTeco con los equipos.
    | Laravel se comunica con él por HTTP usando un token compartido.
    */
    'device_service' => [
        'url' => env('DEVICE_SERVICE_URL', 'http://127.0.0.1:9001'),
        'token' => env('DEVICE_SERVICE_TOKEN'),
    ],

    /*
    | API externa de Datos Personales del sistema «Mamoré» (solo lectura).
    |
    | `token` es un token de Sanctum que **emite Mamoré** desde su pantalla
    | `/admin/tokens-api`, con el alcance `personal:read`. Acá solo se pega:
    | SisMark no genera nada para esta dirección.
    |
    | `origen` es el dominio con el que Mamoré tiene registrado a SisMark. Viaja
    | en la cabecera `Origin` porque del otro lado la comparan y contestan 403 si
    | falta. Vacío, se cae en `APP_URL`.
    |
    | `url` incluye el prefijo completo:
    |   https://servidor/api/externo/personal
    */
    'mamore' => [
        'url' => env('MAMORE_URL'),
        'token' => env('MAMORE_TOKEN'),
        'origen' => env('MAMORE_ORIGIN'),
    ],

    /*
    | La API propia de asistencia ya no se configura acá.
    |
    | Antes vivía en `sismark_api.key`: una sola clave compartida en el `.env`,
    | igual para todos los consumidores. Ahora cada uno es una fila de
    | `sistemas_externos` con su propio token de Sanctum, emitido con
    | `php artisan sismark:token {slug}`. No hay nada que poner en el `.env`.
    */

];
