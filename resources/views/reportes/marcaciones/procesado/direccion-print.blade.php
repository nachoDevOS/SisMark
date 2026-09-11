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
            .firmas, .resumen { page-break-inside: avoid; }
        }
    </style>
@endsection

@php
    use App\Services\ProcesadorAsistencia as P;
    use App\Services\ReporteDireccion as R;
    use Illuminate\Support\Carbon;

    // Todo lo que la columna «Faltas» cuenta como falta: además del día sin
    // ninguna marca, el que tiene una sola punta y el de turno mal cargado.
    $comoFalta = [P::FALTA, P::SIN_ENTRADA, P::SIN_SALIDA, P::TURNO_INVALIDO];

    // El procesador cuenta el atraso en minutos completos y lo guarda en
    // segundos, así que la división es exacta.
    $minutosDeAtraso = fn (int $segundos): int => intdiv($segundos, 60);

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
        .'Atrasos: '.R::contar($totales['porEstado'], [P::ATRASO])
        .($cruzaMeses ? '' : ' ('.$minutosDeAtraso($totales['atraso']).' min)')
        .' | Faltas: '.R::contar($totales['porEstado'], $comoFalta)
        .' | Abandonos: '.R::contar($totales['porEstado'], [P::ABANDONO])."\n"
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
                @if ($cruzaMeses)
                    <th style="text-align: center">Mes</th>
                @endif
                <th style="text-align: center">Minutos<br>acumulados</th>
                <th style="text-align: center">Atrasos</th>
                <th style="text-align: center">Abandonos</th>
                <th style="text-align: center">Faltas</th>
                <th style="text-align: center">Licencia</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($filas as $fila)
                @php
                    $persona = $fila['persona'];
                    $porEstado = $fila['totales']['porEstado'];
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
                    @if ($cruzaMeses)
                        <td style="text-align: center"><b>Total</b></td>
                    @endif
                    {{-- Los minutos se acumulan por mes: cruzando meses el total de la
                         persona va con raya y los números viven en su mes. --}}
                    <td style="text-align: center">
                        {{ $cruzaMeses ? '—' : ($minutosDeAtraso($fila['totales']['atraso']) ?: '') }}
                    </td>
                    <td style="text-align: center">{{ $atrasos ?: '' }}</td>
                    <td style="text-align: center"><b>{{ R::contar($porEstado, [P::ABANDONO]) ?: '' }}</b></td>
                    <td style="text-align: center"><b>{{ R::contar($porEstado, $comoFalta) ?: '' }}</b></td>
                    <td style="text-align: center">{{ R::contar($porEstado, [P::LICENCIA]) ?: '' }}</td>
                </tr>
                @if ($cruzaMeses)
                    @foreach ($fila['meses'] as $mes)
                        @php
                            $delMes = $mes['totales']['porEstado'];
                            $atrasosMes = R::contar($delMes, [P::ATRASO]);
                        @endphp
                        <tr>
                            <td colspan="4"></td>
                            <td style="text-align: center">{{ $mes['etiqueta'] }}</td>
                            <td style="text-align: center">{{ $minutosDeAtraso($mes['totales']['atraso']) ?: '' }}</td>
                            <td style="text-align: center">{{ $atrasosMes ?: '' }}</td>
                            <td style="text-align: center">{{ R::contar($delMes, [P::ABANDONO]) ?: '' }}</td>
                            <td style="text-align: center">{{ R::contar($delMes, $comoFalta) ?: '' }}</td>
                            <td style="text-align: center">{{ R::contar($delMes, [P::LICENCIA]) ?: '' }}</td>
                        </tr>
                    @endforeach
                @endif
            @empty
                <tr>
                    <td colspan="{{ $cruzaMeses ? 10 : 9 }}" style="text-align: center">
                        Nadie de esta dirección tuvo contrato dentro del rango.
                    </td>
                </tr>
            @endforelse
            <tr>
                <th colspan="{{ $cruzaMeses ? 5 : 4 }}" style="text-align: right">Totales de la dirección:</th>
                <th style="text-align: center">{{ $cruzaMeses ? '—' : $minutosDeAtraso($totales['atraso']) }}</th>
                <th style="text-align: center">{{ R::contar($totales['porEstado'], [P::ATRASO]) }}</th>
                <th style="text-align: center">{{ R::contar($totales['porEstado'], [P::ABANDONO]) }}</th>
                <th style="text-align: center">{{ R::contar($totales['porEstado'], $comoFalta) }}</th>
                <th style="text-align: center">{{ R::contar($totales['porEstado'], [P::LICENCIA]) }}</th>
            </tr>
        </tbody>
    </table>

    <div class="resumen" style="font-size: 12px; margin-top: 10px;">
        <b>Atrasos:</b> {{ R::contar($totales['porEstado'], [P::ATRASO]) }}
        @unless ($cruzaMeses)({{ $minutosDeAtraso($totales['atraso']) }} min acumulados)@endunless
        &nbsp;|&nbsp; <b>Abandonos:</b> {{ R::contar($totales['porEstado'], [P::ABANDONO]) }}
        &nbsp;|&nbsp; <b>Faltas:</b> {{ R::contar($totales['porEstado'], $comoFalta) }}
        <br>
        @if ($cruzaMeses)
            <b>Los minutos se acumulan por mes calendario y no se suman entre meses</b>,
            así que el rango se abre por mes y el total va con raya.
            <br>
        @endif
        <b>Días por estado:</b>
        @foreach ($totales['porEstado'] as $estado => $cantidad)
            {{ P::ETIQUETAS[$estado] ?? $estado }}: {{ $cantidad }}@if (! $loop->last) &nbsp;|&nbsp; @endif
        @endforeach
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
