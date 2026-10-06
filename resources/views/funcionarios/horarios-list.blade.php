@php
    $pillPorSituacion = ['vigente' => 'pill--ok', 'vencida' => 'pill--no', 'futura' => 'pill--info'];
    $etiquetaSituacion = ['vigente' => 'Vigente', 'vencida' => 'Vencida', 'futura' => 'Aún no vigente'];
    // Sin acciones cuando la tabla se muestra solo de referencia (modal de licencia).
    $conAcciones = $conAcciones ?? true;
    $columnas = $conAcciones ? 3 : 2;

    /**
     * El nombre del horario del SIA suele ser «LUN: 08:00 - 16:00», que es día y
     * horario otra vez. Se muestra solo cuando aporta algo que la fila no dice.
     */
    $nombreQueAporta = function (?string $nombre, ?string $entrada, ?string $salida): string {
        $nombre = trim((string) $nombre);

        if ($nombre === '' || ($entrada !== null && $salida !== null
            && str_contains($nombre, $entrada) && str_contains($nombre, $salida))) {
            return '';
        }

        return $nombre;
    };
@endphp
<div class="card">
    <table class="tabla--compacta">
        <thead>
            <tr>
                <th>Día</th>
                <th>Horario</th>
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
                    // El período es de un turno cuando sus filas son el detalle de
                    // una misma asignación de turno: ahí se concluye y se elimina
                    // el turno entero, no cada día.
                    $asignacionTurno = $delPeriodo->pluck('asignacionTurno')->filter()->unique('id');
                    $asignacionTurno = $asignacionTurno->count() === 1 && $delPeriodo->every(fn ($fila) => $fila->es_de_turno)
                        ? $asignacionTurno->first()
                        : null;
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
                            <span class="pill pill--mini {{ $pillPorSituacion[$situacion] ?? 'pill--info' }}">
                                {{ $etiquetaSituacion[$situacion] ?? $situacion }}
                            </span>
                            @if ($asignacionTurno)
                                <span class="pill pill--mini pill--info" title="Turno asignado">
                                    {{ $asignacionTurno->turno?->nombre }}
                                </span>
                            @endif
                            <span class="periodo__conteo">
                                {{ $delPeriodo->count() }} {{ $delPeriodo->count() === 1 ? 'día' : 'días' }}
                            </span>
                            @if ($conAcciones && $asignacionTurno)
                                <span class="acciones" style="margin-left: auto;">
                                    @if ($situacion !== 'vencida')
                                        @can('update', $asignacionTurno)
                                            <x-boton-concluir :accion="route('turnos-asignados.concluir', $asignacionTurno)"
                                                              :mensaje="'Turno «'.$asignacionTurno->turno?->nombre.'» de CI '.trim((string) $asignacionTurno->ci).'.'"
                                                              origen="{{ $origenFicha ?? '' }}" etiqueta="Concluir turno" />
                                        @endcan
                                    @endif
                                    @can('delete', $asignacionTurno)
                                        <x-boton-eliminar :accion="route('turnos-asignados.destroy', $asignacionTurno)"
                                                          :mensaje="'Se elimina la asignación del turno «'.$asignacionTurno->turno?->nombre.'». Si el funcionario dejó ese turno, concluilo en vez de borrarlo.'"
                                                          ancla="horarios" />
                                    @endcan
                                </span>
                            @endif
                        </div>
                    </th>
                </tr>
                @foreach ($delPeriodo as $asignacion)
                    @php
                        $entrada = $asignacion->horario?->hEntrada?->format('H:i');
                        $salida = $asignacion->horario?->hSalida?->format('H:i');
                        $nombre = $nombreQueAporta($asignacion->horario?->nombreHorario, $entrada, $salida);
                    @endphp
                    <tr @class(['fila--inactiva' => $vencido])>
                        <td>
                            <strong>{{ $asignacion->horario?->nombre_dia ?? '—' }}</strong>
                            @if ($nombre !== '')
                                <div class="ayuda">{{ $nombre }}</div>
                            @endif
                        </td>
                        <td class="horario">
                            {{ $entrada ?? '—' }}<span class="horario__sep" aria-hidden="true">→</span>{{ $salida ?? '—' }}
                        </td>
                        @if ($conAcciones)
                            <td>
                                {{-- Lo heredado del sistema anterior se concluye y se
                                     elimina fila por fila; lo de un turno, desde la
                                     cabecera del período. --}}
                                <div class="acciones">
                                    @if (! $asignacion->es_de_turno)
                                    {{-- Concluir es lo que corresponde cuando el funcionario dejó
                                         ese horario; eliminar, solo si la asignación se cargó mal. --}}
                                    @if ($asignacion->situacion !== 'vencida')
                                        @can('update', $asignacion)
                                            <x-boton-concluir :accion="route('horarios-asignados.concluir', $asignacion)"
                                                              :mensaje="'Horario «'.trim((string) $asignacion->horario?->nombreHorario).'» de CI '.trim((string) $asignacion->ci).'.'"
                                                              origen="{{ $origenFicha ?? '' }}" />
                                        @endcan
                                    @endif
                                    @can('delete', $asignacion)
                                        <x-boton-eliminar :accion="route('horarios-asignados.destroy', $asignacion)"
                                                          :mensaje="'Se elimina la asignación del horario «'.trim((string) $asignacion->horario?->nombreHorario).'». Si el funcionario dejó ese horario, concluilo en vez de borrarlo.'"
                                                          ancla="horarios" />
                                    @endcan
                                    @endif
                                </div>
                            </td>
                        @endif
                    </tr>
                @endforeach
            @empty
                <tr><td colspan="{{ $columnas }}" class="vacio">El funcionario no tiene horarios asignados en este filtro.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="paginacion">{{ $periodos->links() }}</div>
