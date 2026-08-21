<?php

use App\Services\CalificadorRip;
use App\Services\EscalaRip;

return [

    /*
    |--------------------------------------------------------------------------
    | Reglamento Interno de Personal (RIP) — Gestión 2025
    |--------------------------------------------------------------------------
    |
    | Parámetros del régimen disciplinario por asistencia, aprobado por
    | Resolución de Gobernación N.º 162/2025. Viven acá y no en el código
    | porque dos artículos del propio reglamento admiten más de una lectura, y
    | la interpretación la firma Recursos Humanos, no el programador.
    |
    | Cambiar cualquiera de estos valores cambia la plata que se le descuenta a
    | una persona. Todo cierre mensual tiene que guardar con qué parámetros se
    | calculó.
    |
    */

    /*
    | De dónde se cuentan los minutos de atraso.
    |
    | - `nominal`     : desde `hEntrada`. El que llega 08:45 con entrada 08:30
    |                   acumula 15 minutos. Es lo que el sistema hizo siempre.
    | - `tolerancia`  : desde el fin de la tolerancia. El mismo caso acumula 5.
    |
    | El Art. 22.V dice que se computan «los minutos de atraso que se registren
    | posteriores a la tolerancia establecida», y esa frase admite las dos
    | lecturas. La diferencia es de una tolerancia completa por evento: seis
    | llegadas al mes pueden ser 2 días de sueldo o ninguno.
    */
    'atrasoDesde' => env('RIP_ATRASO_DESDE', EscalaRip::DESDE_NOMINAL),

    /*
    | Tolerancia fija en segundos, o `null` para respetar la `hTolerancia`
    | cargada en cada turno.
    |
    | El Art. 22.V concede 10 minutos; el Art. 45.I sanciona los minutos
    | «posteriores a los cinco (5) minutos de tolerancia». El reglamento se
    | contradice. Por defecto se respeta el turno, que es donde Recursos
    | Humanos ya cargó el criterio: 710 de los 757 turnos vigentes tienen
    | exactamente 10 minutos.
    */
    'toleranciaFija' => env('RIP_TOLERANCIA_SEGUNDOS') !== null
        ? (int) env('RIP_TOLERANCIA_SEGUNDOS')
        : null,

    /*
    | Segundos de atraso a partir de los cuales la llegada deja de ser atraso y
    | pasa a ser inasistencia (Art. 45.II: «registre su asistencia pasados
    | treinta (30) minutos de la hora fijada para el ingreso»).
    |
    | Es lo que impide que una sola llegada muy tarde dispare por sí sola la
    | escala mensual: sin este corte, entrar a las 10:00 suma 90 minutos de una
    | vez. Con él, ningún ingreso aporta más de 30 minutos al acumulado.
    */
    'corteInasistencia' => (int) env('RIP_CORTE_INASISTENCIA', 1800),

    /*
    | Escalas de descuento, en días de la remuneración mensual.
    |
    | Las etiquetas y los artículos viajan con cada tramo para que la sanción
    | pueda explicarse sola: quien la recibe tiene que poder verificarla contra
    | el reglamento publicado (Art. 52, Representación).
    */
    'escalas' => [

        /*
        | Art. 45.I — minutos de atraso acumulados en el mes.
        | Cada tramo es [minutos hasta (inclusive), días de descuento].
        */
        'atraso' => [
            [30, 0],
            [45, 0.5],
            [60, 1],
            [90, 2],
            [120, 3],
        ],

        /*
        | Pasado el último tramo (121 minutos o más), manda la reincidencia en
        | la gestión: Art. 45.I la primera vez, Art. 46.IV la segunda y
        | Art. 47.III la tercera, que ya es proceso interno con destitución.
        */
        'atrasoReincidencia' => [
            1 => ['dias' => 4, 'gravedad' => EscalaRip::LEVE, 'articulo' => '45.I'],
            2 => ['dias' => 6, 'gravedad' => EscalaRip::GRAVE, 'articulo' => '46.IV'],
            3 => ['dias' => null, 'gravedad' => EscalaRip::GRAVISIMA, 'articulo' => '47.III'],
        ],

        /*
        | Art. 45.III — omisiones no regularizadas en el mes.
        | Cada vez tiene su propia sanción; la cuarta es proceso interno.
        */
        'omision' => [
            1 => ['dias' => 0.5, 'gravedad' => EscalaRip::LEVE, 'articulo' => '45.III'],
            2 => ['dias' => 1, 'gravedad' => EscalaRip::LEVE, 'articulo' => '45.III'],
            3 => ['dias' => 2, 'gravedad' => EscalaRip::GRAVE, 'articulo' => '46.IV'],
            4 => ['dias' => null, 'gravedad' => EscalaRip::GRAVISIMA, 'articulo' => '47.III'],
        ],

        /*
        | Inasistencias y ausencias en el puesto.
        |
        | El descuento es proporcional y uniforme en los tres artículos: media
        | jornada cuesta 1 día, una jornada cuesta 2, dos días continuos cuestan
        | 4 y los discontinuos van «2 días por cada día». Todos son la misma
        | razón, así que se guarda una sola —`porJornada`— y lo que cambia con
        | la acumulación es la gravedad, no el monto.
        |
        | `continuosProceso` y `discontinuosProceso` son los umbrales que ya no
        | se descuentan: abren proceso interno (Art. 47.III para la ausencia en
        | el puesto, Art. 48 para la inasistencia, que es abandono de funciones).
        */
        'ausentismo' => [
            'porJornada' => 2,
            'continuosGrave' => 2,
            'discontinuosGrave' => 2,
            'continuosProceso' => 3,
            'discontinuosProceso' => 6,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Haber: de días de descuento a bolivianos
    |--------------------------------------------------------------------------
    |
    | Las escalas del reglamento se expresan en «días de la remuneración
    | mensual». El haber lo tiene Mamoré en el contrato firmado; SisMark no
    | guarda sueldos. Ver {@see App\Services\HaberFuncionario}.
    */
    'haber' => [

        /*
        | Qué se toma como «remuneración mensual»: solo el haber básico del
        | contrato (`false`, por defecto) o el básico más el bono (`true`).
        |
        | El reglamento no lo define y la diferencia es plata. Queda como
        | decisión de Recursos Humanos, y la pantalla dice cuál se aplicó.
        */
        'incluirBono' => env('RIP_INCLUIR_BONO', false),

        /*
        | Divisor único para todos los casos, o `null` para el criterio por
        | defecto: 30 en el mes completo —así se liquida el haber mensual en el
        | sector público, tenga el mes 28 o 31 días— y los días efectivamente
        | cubiertos cuando el contrato empezó o terminó en el medio.
        |
        | Ponerlo en 30 fuerza ese divisor también en los contratos parciales.
        */
        'divisorFijo' => env('RIP_DIVISOR_FIJO'),
    ],

    /*
    | Estados de {@see App\Services\ProcesadorAsistencia} que no se califican
    | porque el turno está mal cargado. Un turno con tolerancia de cuatro horas
    | o sin horas declaradas no puede fundar un descuento: el día se aparta y se
    | informa aparte. Ver {@see CalificadorRip::calificar()}.
    */
    'apartarTurnosInvalidos' => true,

];
