@extends('layouts.template-print-alt')

@section('page_title', 'Funcionarios sin turno')

@section('css')
    <style>
        table[border] { border-collapse: collapse; }
        table[border] th,
        table[border] td { border: 1px solid #808080; }

        @media print {
            thead { display: table-header-group; }
            tr { page-break-inside: avoid; }
            .firmas { page-break-inside: avoid; }
        }
    </style>
@endsection

@php
    // Las fechas del contrato llegan de Mamoré como «Y-m-d», o null si no están.
    $fecha = fn (?string $valor, string $sinDato = '—'): string => $valor
        ? \Illuminate\Support\Carbon::parse($valor)->format('d/m/Y')
        : $sinDato;

    // En UTF-8: el nombre de la dirección llega del combo como «RRHH — Recursos
    // Humanos», y esa raya larga no existe en el ISO-8859-1 por defecto.
    $qrTexto = "FUNCIONARIOS SIN TURNO - GAD BENI\n"
        ."Direccion: {$nombreDireccion}\n"
        ."Unidad: {$nombreUnidad}\n"
        ."Tipo de contrato: {$nombreTipo}\n"
        .'Funcionarios: '.$total."\n"
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
                    FUNCIONARIOS SIN TURNO NI HORARIO ASIGNADO
                    <br>
                    TRINIDAD
                </h4>
                <small>Con contrato firmado, al {{ now()->format('d/m/Y') }}</small>
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
        <b>Dirección:</b> {{ $nombreDireccion }}
        <br><b>Unidad:</b> {{ $nombreUnidad }}
        <br><b>Tipo de contrato:</b> {{ $nombreTipo }}
        <br><b>Funcionarios:</b> {{ $total }}
        {{-- La hoja tiene tope: si quedó gente afuera tiene que decirlo ella misma. --}}
        @if (count($filas) < $total)
            (se imprimen los primeros {{ count($filas) }}; acotá por dirección para ver el resto)
        @endif
    </p>

    <table style="width: 100%; font-size: 11px" border="1" cellspacing="0" cellpadding="3">
        <thead>
            <tr>
                <th style="text-align: center">N°</th>
                <th style="text-align: center">Funcionario</th>
                <th style="text-align: center">CI</th>
                <th style="text-align: center">Tipo de contrato</th>
                <th style="text-align: center">Cargo</th>
                <th style="text-align: center">Dirección / Unidad</th>
                <th style="text-align: center">Inicio contrato</th>
                <th style="text-align: center">Fin contrato</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($filas as $persona)
                <tr>
                    <td style="text-align: center">{{ $loop->iteration }}</td>
                    <td>{{ $persona['nombreFormal'] ?: $persona['nombre'] }}</td>
                    <td style="text-align: center">{{ $persona['ci'] }}</td>
                    <td style="text-align: center">{{ $persona['tipoContrato'] ?: '—' }}</td>
                    <td>{{ $persona['cargo'] ?: '—' }}</td>
                    <td>
                        {{ $persona['direccionNombre'] ?: '—' }}@if (! empty($persona['unidadNombre']))<br><small>{{ $persona['unidadNombre'] }}</small>@endif
                    </td>
                    <td style="text-align: center">{{ $fecha($persona['contratoDesde']) }}</td>
                    <td style="text-align: center">{{ $fecha($persona['contratoHasta'], 'Sin fecha de fin') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" style="text-align: center">Todos los funcionarios con contrato de este filtro tienen turno.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

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
