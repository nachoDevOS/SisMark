@extends('layouts.template-print-alt')

@section('page_title', 'Reporte de marcaciones por dirección')

@section('css')
    <style>
        /* Bordes colapsados: mismas líneas y en el mismo lugar, pero sin el
           borde inferior "fantasma" que el modelo de bordes separados dibuja
           al cortar la tabla al final de la página. */
        table[border] { border-collapse: collapse; }
        table[border] th,
        table[border] td { border: 1px solid #808080; }

        /* No parte una fila por la mitad y repite el encabezado en cada hoja. */
        @media print {
            thead { display: table-header-group; }
            tr { page-break-inside: avoid; }
            .leyenda, .firmas, .resumen { page-break-inside: avoid; }
        }
    </style>
@endsection

@php
    use App\Services\ProcesadorAsistencia as P;
    use App\Services\ReporteDireccion as R;
    use Illuminate\Support\Carbon;

    $sinMarca = [P::SIN_ENTRADA, P::SIN_SALIDA, P::TURNO_INVALIDO];

    $conJornada = fn (array $porEstado, int $dias): int => $dias
        - R::contar($porEstado, [P::NO_LABORABLE, P::SIN_CONTRATO]);

    $desdeFmt = $desde ? Carbon::parse($desde)->format('j/n/Y') : '—';
    $hastaFmt = $hasta ? Carbon::parse($hasta)->format('j/n/Y') : '—';

    // QR con el resumen del reporte (para verificación/archivo). Se quita el
    // prólogo XML del SVG para poder incrustarlo dentro del HTML.
    //
    // Va en UTF-8 y no en el ISO-8859-1 que la librería usa por defecto: el
    // nombre de la dirección llega del combo como «RRHH — Recursos Humanos», y
    // esa raya larga no existe en ISO-8859-1, así que el imprimible se caía con
    // un 500 en vez de salir.
    $qrTexto = "REPORTE DE MARCACIONES POR DIRECCION - GAD BENI\n"
        ."Direccion: {$nombreDireccion}\n"
        ."Unidad: {$nombreUnidad}\n"
        .'Funcionarios: '.$totales['funcionarios']."\n"
        ."Rango: {$desdeFmt} a {$hastaFmt}\n"
        .'Computado: '.P::duracion($totales['computado']).' de '.P::duracion($totales['esperado'])."\n"
        .'Impreso: '.now()->format('d/m/Y H:i:s');
    $qrSvg = preg_replace('/^<\?xml.*?\?>\s*/s', '', \SimpleSoftwareIO\QrCode\Facades\QrCode::format('svg')->encoding('UTF-8')->size(100)->margin(0)->generate($qrTexto));
@endphp

@section('content')
    <table width="100%">
        <tr>
            <td style="width: 20%"><img src="{{ asset('image/icon.png') }}" alt="GADBENI" width="140px"></td>
            <td style="text-align: center; width: 60%">
                <h3 style="margin-bottom: 0px; margin-top: 5px">
                    GOBIERNO AUTONOMO DEPARTAMENTAL DEL BENI
                </h3>
                <h4 style="margin-bottom: 0px; margin-top: 5px">
                    REPORTE DE MARCACIONES
                    <br>
                    TRINIDAD
                </h4>
                <small>Resumen por dirección</small>
            </td>
            <td style="text-align: right; width: 20%">
                <div>{!! $qrSvg !!}</div>
                <small style="font-size: 11px; font-weight: 100">
                    Impreso por: {{ auth()->user()?->name }}
                    <br>
                    {{ now()->format('d/m/Y H:i:s') }}
                </small>
            </td>
        </tr>
    </table>

    <p style="font-size: 13px; margin: 10px 0 5px;">
        <b>Dirección:</b> {{ $nombreDireccion }}, desde el {{ $desdeFmt }} hasta el {{ $hastaFmt }}
        {{-- El alcance va en la hoja: firmada y archivada, tiene que decir sola
             si cubre la dirección entera o una unidad. --}}
        <br><b>Unidad:</b> {{ $nombreUnidad }}
        <br><b>Funcionarios con contrato en el rango:</b> {{ $totales['funcionarios'] }}
    </p>

    <table style="width: 100%; font-size: 11px" border="1" cellspacing="0" cellpadding="3">
        <thead>
            <tr>
                <th style="text-align: center">N°</th>
                <th style="text-align: center">Funcionario</th>
                <th style="text-align: center">CI</th>
                <th style="text-align: center">Contrato(s) en el rango</th>
                <th style="text-align: center">Días</th>
                <th style="text-align: center">Cumple</th>
                <th style="text-align: center">Atrasos</th>
                <th style="text-align: center">Faltas</th>
                <th style="text-align: center">Abandonos</th>
                <th style="text-align: center">Sin marca</th>
                <th style="text-align: center">Licencia</th>
                <th style="text-align: center">Computado</th>
                <th style="text-align: center">Saldo</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($filas as $fila)
                @php
                    $persona = $fila['persona'];
                    $porEstado = $fila['totales']['porEstado'];
                    $saldo = $fila['totales']['saldo'];
                    $atrasos = R::contar($porEstado, [P::ATRASO]);
                @endphp
                <tr>
                    <td style="text-align: center">{{ $loop->iteration }}</td>
                    <td>{{ $persona['nombreFormal'] ?: $persona['nombre'] }}</td>
                    <td style="text-align: center">{{ $persona['ci'] }}</td>
                    <td style="font-size: 10px">
                        {{-- Los tramos y no «el cargo de hoy»: quien renovó o se pasó de
                             dirección tiene más de uno dentro del mismo rango. --}}
                        @foreach ($fila['tramos'] as $tramo)
                            {{ $tramo['desde']->format('j/n/y') }}–{{ $tramo['hasta']?->format('j/n/y') ?? 'vig.' }}@if ($tramo['direccion']) ({{ $tramo['direccion'] }})@endif
                            @if (! $loop->last)<br>@endif
                        @endforeach
                    </td>
                    <td style="text-align: center">{{ $conJornada($porEstado, $fila['totales']['dias']) }}</td>
                    <td style="text-align: center">{{ R::contar($porEstado, [P::CUMPLE]) }}</td>
                    <td style="text-align: center">
                        {{ $atrasos ?: '' }}@if ($fila['totales']['atraso'] > 0) <br><small>{{ P::desvio($fila['totales']['atraso']) }}</small>@endif
                    </td>
                    <td style="text-align: center"><b>{{ R::contar($porEstado, [P::FALTA]) ?: '' }}</b></td>
                    <td style="text-align: center"><b>{{ R::contar($porEstado, [P::ABANDONO]) ?: '' }}</b></td>
                    <td style="text-align: center">{{ R::contar($porEstado, $sinMarca) ?: '' }}</td>
                    <td style="text-align: center">{{ R::contar($porEstado, [P::LICENCIA]) ?: '' }}</td>
                    <td style="text-align: center">{{ P::duracion($fila['totales']['computado']) }}</td>
                    <td style="text-align: center">{{ $saldo > 0 ? '+' : '' }}{{ P::duracion($saldo) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="13" style="text-align: center">
                        Nadie de esta dirección tuvo contrato dentro del rango.
                    </td>
                </tr>
            @endforelse
            <tr>
                <th colspan="4" style="text-align: right">Totales de la dirección:</th>
                <th style="text-align: center">{{ $conJornada($totales['porEstado'], $totales['dias']) }}</th>
                <th style="text-align: center">{{ R::contar($totales['porEstado'], [P::CUMPLE]) }}</th>
                <th style="text-align: center">
                    {{ R::contar($totales['porEstado'], [P::ATRASO]) }}<br><small>{{ P::desvio($totales['atraso']) }}</small>
                </th>
                <th style="text-align: center">{{ R::contar($totales['porEstado'], [P::FALTA]) }}</th>
                <th style="text-align: center">{{ R::contar($totales['porEstado'], [P::ABANDONO]) }}</th>
                <th style="text-align: center">{{ R::contar($totales['porEstado'], $sinMarca) }}</th>
                <th style="text-align: center">{{ R::contar($totales['porEstado'], [P::LICENCIA]) }}</th>
                <th style="text-align: center">{{ P::duracion($totales['computado']) }}</th>
                <th style="text-align: center">{{ $totales['saldo'] > 0 ? '+' : '' }}{{ P::duracion($totales['saldo']) }}</th>
            </tr>
        </tbody>
    </table>

    <div class="resumen" style="font-size: 12px; margin-top: 10px;">
        <b>Horas computadas:</b> {{ P::duracion($totales['computado']) }} de {{ P::duracion($totales['esperado']) }}
        &nbsp;|&nbsp; <b>Saldo:</b> {{ $totales['saldo'] > 0 ? '+' : '' }}{{ P::duracion($totales['saldo']) }}
        &nbsp;|&nbsp; <b>Salida anticipada:</b> {{ P::desvio($totales['anticipo']) }}
        <br>
        <b>Días por estado:</b>
        @foreach ($totales['porEstado'] as $estado => $cantidad)
            {{ P::ETIQUETAS[$estado] ?? $estado }}: {{ $cantidad }}@if (! $loop->last) &nbsp;|&nbsp; @endif
        @endforeach
    </div>

    <div class="leyenda" style="font-size: 12px; margin-top: 10px;">
        <b>Referencias:</b>
        <br>&nbsp;&nbsp;&nbsp;&nbsp;<b>Quién entra:</b> el que tuvo contrato dentro del rango, aunque hoy ya no esté en la dirección. Más de un contrato es una renovación o un pase, y el hueco entre dos no se controla.
        <br>&nbsp;&nbsp;&nbsp;&nbsp;<b>Días</b> = los que tenían jornada: no cuentan los no laborables ni los que ningún contrato cubría.
        <br>&nbsp;&nbsp;&nbsp;&nbsp;<b>Sin marca</b> = faltó la entrada, faltó la salida, o el turno está mal configurado.
        <br>&nbsp;&nbsp;&nbsp;&nbsp;<b>Abandono</b> = se retiró antes de la mínima hora de salida, o no marcó un tramo que la licencia no cubría.
        <br>&nbsp;&nbsp;&nbsp;&nbsp;<b>Computado</b> = horas acotadas al turno; <b>Saldo</b> = lo computado contra lo esperado.
    </div>

    <br><br><br>
    <table width="100%" class="firmas">
        <tr>
            <td style="text-align: center">
                ______________________
                <br>
                <b>Firma Responsable</b>
            </td>
            <td style="text-align: center">
                ______________________
                <br>
                <b>Firma RR. HH.</b>
            </td>
        </tr>
    </table>
@endsection
