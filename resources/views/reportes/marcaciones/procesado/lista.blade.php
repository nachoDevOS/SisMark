@php
    use App\Models\Turno;
    use App\Services\ProcesadorAsistencia as P;

    // $persona es la ficha resuelta (Mamoré, con la base local como respaldo).
    $nombreEmpleado = $persona['nombreFormal'] ?: $persona['nombre'];
    $parametros = ['persona' => $persona['ci'], 'desde' => $desde, 'hasta' => $hasta];

    // La tabla en pantalla lista solo los días con turno asignado: los «no
    // laborable» no se controlan y llenaban el listado de filas vacías. Siguen
    // contados en «Días por estado» del resumen, y el imprimible los conserva
    // porque ese formato replica el reporte del sistema de escritorio viejo.
    $diasConTurno = $dias->reject(fn (array $dia): bool => $dia['estado'] === P::NO_LABORABLE);
@endphp

{{-- Partial: se inyecta bajo el filtro del reporte vía AJAX (no lleva layout). --}}
<div class="card card--padded">
    <div class="cabecera" style="margin-bottom: 1rem;">
        <div>
            {{-- Quién es solo hace falta en la pantalla general del reporte, donde
                 se elige al funcionario de un combo y la tabla es lo único que hay.
                 Servido dentro de la ficha, la cabecera de arriba ya lo dice con
                 más detalle y repetirlo acá le roba lugar a la tabla. --}}
            @if ($conEncabezado ?? true)
                <strong>{{ $nombreEmpleado ?: 'Funcionario' }}</strong> · CI {{ $persona['ci'] }} ·
                PIN reloj {{ $persona['pinReloj'] ?: '—' }}<br>
                @if (!empty($persona['cargo']))
                    <span style="color: var(--muted);">
                        {{ $persona['cargo'] }}{{ empty($persona['direccion']) ? '' : ' · '.$persona['direccion'] }}
                    </span><br>
                @endif
            @endif
            <span style="color: var(--muted);">
                Rango: {{ $desde ?: '—' }} a {{ $hasta ?: '—' }} · {{ $totales['dias'] }} día(s)
            </span>
        </div>
        <div class="acciones">
            <a class="btn" target="_blank" rel="noopener"
               href="{{ route('reportes.marcaciones.procesado.generar', $parametros + ['print' => 1]) }}"><x-heroicon-o-printer />Imprimir</a>
            @can('Export:Reporte')
                <a class="btn btn--gris"
                   href="{{ route('reportes.marcaciones.procesado.generar', $parametros + ['print' => 2]) }}"><x-heroicon-o-table-cells />Excel</a>
            @endcan
        </div>
    </div>

    {{-- Columnas del reporte del sistema de escritorio viejo, con «Día» sumado
         adelante.

         La pantalla no lleva barra de resumen: el total de horas, el saldo, los
         desvíos acumulados y el conteo por estado son de lectura mensual y acá
         estorbaban arriba de lo que se viene a mirar, que es día por día. El
         imprimible y el Excel sí los conservan, que es donde ese cierre sirve. --}}
    <div style="overflow-x: auto;">
        <table>
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Día</th>
                    <th>Turno</th>
                    <th>Entró</th>
                    <th>Salió</th>
                    <th>Atraso</th>
                    <th>Abandono</th>
                    <th>Falta</th>
                    <th>Entrada lic.</th>
                    <th>Salida lic.</th>
                    <th title="Licencia de turno completo">T.C.</th>
                    <th title="Con goce de haberes">C.G.H.</th>
                    <th>Motivo licencia</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($diasConTurno as $dia)
                    @php
                        $nombreDia = Turno::DIAS[$dia['fecha']->dayOfWeek + 1] ?? '—';
                        $filas = max(1, count($dia['bloques']));
                    @endphp

                    @if ($dia['bloques'] === [])
                        {{-- Día resuelto sin mirar turnos: excepcional o sin turno asignado. --}}
                        <tr>
                            <td><strong>{{ $dia['fecha']->format('d/m/Y') }}</strong></td>
                            <td>{{ $nombreDia }}</td>
                            <td colspan="10" style="color: var(--muted);">
                                <span class="pill {{ P::COLORES[$dia['estado']] ?? 'pill--info' }}">{{ P::ETIQUETAS[$dia['estado']] ?? $dia['estado'] }}</span>
                                @if ($dia['marcas'] !== [])
                                    <small>· marcó {{ collect($dia['marcas'])->map(fn ($s) => P::hora($s))->implode(', ') }}</small>
                                @endif
                            </td>
                            <td>{{ $dia['motivo'] ?: '—' }}</td>
                        </tr>
                    @else
                        @foreach ($dia['bloques'] as $indice => $bloque)
                            @php
                                $licencia = $bloque['licencia'];
                                $falta = P::FALTAS[$bloque['estado']] ?? '';
                            @endphp
                            <tr>
                                @if ($indice === 0)
                                    <td rowspan="{{ $filas }}"><strong>{{ $dia['fecha']->format('d/m/Y') }}</strong></td>
                                    <td rowspan="{{ $filas }}">{{ $nombreDia }}</td>
                                @endif
                                {{-- Solo el horario: los avisos de configuración del turno
                                     (`$bloque['avisos']`) repetían la misma advertencia en cada
                                     fila y tapaban los datos del día. --}}
                                <td>{{ trim((string) $bloque['turno']->nombreTurno) }}</td>
                                <td>
                                    {{ $bloque['entrada'] === null ? '' : P::hora($bloque['entrada']) }}
                                    @unless ($bloque['entradaExigida'])
                                        <small style="color: var(--muted);">licencia</small>
                                    @endunless
                                </td>
                                <td>
                                    {{ $bloque['salida'] === null ? '' : P::hora($bloque['salida']) }}
                                    @unless ($bloque['salidaExigida'])
                                        <small style="color: var(--muted);">licencia</small>
                                    @endunless
                                </td>
                                <td style="color: #92400e; font-weight: 600;">{{ $bloque['atraso'] > 0 ? P::desvio($bloque['atraso']) : '' }}</td>
                                <td style="color: #991b1b; font-weight: 700;">{{ $bloque['estado'] === P::ABANDONO ? 'ABANDONO' : '' }}</td>
                                <td style="color: #991b1b; font-weight: 700;">{{ $falta }}</td>
                                <td>{{ $licencia?->lEntra?->format('H:i') ?? '' }}</td>
                                <td>{{ $licencia?->lSale?->format('H:i') ?? '' }}</td>
                                <td>{{ $licencia === null ? '' : ($licencia->tCompleto ? 'Sí' : 'No') }}</td>
                                <td>{{ $licencia === null ? '' : ($licencia->goceHaberes ? 'Sí' : 'No') }}</td>
                                <td>{{ $licencia?->motivo ?? '' }}</td>
                            </tr>
                        @endforeach
                    @endif
                @empty
                    <tr><td colspan="13" class="vacio">Sin días con turno asignado en el rango seleccionado.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <p style="color: var(--muted); font-size: .8rem; margin-bottom: 0;">
        <strong>T.C.</strong> = licencia de turno completo · <strong>C.G.H.</strong> = con goce de haberes.
        El atraso se dispara con la tolerancia del turno y se mide contra su hora de entrada.
        <strong>Abandono</strong> = se retiró antes de la mínima hora de salida, o no marcó un tramo que la licencia no cubría.
    </p>
</div>
