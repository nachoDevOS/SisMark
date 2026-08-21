@php
    $pillPorSituacion = ['vigente' => 'pill--ok', 'vencida' => 'pill--no', 'futura' => 'pill--info'];
    $etiquetaSituacion = ['vigente' => 'Vigente', 'vencida' => 'Vencida', 'futura' => 'Aún no vigente'];
    // Sin acciones cuando la tabla se muestra solo de referencia (modal de licencia).
    $conAcciones = $conAcciones ?? true;
    $columnas = $conAcciones ? 5 : 4;
@endphp
<div class="card">
    <table>
        <thead>
            <tr>
                <th>Turno</th>
                <th>Día</th>
                <th>Entrada</th>
                <th>Salida</th>
                @if ($conAcciones)
                    <th></th>
                @endif
            </tr>
        </thead>
        <tbody>
            @forelse ($periodos as $periodo)
                @php
                    $delPeriodo = $asignaciones[$periodo->clave_periodo] ?? collect();
                    $situacion = $periodo->situacion;
                    $vencido = $situacion === 'vencida';
                @endphp
                {{-- Cabecera del bloque: las fechas van una sola vez acá y no
                     repetidas en cada uno de los días que las comparten. --}}
                <tr class="fila--periodo">
                    <th colspan="{{ $columnas }}" scope="colgroup">
                        <div class="periodo">
                            <span class="periodo__fechas">
                                {{ $periodo->desde?->format('d/m/Y') ?? '—' }}
                                <span class="periodo__flecha" aria-hidden="true">→</span>
                                {{ $periodo->hasta?->format('d/m/Y') ?? '—' }}
                            </span>
                            <span class="pill {{ $pillPorSituacion[$situacion] ?? 'pill--info' }}">
                                {{ $etiquetaSituacion[$situacion] ?? $situacion }}
                            </span>
                            <span class="periodo__conteo">
                                {{ $delPeriodo->count() }} {{ $delPeriodo->count() === 1 ? 'día' : 'días' }}
                            </span>
                        </div>
                    </th>
                </tr>
                @foreach ($delPeriodo as $asignacion)
                    <tr @class(['fila--inactiva' => $vencido])>
                        <td><strong>{{ trim((string) $asignacion->turno?->nombreTurno) ?: '—' }}</strong></td>
                        <td>{{ $asignacion->turno?->nombre_dia ?? '—' }}</td>
                        <td>{{ $asignacion->turno?->hEntrada?->format('H:i') ?? '—' }}</td>
                        <td>{{ $asignacion->turno?->hSalida?->format('H:i') ?? '—' }}</td>
                        @if ($conAcciones)
                            <td>
                                <div class="acciones">
                                    {{-- Concluir es lo que corresponde cuando el funcionario dejó
                                         ese turno; eliminar, solo si la asignación se cargó mal. --}}
                                    @if ($asignacion->situacion !== 'vencida')
                                        @can('update', $asignacion)
                                            <x-boton-concluir :accion="route('turnos-asignados.concluir', $asignacion)"
                                                              :mensaje="'Turno «'.trim((string) $asignacion->turno?->nombreTurno).'» de CI '.trim((string) $asignacion->ci).'.'"
                                                              origen="{{ $origenFicha ?? '' }}" />
                                        @endcan
                                    @endif
                                    @can('delete', $asignacion)
                                        <x-boton-eliminar :accion="route('turnos-asignados.destroy', $asignacion)"
                                                          :mensaje="'Se elimina la asignación del turno «'.trim((string) $asignacion->turno?->nombreTurno).'». Si el funcionario dejó ese turno, concluilo en vez de borrarlo.'"
                                                          ancla="turnos" />
                                    @endcan
                                </div>
                            </td>
                        @endif
                    </tr>
                @endforeach
            @empty
                <tr><td colspan="{{ $columnas }}" class="vacio">El funcionario no tiene turnos asignados en este filtro.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="paginacion">{{ $periodos->links() }}</div>
