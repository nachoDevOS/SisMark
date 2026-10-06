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
                <th>Horario</th>
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
                    {{-- El horario viene por la FK horario_id; queda en null cuando la
                         copia del SIA no pudo cruzar el código histórico. --}}
                    <td>
                        @if ($asignacion->horario)
                            {{ trim((string) $asignacion->horario->nombreHorario) }}
                        @else
                            <span class="pill pill--advertencia">Sin horario vinculado</span>
                        @endif
                        @if ($asignacion->es_de_turno)
                            <div class="ayuda">Turno: {{ $asignacion->asignacionTurno?->turno?->nombre }}</div>
                        @endif
                    </td>
                    <td>{{ $asignacion->horario?->nombre_dia ?? '—' }}</td>
                    <td>{{ $asignacion->horario?->hEntrada?->format('H:i') ?? '—' }}</td>
                    <td>{{ $asignacion->horario?->hSalida?->format('H:i') ?? '—' }}</td>
                    <td>{{ $asignacion->desde?->format('d/m/Y') ?? '—' }}</td>
                    <td>{{ $asignacion->hasta?->format('d/m/Y') ?? '—' }}</td>
                    <td>
                        <span class="pill {{ $pillPorSituacion[$asignacion->situacion] ?? 'pill--info' }}">
                            {{ $etiquetaSituacion[$asignacion->situacion] ?? $asignacion->situacion }}
                        </span>
                    </td>
                    <td>
                        <div class="acciones">
                            {{-- Lo de un turno se concluye o se elimina desde «Turnos
                                 asignados», nunca día por día: quedaría desparejo con su
                                 turno. Va con @if y no solo con la policy porque el
                                 super_admin pasa por encima de todas las policies. --}}
                            @if ($asignacion->es_de_turno)
                                @can('viewAny', \App\Models\AsignacionTurno::class)
                                    <a href="{{ route('turnos-asignados.index', ['buscar' => trim((string) $asignacion->ci)]) }}"
                                       class="btn-icon btn-icon--gris" title="Ver en turnos asignados" aria-label="Ver en turnos asignados">
                                        <x-heroicon-o-rectangle-stack />
                                    </a>
                                @endcan
                            @else
                                {{-- Concluir cuando el funcionario dejó el horario; eliminar,
                                     solo si la asignación se cargó mal. --}}
                                @if ($asignacion->situacion !== 'vencida')
                                    @can('update', $asignacion)
                                        <x-boton-concluir :accion="route('horarios-asignados.concluir', $asignacion)"
                                                          :mensaje="'Horario «'.trim((string) $asignacion->horario?->nombreHorario).'» de CI '.trim((string) $asignacion->ci).'.'" />
                                    @endcan
                                @endif
                                @can('delete', $asignacion)
                                    <x-boton-eliminar :accion="route('horarios-asignados.destroy', $asignacion)"
                                                      :mensaje="'Se elimina la asignación del horario «'.trim((string) $asignacion->horario?->nombreHorario).'». Si el funcionario dejó ese horario, concluilo en vez de borrarlo.'" />
                                @endcan
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" class="vacio">
                        {{ $buscar !== '' ? 'Sin horarios asignados para la búsqueda.' : 'Aún no hay horarios asignados.' }}
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="paginacion">{{ $asignaciones->links() }}</div>
