@php
    use App\Services\ProcesadorAsistencia as P;
    use App\Services\ReporteDireccion as R;

    // La unidad viaja al imprimible solo si se eligió una: sin ella la hoja es
    // de la dirección entera.
    $parametros = array_filter([
        'direccion' => $direccion,
        'nombre' => $nombreDireccion,
        'unidad' => $unidad,
        'nombreUnidad' => $unidad === null ? null : $nombreUnidad,
        'desde' => $desde,
        'hasta' => $hasta,
        'con' => $filtro,
    ], fn ($valor): bool => $valor !== null);

    // Todo lo que la columna «Faltas» cuenta como falta.
    //
    // Además del día sin ninguna marca van los que tienen una sola punta —marcó
    // la entrada y no la salida, o al revés— y los que caen en un horario mal
    // cargado. Tenían columna aparte porque no son lo mismo: en esos días la
    // persona estuvo y hay una marca que lo prueba. Se unificaron a pedido,
    // porque separados no cambiaban ninguna decisión.
    $comoFalta = [P::FALTA, P::SIN_ENTRADA, P::SIN_SALIDA, P::HORARIO_INVALIDO];

    /**
     * El atraso del rango en minutos enteros. El procesador lo cuenta en minutos
     * completos y lo guarda en segundos, así que la división es exacta.
     */
    $minutosDeAtraso = fn (int $segundos): int => intdiv($segundos, 60);
@endphp

