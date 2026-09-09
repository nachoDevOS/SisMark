@php
    $pillPorSituacion = ['vigente' => 'pill--ok', 'vencida' => 'pill--no', 'futura' => 'pill--info'];
    $etiquetaSituacion = ['vigente' => 'Vigente', 'vencida' => 'Vencida', 'futura' => 'Aún no vigente'];
    // Sin acciones cuando la tabla se muestra solo de referencia (modal de licencia).
    $conAcciones = $conAcciones ?? true;
    $columnas = $conAcciones ? 3 : 2;

    /**
     * El nombre del turno del SIA suele ser «LUN: 08:00 - 16:00», que es día y
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
                            <span class="periodo__conteo">
                                {{ $delPeriodo->count() }} {{ $delPeriodo->count() === 1 ? 'día' : 'días' }}
                            </span>
                        </div>
                    </th>
                </tr>
                @foreach ($delPeriodo as $asignacion)
                    @php
                        $entrada = $asignacion->turno?->hEntrada?->format('H:i');
                        $salida = $asignacion->turno?->hSalida?->format('H:i');
                        $nombre = $nombreQueAporta($asignacion->turno?->nombreTurno, $entrada, $salida);
                    @endphp
                    <tr @class(['fila--inactiva' => $vencido])>
                        <td>
                            <strong>{{ $asignacion->turno?->nombre_dia ?? '—' }}</strong>
                            @if ($nombre !== '')
                                <div class="ayuda">{{ $nombre }}</div>
                            @endif
                        </td>
                        <td class="horario">
                            {{ $entrada ?? '—' }}<span class="horario__sep" aria-hidden="true">→</span>{{ $salida ?? '—' }}
                        </td>
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
