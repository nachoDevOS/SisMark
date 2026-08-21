{{--
    Solapa «Régimen RIP» de la ficha del funcionario: qué acumuló en el mes y
    qué sanción le correspondería según el Reglamento Interno de Personal.

    Espera: $resultado (o null si no llegó cédula), $periodo y $mesEtiqueta.

    Nada de esto está guardado: se calcula al vuelo cada vez que se abre. Es la
    pantalla con la que Recursos Humanos compara contra los meses que ya
    resolvió a mano, antes de que estas cifras funden un memorándum.
--}}
@php
    use App\Services\EscalaRip;
    use App\Services\HaberFuncionario;

    $pillPorGravedad = [
        EscalaRip::LEVE => 'pill--advertencia',
        EscalaRip::GRAVE => 'pill--no',
        EscalaRip::GRAVISIMA => 'pill--no',
    ];
@endphp

@if ($resultado === null)
    <div class="vacio">Sin funcionario seleccionado.</div>
@else
    @php
        $acumulado = $resultado['acumulado'];
        $minutos = intdiv($acumulado['atrasoSegundos'], 60);
    @endphp

    {{-- El mes abierto se corta en ayer: los días que todavía no llegaron no se
         califican. Sin este aviso, la cifra parcial se lee como definitiva. --}}
    @if ($resultado['enCurso'])
        <div class="aviso aviso--advertencia">
            <strong>Mes en curso.</strong>
            Calificado hasta el {{ $resultado['hasta']->format('d/m/Y') }} inclusive; lo que falta del mes no se cuenta.
            La sanción se determina a la conclusión del mes (Art. 51.II), así que esto es un avance.
        </div>
    @endif

    <div class="stats-grid">
        <div class="stat-card {{ $minutos > 30 ? 'stat-card--warning' : '' }}">
            <div class="stat-card__valor">{{ $minutos }} min</div>
            <div class="stat-card__label">Atraso acumulado</div>
            <div class="stat-card__sub">Sin sanción hasta 30 min · Art. 45.I</div>
        </div>

        <div class="stat-card {{ $acumulado['inasistenciaJornadas'] > 0 ? 'stat-card--danger' : '' }}">
            <div class="stat-card__valor">{{ rtrim(rtrim(number_format($acumulado['inasistenciaJornadas'], 2, ',', ''), '0'), ',') }}</div>
            <div class="stat-card__label">Inasistencias (jornadas)</div>
            <div class="stat-card__sub">
                {{ $acumulado['inasistenciaDias'] }} día(s) · racha máxima {{ $acumulado['inasistenciaContinuos'] }}
            </div>
        </div>

        <div class="stat-card {{ $acumulado['ausenciaJornadas'] > 0 ? 'stat-card--danger' : '' }}">
            <div class="stat-card__valor">{{ rtrim(rtrim(number_format($acumulado['ausenciaJornadas'], 2, ',', ''), '0'), ',') }}</div>
            <div class="stat-card__label">Ausencias en el puesto</div>
            <div class="stat-card__sub">
                {{ $acumulado['ausenciaDias'] }} día(s) · racha máxima {{ $acumulado['ausenciaContinuos'] }}
            </div>
        </div>

        <div class="stat-card {{ $acumulado['omisiones'] > 0 ? 'stat-card--warning' : '' }}">
            <div class="stat-card__valor">{{ $acumulado['omisiones'] }}</div>
            <div class="stat-card__label">Omisiones de registro</div>
            <div class="stat-card__sub">Sin regularizar · Art. 45.III</div>
        </div>

        {{-- Lo que el mes le costaría, en plata. Es lo único de esta pantalla
             que no sale del reglamento sino del contrato del funcionario. --}}
        <div class="stat-card {{ ($resultado['totalMonto'] ?? 0) > 0 ? 'stat-card--danger' : '' }}">
            <div class="stat-card__valor">
                @if ($resultado['totalDias'] === null)
                    —
                @else
                    {{ HaberFuncionario::bolivianos($resultado['totalMonto']) }}
                @endif
            </div>
            <div class="stat-card__label">Descuento del mes</div>
            <div class="stat-card__sub">
                @if ($resultado['totalDias'] === null)
                    Corresponde proceso interno: el monto lo fija la Autoridad Sumariante.
                @elseif (! $resultado['haber']['conHaber'])
                    Sin haber en el contrato de Mamoré: no se puede pasar a bolivianos.
                @else
                    {{ EscalaRip::dias($resultado['totalDias']) }}
                @endif
            </div>
        </div>
    </div>

    {{-- Un turno mal cargado no puede fundar un descuento: esos días se apartan
         antes de calificar y se avisa acá para que se corrijan. --}}
    @if ($resultado['diasApartados'] > 0)
        <div class="aviso aviso--advertencia">
            <strong>{{ $resultado['diasApartados'] }} día(s) apartados por turno mal configurado.</strong>
            No se califican: una tolerancia o una ventana mal cargadas darían un resultado limpio sobre un dato roto.
            Fechas: {{ collect($resultado['apartados'])->map(fn ($d) => $d['fecha']->format('d/m'))->implode(', ') }}.
        </div>
    @endif

    <div class="card card--padded" style="margin-bottom: 1rem;">
        <h2 style="font-size: .9375rem; margin: 0 0 .85rem;">
            Sanción que correspondería — {{ $mesEtiqueta }}
        </h2>

        @if ($resultado['sanciones'] === [])
            <div class="aviso aviso--exito" style="margin: 0;">
                <strong>Sin observaciones.</strong>
                {{ $resultado['diasControlados'] }} día(s) controlados en el mes, sin conducta sancionable.
            </div>
        @else
            <table>
                <thead>
                    <tr>
                        <th>Conducta</th>
                        <th>Detalle</th>
                        <th>Gravedad</th>
                        <th>Artículo</th>
                        <th>Descuento</th>
                        <th>Monto</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($resultado['sanciones'] as $sancion)
                        <tr>
                            <td>
                                {{ $sancion['tipoEtiqueta'] }}
                                @if ($sancion['reincidenciaEtiqueta'] !== '')
                                    <div class="persona-meta">{{ $sancion['reincidenciaEtiqueta'] }}</div>
                                @endif
                            </td>
                            <td>{{ $sancion['detalle'] }}</td>
                            <td>
                                <span class="pill {{ $pillPorGravedad[$sancion['gravedad']] ?? 'pill--info' }}">
                                    {{ $sancion['gravedadEtiqueta'] }}
                                </span>
                            </td>
                            <td>Art. {{ $sancion['articulo'] }}</td>
                            <td>
                                {{-- El proceso interno no lleva monto: lo resuelve
                                     la Autoridad Sumariante (Art. 47), y poner un
                                     número acá lo daría por decidido. --}}
                                @if ($sancion['procesoInterno'])
                                    <span class="pill pill--no">Proceso interno</span>
                                @else
                                    <strong>{{ EscalaRip::dias($sancion['dias']) }}</strong>
                                @endif
                            </td>
                            <td>
                                @if ($sancion['procesoInterno'])
                                    —
                                @else
                                    <strong>{{ HaberFuncionario::bolivianos($sancion['monto']) }}</strong>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <p style="margin: .85rem 0 0; font-size: .8125rem;">
                @if ($resultado['totalDias'] === null)
                    <strong>Total: corresponde proceso administrativo interno.</strong>
                    No hay monto hasta que lo resuelva la Autoridad Sumariante.
                @elseif (! $resultado['haber']['conHaber'])
                    <strong>Total del mes: {{ EscalaRip::dias($resultado['totalDias']) }}.</strong>
                    El contrato del funcionario no trae haber, así que no se puede expresar en bolivianos.
                @else
                    <strong>
                        Total del mes: {{ EscalaRip::dias($resultado['totalDias']) }} =
                        {{ HaberFuncionario::bolivianos($resultado['totalMonto']) }}.
                    </strong>
                @endif
            </p>
        @endif
    </div>

    <div class="card">
        <table>
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Turno</th>
                    <th>Conducta</th>
                    <th>Detalle</th>
                    <th>Artículo</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($resultado['hechos'] as $hecho)
                    <tr>
                        <td>{{ $hecho['fecha']->format('d/m/Y') }}</td>
                        <td>{{ $hecho['turno'] ?? '—' }}</td>
                        <td>{{ $hecho['tipoEtiqueta'] }}</td>
                        <td>{{ $hecho['detalle'] }}</td>
                        <td>Art. {{ $hecho['articulo'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="vacio">Ningún día del mes registró una conducta sancionable.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Con qué criterio se calculó. Va siempre: una sanción económica tiene
         que poder explicarse, y hay dos artículos del reglamento que admiten
         más de una lectura. --}}
    <p class="persona-meta" style="margin-top: .85rem;">
        Calculado con el atraso medido desde
        <strong>{{ config('rip.atrasoDesde') === EscalaRip::DESDE_TOLERANCIA ? 'el fin de la tolerancia' : 'la hora nominal de entrada' }}</strong>,
        corte de inasistencia a los <strong>{{ intdiv((int) config('rip.corteInasistencia'), 60) }} minutos</strong>
        y tolerancia
        <strong>{{ config('rip.toleranciaFija') === null ? 'la cargada en cada turno' : intdiv((int) config('rip.toleranciaFija'), 60).' min fija' }}</strong>.
        Cálculo al vuelo, sin cierre guardado: no funda un memorándum.
    </p>

    {{-- De dónde salió la plata. Un descuento tiene que poder explicarse hasta
         el último boliviano, y el haber no es de SisMark: viene del contrato
         firmado que administra Mamoré. --}}
    @if ($resultado['haber']['conHaber'])
        @php($haber = $resultado['haber'])
        <p class="persona-meta" style="margin-top: .35rem;">
            Haber del contrato en Mamoré · base tomada:
            <strong>{{ $haber['baseEtiqueta'] }}</strong> ·
            un día = el haber dividido entre <strong>{{ $haber['divisor'] }}</strong>,
            cubra el contrato el mes entero o unos pocos días.

            @if (count($haber['tramos']) > 1)
                {{-- Dos contratos en el mismo mes con sueldos distintos: los
                     días de la escala se reparten en proporción a lo que se
                     acumuló bajo cada uno, porque el cómputo del reglamento es
                     mensual y no se puede partir en dos sin repartirlo. --}}
                <strong>Este mes tiene {{ count($haber['tramos']) }} contratos</strong>,
                y cada día de sanción se cobra al que regía cuando se cometió.
            @endif
        </p>

        <ul class="persona-meta" style="margin: .3rem 0 0; padding-left: 1.1rem;">
            @foreach ($haber['tramos'] as $tramo)
                <li>
                    Del <strong>{{ $tramo['desde']->format('d/m') }}</strong>
                    al <strong>{{ $tramo['hasta']->format('d/m') }}</strong>
                    ({{ $tramo['dias'] }} {{ $tramo['dias'] === 1 ? 'día' : 'días' }}):
                    @if ($tramo['valorDia'] === null)
                        el contrato no trae haber cargado, así que sus días no se pueden pasar a bolivianos.
                    @else
                        {{ HaberFuncionario::bolivianos($tramo['sueldo']) }}
                        @if ($tramo['bono'] !== null)
                            (bono {{ HaberFuncionario::bolivianos($tramo['bono']) }})
                        @endif
                        · un día = {{ HaberFuncionario::bolivianos($tramo['base']) }}
                        ÷ {{ $haber['divisor'] }} =
                        <strong>{{ HaberFuncionario::bolivianos($tramo['valorDia']) }}</strong>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
@endif
