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
    | Se consulta por HTTP con el header X-API-KEY para listar/ver personas.
    | `url` incluye el prefijo completo, p.ej. https://servidor/api/personal
    */
    'mamore' => [
        'url' => env('MAMORE_API_URL'),
        'key' => env('MAMORE_API_KEY'),
    ],

    /*
    | API propia de asistencia (solo lectura), que exponemos a los sistemas
    | externos. Hoy la consume Mamoré, para que cada funcionario vea sus
    | propias marcaciones sin entrar a SisMark.
    |
    | Es la contraparte de 'mamore': el mismo mecanismo de clave compartida,
    | en la dirección opuesta. Vacía, la API rechaza todo con 503.
    */
    'sismark_api' => [
        'key' => env('SISMARK_API_KEY'),
    ],

];
