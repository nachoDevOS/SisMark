@php
    // Lo que viaja al imprimible: solo los filtros elegidos.
    $parametros = array_filter([
        'direccion' => $direccion,
        'nombreDireccion' => $direccion === null ? null : $nombreDireccion,
        'unidad' => $unidad,
        'nombreUnidad' => $unidad === null ? null : $nombreUnidad,
        'tipo' => $tipo,
        'nombreTipo' => $tipo === null ? null : $nombreTipo,
    ], fn ($valor): bool => $valor !== null);
    // Las fechas del contrato llegan de Mamoré como «Y-m-d», o null si no están.
    $fecha = fn (?string $valor, string $sinDato = '—'): string => $valor
        ? \Illuminate\Support\Carbon::parse($valor)->format('d/m/Y')
        : $sinDato;
@endphp

{{-- Partial: se inyecta bajo los filtros del reporte vía AJAX (no lleva layout). --}}
<div class="card card--padded">
    <div class="cabecera" style="margin-bottom: 1rem;">
        <div>
            <strong>{{ $nombreDireccion }}</strong><br>
            <span style="color: var(--muted);">
                {{ $nombreUnidad }} · {{ $nombreTipo }} ·
                {{ number_format($funcionarios->total(), 0, ',', '.') }} {{ $funcionarios->total() === 1 ? 'funcionario' : 'funcionarios' }} sin turno
            </span>
        </div>
        <div class="acciones">
            <a class="btn" target="_blank" rel="noopener"
               href="{{ route('reportes.funcionarios.sin-turno.generar', $parametros + ['print' => 1]) }}"><x-heroicon-o-printer />Imprimir</a>
            @can('Export:Reporte')
                <a class="btn btn--gris"
                   href="{{ route('reportes.funcionarios.sin-turno.generar', $parametros + ['print' => 2]) }}"><x-heroicon-o-table-cells />Excel</a>
            @endcan
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>N°</th>
                <th>Funcionario</th>
                <th>Tipo de contrato</th>
                <th>Cargo</th>
                <th>Dirección / Unidad</th>
                <th>Contrato</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($funcionarios as $persona)
                <tr>
                    <td>{{ $funcionarios->firstItem() + $loop->index }}</td>
                    <td>
                        <div class="persona-celda">
                            <x-persona-avatar :thumb="$persona['imageThumb'] ?? null"
                                              :full="$persona['image'] ?? null"
                                              :nombre="$persona['nombre'] ?? ''" />
                            <div>
                                <div class="persona-nombre">{{ $persona['nombreFormal'] ?: $persona['nombre'] }}</div>
                                <div class="persona-meta">{{ $persona['ci'] }}</div>
                            </div>
                        </div>
                    </td>
                    <td>{{ $persona['tipoContrato'] ?: '—' }}</td>
                    <td>{{ $persona['cargo'] ?: '—' }}</td>
                    <td>
                        {{ $persona['direccionNombre'] ?: '—' }}
                        @if (! empty($persona['unidadNombre']))
                            <div class="persona-meta">{{ $persona['unidadNombre'] }}</div>
                        @endif
                    </td>
                    <td>
                        <span style="white-space: nowrap;">Inicio: {{ $fecha($persona['contratoDesde']) }}</span>
                        <div class="persona-meta" style="white-space: nowrap;">Fin: {{ $fecha($persona['contratoHasta'], 'Sin fecha de fin') }}</div>
                    </td>
                    <td class="acciones">
                        {{-- En otra pestaña: volver atrás obligaba a generar el reporte de nuevo. --}}
                        @if ($persona['ver'] && auth()->user()->can('View:Persona'))
                            <a href="{{ $persona['ver'] }}" target="_blank" rel="noopener" class="btn-icon btn-icon--gris" title="Ver" aria-label="Ver"><x-heroicon-o-eye /></a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="vacio">Todos los funcionarios con contrato de este filtro tienen turno.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="paginacion">{{ $funcionarios->links() }}</div>
