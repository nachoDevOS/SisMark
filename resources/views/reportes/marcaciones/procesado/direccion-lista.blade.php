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
    ], fn ($valor): bool => $valor !== null);

    // Los estados que se agrupan en la columna «Sin marca»: son la misma omisión
    // mirada desde un borde distinto del turno, y darle columna propia a cada
    // una ensanchaba la tabla sin decir nada nuevo.
    $sinMarca = [P::SIN_ENTRADA, P::SIN_SALIDA, P::TURNO_INVALIDO];

    /**
     * Días con jornada que controlar: sacando los que no eran laborables y los
     * que ningún contrato cubría. Es el denominador honesto de la fila —contra
     * el total del rango, quien entró el día 20 parecería haber faltado 19
     * veces—.
     */
    $conJornada = fn (array $porEstado, int $dias): int => $dias
        - R::contar($porEstado, [P::NO_LABORABLE, P::SIN_CONTRATO]);
@endphp

{{-- Partial: se inyecta bajo el filtro del reporte vía AJAX (no lleva layout). --}}
<div class="card card--padded">
    <div class="cabecera" style="margin-bottom: 1rem;">
        <div>
            <strong>{{ $nombreDireccion }}</strong><br>
            <span style="color: var(--muted);">
                {{-- Qué alcance tiene la tabla: sin esto no se sabe si es la
                     dirección entera o una unidad suelta. --}}
                {{ $nombreUnidad }} ·
                Rango: {{ $desde }} a {{ $hasta }} · {{ $totales['funcionarios'] }} funcionario(s)
            </span>
        </div>
        <div class="acciones">
            <a class="btn" target="_blank" rel="noopener"
               href="{{ route('reportes.marcaciones.direccion.generar', $parametros + ['print' => 1]) }}"><x-heroicon-o-printer />Imprimir</a>
        </div>
    </div>

    @if ($filas->isEmpty())
        <p class="vacio" style="margin: 0;">
            Nadie de esta dirección tuvo contrato dentro del rango, así que no hay días que controlar.
        </p>
    @else
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th>Funcionario</th>
                        <th>Contrato(s) en el rango</th>
                        <th title="Días con jornada: sin los no laborables ni los que ningún contrato cubre">Días</th>
                        <th>Cumple</th>
                        <th>Atrasos</th>
                        <th>Faltas</th>
                        <th>Abandonos</th>
                        <th title="Sin entrada, sin salida o turno mal configurado">Sin marca</th>
                        <th>Licencia</th>
                        <th>Computado</th>
                        <th>Saldo</th>
                        <th></th>
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
                        $saldo = $fila['totales']['saldo'];
                        $faltas = R::contar($porEstado, [P::FALTA]);
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
                        <tr>
                            <td>
                                <strong>{{ $nombre ?: 'Sin nombre' }}</strong><br>
                                <small style="color: var(--muted);">CI {{ $persona['ci'] }}</small>
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
                            <td>{{ $conJornada($porEstado, $fila['totales']['dias']) }}</td>
                            <td>{{ R::contar($porEstado, [P::CUMPLE]) }}</td>
                            <td @if ($atrasos > 0) style="color: #92400e; font-weight: 600;" @endif>
                                {{ $atrasos ?: '' }}
                                @if ($fila['totales']['atraso'] > 0)
                                    <small>({{ P::desvio($fila['totales']['atraso']) }})</small>
                                @endif
                            </td>
                            <td @if ($faltas > 0) style="color: #991b1b; font-weight: 700;" @endif>{{ $faltas ?: '' }}</td>
                            <td @if ($abandonos > 0) style="color: #991b1b; font-weight: 700;" @endif>{{ $abandonos ?: '' }}</td>
                            <td>{{ R::contar($porEstado, $sinMarca) ?: '' }}</td>
                            <td>{{ R::contar($porEstado, [P::LICENCIA]) ?: '' }}</td>
                            <td style="white-space: nowrap;">{{ P::duracion($fila['totales']['computado']) }}</td>
                            <td style="white-space: nowrap;{{ $saldo < 0 ? ' color: #991b1b;' : '' }}">
                                {{ $saldo > 0 ? '+' : '' }}{{ P::duracion($saldo) }}
                            </td>
                            <td style="white-space: nowrap;">
                                <button type="button" class="btn btn--gris" style="padding: .3rem .55rem;"
                                        x-on:click="alternar()" :aria-expanded="abierto" aria-controls="{{ $detalle }}">
                                    <span x-text="abierto ? 'Ocultar' : 'Detalle'">Detalle</span>
                                </button>
                                <a class="btn btn--gris" style="padding: .3rem .55rem;" target="_blank" rel="noopener"
                                   href="{{ route('reportes.marcaciones.procesado.generar', $suyo + ['print' => 1]) }}"
                                   title="Imprimible individual"><x-heroicon-o-printer /></a>
                            </td>
                        </tr>
                        <tr x-show="abierto" x-cloak id="{{ $detalle }}">
                            <td colspan="12" style="background: var(--bg);">
                                <div x-show="cargando" style="color: var(--muted); padding: .5rem;">Cargando el detalle…</div>
                                <div x-html="html"></div>
                            </td>
                        </tr>
                    </tbody>
                @endforeach

                <tfoot>
                    <tr>
                        <th colspan="2" style="text-align: right;">Total de la dirección:</th>
                        <th>{{ $conJornada($totales['porEstado'], $totales['dias']) }}</th>
                        <th>{{ R::contar($totales['porEstado'], [P::CUMPLE]) }}</th>
                        <th>
                            {{ R::contar($totales['porEstado'], [P::ATRASO]) }}
                            <small>({{ P::desvio($totales['atraso']) }})</small>
                        </th>
                        <th>{{ R::contar($totales['porEstado'], [P::FALTA]) }}</th>
                        <th>{{ R::contar($totales['porEstado'], [P::ABANDONO]) }}</th>
                        <th>{{ R::contar($totales['porEstado'], $sinMarca) }}</th>
                        <th>{{ R::contar($totales['porEstado'], [P::LICENCIA]) }}</th>
                        <th style="white-space: nowrap;">{{ P::duracion($totales['computado']) }}</th>
                        <th style="white-space: nowrap;">{{ $totales['saldo'] > 0 ? '+' : '' }}{{ P::duracion($totales['saldo']) }}</th>
                        <th></th>
                    </tr>
                </tfoot>
            </table>
        </div>

        <p style="color: var(--muted); font-size: .8rem; margin-bottom: 0;">
            <strong>Días</strong> = los que tenían jornada: no cuentan los no laborables ni los que
            ningún contrato cubría. <strong>Computado</strong> son horas acotadas al turno, y
            <strong>Saldo</strong> es lo computado contra lo esperado.
            <strong>Abandono</strong> = se retiró antes de la mínima hora de salida, o no marcó un
            tramo que la licencia no cubría.
        </p>
    @endif
</div>
