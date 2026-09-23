@php
    // $persona es la ficha resuelta (Mamoré, con la base local como respaldo).
    $nombreEmpleado = $persona['nombreFormal'] ?: $persona['nombre'];
    $parametros = ['persona' => $persona['ci'], 'desde' => $desde, 'hasta' => $hasta, 'tipo' => $tipo];
@endphp

{{-- Partial: se inyecta bajo el filtro del reporte vía AJAX (no lleva layout). --}}
<div class="card card--padded">
    <div class="cabecera" style="margin-bottom: 1rem;">
        <div>
            <strong>{{ $nombreEmpleado ?: 'Funcionario' }}</strong> · CI {{ $persona['ci'] }} ·
            PIN reloj {{ $persona['pinReloj'] ?: '—' }}<br>
            @if (!empty($persona['cargo']))
                <span style="color: var(--muted);">
                    {{ $persona['cargo'] }}{{ empty($persona['direccion']) ? '' : ' · '.$persona['direccion'] }}
                </span><br>
            @endif
            <span style="color: var(--muted);">
                Rango: {{ $desde ?: '—' }} a {{ $hasta ?: '—' }} · Total: {{ $marcaciones->count() }} registro(s)
            </span>
        </div>
        <div class="acciones">
            <a class="btn" target="_blank" rel="noopener"
               href="{{ route('reportes.marcaciones.sin-procesar.generar', $parametros + ['print' => 1]) }}"><x-heroicon-o-printer />Imprimir</a>
            @can('Export:Reporte')
                <a class="btn btn--gris"
                   href="{{ route('reportes.marcaciones.sin-procesar.generar', $parametros + ['print' => 2]) }}"><x-heroicon-o-table-cells />Excel (CSV)</a>
            @endcan
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 3rem;">N.º</th>
                <th>Fecha</th>
                <th>Hora</th>
                <th>Tipo</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($marcaciones as $marcacion)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $marcacion->fecha?->format('d/m/Y') }}</td>
                    <td>{{ $marcacion->hora?->format('H:i:s') }}</td>
                    <td>{{ trim((string) $marcacion->tipo) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="vacio">Sin marcaciones en el rango seleccionado.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
