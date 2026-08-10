@php
    $pillPorSituacion = [
        'vigente' => 'pill--ok',
        'vencida' => 'pill--no',
        'futura' => 'pill--info',
    ];
    $etiquetaSituacion = [
        'vigente' => 'Vigente',
        'vencida' => 'Vencida',
        'futura' => 'Aún no vigente',
    ];
@endphp
<div class="card">
    <table>
        <thead>
            <tr>
                <th>Funcionario</th>
                <th>Turno</th>
                <th>Día</th>
                <th>Entrada</th>
                <th>Salida</th>
                <th>Desde</th>
                <th>Hasta</th>
                <th>Situación</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($asignaciones as $asignacion)
                <tr>
                    <td>
                        @php($ficha = $fichas[trim((string) $asignacion->ci)] ?? null)
                        <div class="persona-celda">
                            {{-- La foto solo la tiene Mamoré; con el respaldo local
                                 (o sin ficha) queda el ícono genérico. --}}
                            <x-persona-avatar :thumb="$ficha['imageThumb'] ?? null"
                                              :full="$ficha['image'] ?? null"
                                              :nombre="$ficha['nombre'] ?? ''" />
                            <div>
                                @if ($ficha)
                                    {{ $ficha['nombre'] }}
                                @else
                                    <span style="color: var(--muted); font-style: italic;">Sin persona</span>
                                @endif
                                <div class="ayuda">
                                    CI {{ trim((string) $asignacion->ci) }}
                                    @if (!empty($ficha['cargo']))
                                        · {{ $ficha['cargo'] }}
                                    @endif
                                </div>
                            </div>
                        </div>
                    </td>
                    {{-- El turno viene por la FK turno_id; queda en null cuando la
                         copia del SIA no pudo cruzar el código histórico. --}}
                    <td>
                        @if ($asignacion->turno)
                            {{ trim((string) $asignacion->turno->nombreTurno) }}
                        @else
                            <span class="pill pill--advertencia">Sin turno vinculado</span>
                        @endif
                    </td>
                    <td>{{ $asignacion->turno?->nombre_dia ?? '—' }}</td>
                    <td>{{ $asignacion->turno?->hEntrada?->format('H:i') ?? '—' }}</td>
                    <td>{{ $asignacion->turno?->hSalida?->format('H:i') ?? '—' }}</td>
                    <td>{{ $asignacion->desde?->format('d/m/Y') ?? '—' }}</td>
                    <td>{{ $asignacion->hasta?->format('d/m/Y') ?? '—' }}</td>
                    <td>
                        <span class="pill {{ $pillPorSituacion[$asignacion->situacion] ?? 'pill--info' }}">
                            {{ $etiquetaSituacion[$asignacion->situacion] ?? $asignacion->situacion }}
                        </span>
                    </td>
                    <td>
                        <div class="acciones">
                            {{-- Concluir cuando el funcionario dejó el turno; eliminar,
                                 solo si la asignación se cargó mal. --}}
                            @if ($asignacion->situacion !== 'vencida')
                                @can('update', $asignacion)
                                    <x-boton-concluir :accion="route('turnos-asignados.concluir', $asignacion)"
                                                      :mensaje="'Turno «'.trim((string) $asignacion->turno?->nombreTurno).'» de CI '.trim((string) $asignacion->ci).'.'" />
                                @endcan
                            @endif
                            @can('delete', $asignacion)
                                <x-boton-eliminar :accion="route('turnos-asignados.destroy', $asignacion)"
                                                  :mensaje="'Se elimina la asignación del turno «'.trim((string) $asignacion->turno?->nombreTurno).'». Si el funcionario dejó ese turno, concluilo en vez de borrarlo.'" />
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" class="vacio">
                        {{ $buscar !== '' ? 'Sin turnos asignados para la búsqueda.' : 'Aún no hay turnos asignados.' }}
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="paginacion">{{ $asignaciones->links() }}</div>
