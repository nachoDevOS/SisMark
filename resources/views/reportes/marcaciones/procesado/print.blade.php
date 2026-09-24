@extends('layouts.template-print-alt')

@section('page_title', 'Reporte de marcaciones procesadas')

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
        }
    </style>
@endsection

@php
    use App\Models\Turno;
    use App\Services\ProcesadorAsistencia as P;
    use Illuminate\Support\Carbon;

    // $persona es la ficha resuelta (Mamoré, con la base local como respaldo).
    // El reporte imprime «Apellidos Nombres», como el sistema de escritorio viejo.
    $nombreEmpleado = $persona['nombreFormal'] ?: $persona['nombre'];
    $pin = $persona['pinReloj'] ?: '—';
    $cargo = $persona['cargo'] ?? null;
    $direccionAdmin = $persona['direccion'] ?? null;
    $desdeFmt = $desde ? Carbon::parse($desde)->format('j/n/Y') : '—';
    $hastaFmt = $hasta ? Carbon::parse($hasta)->format('j/n/Y') : '—';

    // QR con el resumen del reporte (para verificación/archivo). Se quita el
    // prólogo XML del SVG para poder incrustarlo dentro del HTML.
    $qrTexto = "REPORTE DE MARCACIONES PROCESADAS - GAD BENI\n"
        ."Funcionario: {$nombreEmpleado}\n"
        .'CI: '.$persona['ci']." | PIN: {$pin}\n"
        .($cargo ? "Cargo: {$cargo}\n" : '')
        ."Rango: {$desdeFmt} a {$hastaFmt}\n"
        .'Computado: '.P::duracion($totales['computado']).' de '.P::duracion($totales['esperado'])."\n"
        .'Impreso: '.now()->format('d/m/Y H:i:s');
    $qrSvg = preg_replace('/^<\?xml.*?\?>\s*/s', '', \SimpleSoftwareIO\QrCode\Facades\QrCode::format('svg')->size(100)->margin(0)->generate($qrTexto));

    // Solo los días con turno asignado, igual que la tabla en pantalla: los
    // «no laborable» no se controlan y llenaban la hoja de filas vacías.
    $diasConTurno = $dias->reject(fn (array $dia): bool => $dia['estado'] === P::NO_LABORABLE);
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
                <small>Marcaciones procesadas</small>
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
        <b>Empleado:</b> {{ $nombreEmpleado }}, <b>PIN Reloj:</b> {{ $pin }}, desde el {{ $desdeFmt }} hasta el {{ $hastaFmt }}
        @if ($cargo)
            <br><b>Cargo:</b> {{ $cargo }}@if ($direccionAdmin), <b>Dirección:</b> {{ $direccionAdmin }}@endif
        @endif
    </p>

    <table style="width: 100%; font-size: 11px" border="1" cellspacing="0" cellpadding="3">
        <thead>
            <tr>
                <th style="text-align: center">Fecha</th>
                <th style="text-align: center">Día</th>
                <th style="text-align: center">Turno</th>
                <th style="text-align: center">Entró</th>
                <th style="text-align: center">Salió</th>
                <th style="text-align: center">Atraso</th>
                <th style="text-align: center">Abandono</th>
                <th style="text-align: center">Falta</th>
                <th style="text-align: center">Entrada lic.</th>
                <th style="text-align: center">Salida lic.</th>
                <th style="text-align: center">T.C.</th>
                <th style="text-align: center">C.G.H.</th>
                <th style="text-align: center">Motivo licencia</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($diasConTurno as $dia)
                @php
                    $nombreDia = Turno::DIAS[$dia['fecha']->dayOfWeek + 1] ?? '—';
                    $filas = max(1, count($dia['bloques']));
                @endphp

                @if ($dia['bloques'] === [])
                    <tr>
                        <td style="text-align: center">{{ $dia['fecha']->format('j/n/Y') }}</td>
                        <td style="text-align: center">{{ $nombreDia }}</td>
                        <td colspan="10">{{ P::ETIQUETAS[$dia['estado']] ?? $dia['estado'] }}</td>
                        <td>{{ $dia['motivo'] ?: '' }}</td>
                    </tr>
                @else
                    @foreach ($dia['bloques'] as $indice => $bloque)
                        @php
                            $licencia = $bloque['licencia'];
                        @endphp
                        <tr>
                            @if ($indice === 0)
                                <td style="text-align: center" rowspan="{{ $filas }}">{{ $dia['fecha']->format('j/n/Y') }}</td>
                                <td style="text-align: center" rowspan="{{ $filas }}">{{ $nombreDia }}</td>
                            @endif
                            <td>{{ trim((string) $bloque['turno']->nombreTurno) }}</td>
                            <td style="text-align: center">{{ $bloque['entrada'] === null ? '' : P::hora($bloque['entrada']) }}</td>
                            <td style="text-align: center">{{ $bloque['salida'] === null ? '' : P::hora($bloque['salida']) }}</td>
                            <td style="text-align: center">{{ $bloque['atraso'] > 0 ? P::desvio($bloque['atraso']) : '' }}</td>
                            <td style="text-align: center"><b>{{ $bloque['estado'] === P::ABANDONO ? 'ABANDONO' : '' }}</b></td>
                            <td style="text-align: center"><b>{{ P::FALTAS[$bloque['estado']] ?? '' }}</b></td>
                            <td style="text-align: center">{{ $licencia?->lEntra?->format('H:i') ?? '' }}</td>
                            <td style="text-align: center">{{ $licencia?->lSale?->format('H:i') ?? '' }}</td>
                            <td style="text-align: center">{{ $licencia === null ? '' : ($licencia->tCompleto ? 'Sí' : 'No') }}</td>
                            <td style="text-align: center">{{ $licencia === null ? '' : ($licencia->goceHaberes ? 'Sí' : 'No') }}</td>
                            <td>{{ $licencia?->motivo ?? '' }}</td>
                        </tr>
                    @endforeach
                @endif
            @empty
                <tr>
                    <td colspan="13" style="text-align: center">No hay días con turno asignado en el rango.</td>
                </tr>
            @endforelse
            <tr>
                <th colspan="5" style="text-align: right">Totales del rango:</th>
                <th style="text-align: center">{{ P::desvio($totales['atraso']) }}</th>
                <th colspan="7"></th>
            </tr>
        </tbody>
    </table>
@endsection