{{-- Partial: se inyecta bajo el filtro del reporte vía AJAX (no lleva layout). --}}
<style>
    .fila-desplegable { cursor: pointer; }
    .fila-desplegable--abierta td { background: #f0fdf4; }
    .pestana-detalle { display: flex; align-items: center; gap: .5rem; background: none; border: 0; padding: 0;
        font: inherit; color: inherit; text-align: left; cursor: pointer; }
    .pestana-detalle__icono { width: 1rem; height: 1rem; flex-shrink: 0; color: var(--verde); transition: transform .15s; }
    .fila-desplegable--abierta .pestana-detalle__icono { transform: rotate(90deg); }
</style>
<div class="card card--padded">
    <div class="cabecera" style="margin-bottom: 1rem;">
        <div>
            <strong>{{ $nombreDireccion }}</strong><br>
            <span style="color: var(--muted);">
                {{-- Qué alcance tiene la tabla: sin esto no se sabe si es la
                     dirección entera o una unidad suelta. --}}
                {{ $nombreUnidad }} ·
                Rango: {{ $desde }} a {{ $hasta }} · {{ $alcance }}
            </span>
        </div>
        <div class="acciones">
            <a class="btn" target="_blank" rel="noopener"
               href="{{ route('reportes.marcaciones.direccion.generar', $parametros + ['print' => 1]) }}"><x-heroicon-o-printer />Imprimir</a>
            @can('Export:Reporte')
                <a class="btn btn--gris"
                   href="{{ route('reportes.marcaciones.direccion.generar', $parametros + ['print' => 2]) }}"><x-heroicon-o-table-cells />Excel</a>
            @endcan
        </div>
    </div>

    @if ($filas->isEmpty())
        <p class="vacio" style="margin: 0;">
            @if ($filtro)
                Nadie de esta dirección queda con el filtro «{{ \App\Services\ReporteDireccion::FILTROS[$filtro]['etiqueta'] }}» en el rango.
            @else
                Nadie de esta dirección tuvo contrato dentro del rango, así que no hay días que controlar.
            @endif
        </p>
    @else
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th>Funcionario</th>
                        <th>Contrato(s) en el rango</th>
                        @if ($cruzaMeses)
                            <th>Mes</th>
                        @endif
                        <th title="Suma de los minutos de atraso del mes">Minutos acumulados</th>
                        <th>Atrasos</th>
                        <th>Abandonos</th>
                        <th title="Sin ninguna marca, con una sola punta, o horario mal configurado">Faltas</th>
                        <th>Licencia</th>
                    </tr>
                </thead>

                {{-- Un `tbody` por funcionario, no un `tr`: la fila de totales y la
                     del detalle desplegable comparten el mismo estado de Alpine, y
                     dos `tr` hermanos no pueden estar en un mismo `x-data`. --}}
                @foreach ($filas as $fila)
                    @php
                        $persona = $fila['persona'];
                        $porEstado = $fila['totales']['porEstado'];
                        $nombre = $persona['nombreFormal'] ?: $persona['nombre'];
                        $faltas = R::contar($porEstado, $comoFalta);
                        $abandonos = R::contar($porEstado, [P::ABANDONO]);
                        $atrasos = R::contar($porEstado, [P::ATRASO]);
                        $detalle = 'detalle-'.$loop->index;
                        $suyo = ['persona' => $persona['ci'], 'desde' => $desde, 'hasta' => $hasta];
                    @endphp

                    {{-- El detalle día por día se pide recién al desplegarlo, y es el
                         mismo parcial del reporte individual. Traerlos todos de entrada
                         serían cientos de tablas que casi nunca se miran. --}}
                    <tbody x-data="{
                            abierto: false,
                            cargado: false,
                            cargando: false,
                            html: '',
                            async alternar() {
                                this.abierto = ! this.abierto;
                                if (! this.abierto || this.cargado || this.cargando) { return; }
                                this.cargando = true;
                                try {
                                    const resp = await fetch('{{ route('reportes.marcaciones.procesado.generar', $suyo + ['print' => 0, 'encabezado' => 0]) }}', { headers: { 'Accept': 'text/html' } });
                                    this.html = resp.ok ? await resp.text() : '<div class=\'aviso aviso--error\'>No se pudo traer el detalle.</div>';
                                    this.cargado = resp.ok;
                                } catch (e) {
                                    this.html = '<div class=\'aviso aviso--error\'>Error al traer el detalle.</div>';
                                } finally {
                                    this.cargando = false;
                                }
                            },
                        }">
                        {{-- Toda la fila despliega el detalle; la pestaña del nombre lo
                             indica. Imprimir y Excel están dentro del detalle. --}}
                        <tr class="fila-desplegable" x-on:click="alternar()" :class="abierto && 'fila-desplegable--abierta'">
                            <td>
                                <button type="button" class="pestana-detalle" x-on:click.stop="alternar()"
                                        :aria-expanded="abierto" aria-controls="{{ $detalle }}"
                                        :title="abierto ? 'Ocultar el detalle' : 'Ver el detalle día por día'">
                                    <x-heroicon-s-chevron-right class="pestana-detalle__icono" />
                                    <span>
                                        <strong>{{ $nombre ?: 'Sin nombre' }}</strong><br>
                                        <small style="color: var(--muted);">CI {{ $persona['ci'] }}</small>
                                    </span>
                                </button>
                            </td>
                            <td>
                                {{-- Los tramos y no «el cargo de hoy»: quien renovó o se pasó
                                     de dirección tiene más de uno, y el reporte tiene que poder
                                     decir de dónde sale cada día controlado. --}}
                                @foreach ($fila['tramos'] as $tramo)
                                    <div style="white-space: nowrap;">
                                        {{ $tramo['desde']->format('d/m/Y') }} –
                                        {{ $tramo['hasta']?->format('d/m/Y') ?? 'vigente' }}
                                        @if ($tramo['direccion'])
                                            <small style="color: var(--muted);">· {{ $tramo['direccion'] }}</small>
                                        @endif
                                    </div>
                                @endforeach
                            </td>
                            @if ($cruzaMeses)
                                <td style="color: var(--muted);">Total</td>
                            @endif
                            {{-- Los minutos se acumulan por mes: doce en enero y diez en
                                 febrero no son veintidós. Cruzando meses el subtotal de la
                                 persona va con raya y los números viven en su mes. --}}
                            <td @if ($atrasos > 0 && ! $cruzaMeses) style="color: #92400e; font-weight: 600;" @endif>
                                @if ($cruzaMeses)
                                    <span style="color: var(--muted);" title="Los minutos se acumulan por mes y no se suman entre meses">—</span>
                                @else
                                    {{ $minutosDeAtraso($fila['totales']['atraso']) ?: '' }}
                                @endif
                            </td>
                            <td @if ($atrasos > 0) style="color: #92400e; font-weight: 600;" @endif>{{ $atrasos ?: '' }}</td>
                            <td @if ($abandonos > 0) style="color: #991b1b; font-weight: 700;" @endif>{{ $abandonos ?: '' }}</td>
                            <td @if ($faltas > 0) style="color: #991b1b; font-weight: 700;" @endif>{{ $faltas ?: '' }}</td>
                            <td>{{ R::contar($porEstado, [P::LICENCIA]) ?: '' }}</td>
                        </tr>
                        @if ($cruzaMeses)
                            @foreach ($fila['meses'] as $mes)
                                @php
                                    $delMes = $mes['totales']['porEstado'];
                                    $atrasosMes = R::contar($delMes, [P::ATRASO]);
                                @endphp
                                <tr style="background: var(--bg);">
                                    <td colspan="2"></td>
                                    <td style="white-space: nowrap;">{{ $mes['etiqueta'] }}</td>
                                    <td @if ($atrasosMes > 0) style="color: #92400e; font-weight: 600;" @endif>
                                        {{ $minutosDeAtraso($mes['totales']['atraso']) ?: '' }}
                                    </td>
                                    <td @if ($atrasosMes > 0) style="color: #92400e; font-weight: 600;" @endif>{{ $atrasosMes ?: '' }}</td>
                                    <td>{{ R::contar($delMes, [P::ABANDONO]) ?: '' }}</td>
                                    <td>{{ R::contar($delMes, $comoFalta) ?: '' }}</td>
                                    <td>{{ R::contar($delMes, [P::LICENCIA]) ?: '' }}</td>
                                </tr>
                            @endforeach
                        @endif

                        <tr x-show="abierto" x-cloak id="{{ $detalle }}">
                            <td colspan="{{ $cruzaMeses ? 8 : 7 }}" style="background: var(--bg); padding: .75rem;">
                                <div x-show="cargando" style="color: var(--muted); padding: .5rem;">Cargando el detalle…</div>
                                <div x-html="html"></div>
                            </td>
                        </tr>
                    </tbody>
                @endforeach

                <tfoot>
                    <tr>
                        <th colspan="{{ $cruzaMeses ? 3 : 2 }}" style="text-align: right;">Total:</th>
                        <th>
                            @if ($cruzaMeses)
                                <span style="font-weight: normal;" title="Los minutos se acumulan por mes y no se suman entre meses">—</span>
                            @else
                                {{ $minutosDeAtraso($totales['atraso']) }}
                            @endif
                        </th>
                        <th>{{ R::contar($totales['porEstado'], [P::ATRASO]) }}</th>
                        <th>{{ R::contar($totales['porEstado'], [P::ABANDONO]) }}</th>
                        <th>{{ R::contar($totales['porEstado'], $comoFalta) }}</th>
                        <th>{{ R::contar($totales['porEstado'], [P::LICENCIA]) }}</th>
                    </tr>
                </tfoot>
            </table>
        </div>

        <p style="color: var(--muted); font-size: .8rem; margin-bottom: 0;">
            <strong>Minutos acumulados</strong> = la suma de los atrasos, medidos contra la hora
            de entrada del horario, y <strong>Atrasos</strong> en cuántos días ocurrieron. <strong>Abandono</strong> = se retiró antes de la mínima hora de
            salida, o no marcó un tramo que la licencia no cubría.
            <strong>Faltas</strong> junta el día sin ninguna marca con el que tiene una sola
            punta —marcó la entrada y no la salida, o al revés— y el que cae en un horario mal
            cargado. Los días sin jornada —no laborables, o que ningún contrato cubría— no
            cuentan en ninguna columna.
            @if ($cruzaMeses)
                <br><strong>Los minutos se acumulan por mes calendario y no se suman entre
                meses</strong>: doce en un mes y diez en el siguiente no son veintidós. Por eso el
                rango se abre por mes y el total va con raya.
            @endif
        </p>
    @endif
</div>
