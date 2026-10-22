{{--
    Un período de la solapa «Turnos/Horarios»: arriba el turno y las fechas;
    debajo, los días con su horario.

    Espera: $periodo (primera fila del período), $delPeriodo (sus filas),
    $asignacionTurno (o null si es del sistema anterior), $enContrato (va
    debajo de la cabecera de su contrato: se corre a la derecha y se pliega
    con él; $abierto dice si arranca a la vista) y lo que
    define `horarios-list` ($columnas, $conAcciones, $origenFicha,
    $pillPorSituacion, $etiquetaSituacion, $nombreQueAporta).
--}}
@php
    $situacion = $periodo->situacion;
    $vencido = $situacion === 'vencida';
    $sangria = $enContrato ? 'padding-left: 2.5rem;' : '';
    // Dentro de un contrato, las filas se pliegan con él.
    $plegable = $enContrato ? 'x-show="abierto"'.(($abierto ?? true) ? '' : ' x-cloak') : '';
@endphp
{{-- Cabecera del bloque: lo que se repetiría en cada día va una sola vez acá. --}}
<tr class="fila--periodo" {!! $plegable !!}>
    <th colspan="{{ $columnas }}" scope="colgroup">
        <div class="periodo" style="{{ $sangria }}">
            @if ($asignacionTurno)
                <span class="periodo__turno">{{ $asignacionTurno->turno?->nombre }}</span>
            @else
                <span class="periodo__turno periodo__turno--sia" title="Horarios migrados del sistema anterior (SIA): no están atados a ningún contrato">Sistema anterior</span>
            @endif
            <span class="periodo__separador" aria-hidden="true">·</span>
            <span class="periodo__fechas">
                {{ $periodo->desde?->format('d/m/Y') ?? '—' }}
                <span class="periodo__flecha" aria-hidden="true">→</span>
                {{ $periodo->hasta?->format('d/m/Y') ?? '—' }}
            </span>
            <span class="pill pill--mini {{ $pillPorSituacion[$situacion] ?? 'pill--info' }}">
                {{ $etiquetaSituacion[$situacion] ?? $situacion }}
            </span>
            {{-- Cargado después de terminado el contrato («Regularizar turno»). --}}
            @if (str_starts_with((string) $asignacionTurno?->observacion, 'Regularización.'))
                <span class="pill pill--mini pill--advertencia" title="{{ $asignacionTurno->observacion }}">Regularizado</span>
            @endif
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
        // Hasta qué hora de entrada no es atraso, y cuántos minutos son.
        $tolerancia = $asignacion->horario?->hTolerancia;
        $minutosTolerancia = $tolerancia && $asignacion->horario->hEntrada
            ? (int) $asignacion->horario->hEntrada->diffInMinutes($tolerancia)
            : null;
    @endphp
    <tr @class(['fila--inactiva' => $vencido]) {!! $plegable !!}>
        <td style="{{ $enContrato ? 'padding-left: 3.5rem;' : '' }}">
            <strong>{{ $asignacion->horario?->nombre_dia ?? '—' }}</strong>
            @if ($nombre !== '')
                <div class="ayuda">{{ $nombre }}</div>
            @endif
        </td>
        <td class="horario">
            {{ $entrada ?? '—' }}<span class="horario__sep" aria-hidden="true">→</span>{{ $salida ?? '—' }}
            @if ($minutosTolerancia !== null)
                <span class="horario__tolerancia" title="Hasta esa hora la entrada no cuenta como atraso">
                    {{ $minutosTolerancia > 0 ? 'tolerancia hasta '.$tolerancia->format('H:i').' ('.$minutosTolerancia.' min)' : 'sin tolerancia' }}
                </span>
            @endif
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
