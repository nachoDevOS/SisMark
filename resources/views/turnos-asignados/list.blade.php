@php
    $pillPorSituacion = ['vigente' => 'pill--ok', 'vencida' => 'pill--no', 'futura' => 'pill--info'];
    $etiquetaSituacion = ['vigente' => 'Vigente', 'vencida' => 'Vencida', 'futura' => 'Aún no vigente'];
@endphp
<div class="card">
    <table>
        <thead>
            <tr>
                <th style="width: 2rem;"></th>
                <th>Funcionario</th>
                <th>Turno</th>
                <th>Desde</th>
                <th>Hasta</th>
                <th>Situación</th>
                <th></th>
            </tr>
        </thead>
        {{-- Un tbody por asignación: al hacer clic se despliegan los horarios del turno. --}}
        @forelse ($asignaciones as $asignacion)
            @php($ficha = $fichas[trim((string) $asignacion->ci)] ?? null)
            <tbody x-data="{ abierto: false }">
                <tr x-on:click="abierto = ! abierto" style="cursor: pointer;" :aria-expanded="abierto">
                    <td>
                        <span style="display: inline-flex; width: 1rem; transition: transform .15s;"
                              :style="abierto ? 'transform: rotate(90deg)' : ''"><x-heroicon-o-chevron-right /></span>
                    </td>
                    <td>
                        <div class="persona-celda">
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
                    <td>
                        <strong>{{ $asignacion->turno?->nombre ?? '—' }}</strong>
                        <div class="ayuda">
                            {{ $asignacion->turno?->dias_cubiertos }}
                            · {{ number_format($asignacion->turno?->horas_semanales ?? 0, 2) }} h semanales
                        </div>
                    </td>
                    <td>{{ $asignacion->desde?->format('d/m/Y') ?? '—' }}</td>
                    <td>{{ $asignacion->hasta?->format('d/m/Y') ?? '—' }}</td>
                    <td>
                        <span class="pill {{ $pillPorSituacion[$asignacion->situacion] ?? 'pill--info' }}">
                            {{ $etiquetaSituacion[$asignacion->situacion] ?? $asignacion->situacion }}
                        </span>
                    </td>
                    <td>
                        <div class="acciones" x-on:click.stop>
                            @if ($asignacion->situacion !== 'vencida')
                                @can('update', $asignacion)
                                    <x-boton-concluir :accion="route('turnos-asignados.concluir', $asignacion)"
                                                      :mensaje="'Turno «'.$asignacion->turno?->nombre.'» de CI '.trim((string) $asignacion->ci).'.'" />
                                @endcan
                            @endif
                            @can('delete', $asignacion)
                                <x-boton-eliminar :accion="route('turnos-asignados.destroy', $asignacion)"
                                                  :mensaje="'Se elimina la asignación del turno «'.$asignacion->turno?->nombre.'». Si el funcionario dejó ese turno, concluilo en vez de borrarlo.'" />
                            @endcan
                        </div>
                    </td>
                </tr>
                <tr x-show="abierto" x-cloak>
                    <td></td>
                    <td colspan="6" style="padding-top: 0;">
                        @include('turnos._horarios', ['horarios' => $asignacion->turno?->horarios ?? collect()])
                    </td>
                </tr>
            </tbody>
        @empty
            <tbody>
                <tr>
                    <td colspan="7" class="vacio">
                        {{ $buscar !== '' ? 'Sin turnos asignados para la búsqueda.' : 'Aún no hay turnos asignados.' }}
                    </td>
                </tr>
            </tbody>
        @endforelse
    </table>
</div>

<div class="paginacion">{{ $asignaciones->links() }}</div>
