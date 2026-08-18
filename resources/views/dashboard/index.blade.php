@extends('layouts.app')

@section('titulo', 'Escritorio')

@php
    $numero = fn (?int $n): string => $n === null ? '—' : number_format($n, 0, ',', '.');

    // Cuánto hace que no entra una marcación, en palabras.
    $minutosSinMarcar = $captura['minutos_sin_marcar'];
    $antiguedad = match (true) {
        $minutosSinMarcar === null => '—',
        $minutosSinMarcar < 60 => $minutosSinMarcar.' min',
        $minutosSinMarcar < 1440 => intdiv($minutosSinMarcar, 60).' h '.($minutosSinMarcar % 60).'′',
        default => intdiv($minutosSinMarcar, 1440).' d',
    };
    // Más de tres horas sin recibir nada en horario laboral suele ser un equipo
    // que dejó de reportar, no que nadie marcó.
    $capturaEstado = match (true) {
        $minutosSinMarcar === null => 'stat-card--danger',
        $minutosSinMarcar > 1440 => 'stat-card--danger',
        $minutosSinMarcar > 180 => 'stat-card--warning',
        default => 'stat-card--success',
    };

    $equipoViejo = $captura['equipo_desactualizado'];
    $comparacion = $captura['comparacion'];
    $desvio = $comparacion['desvio'];
    $comparacionEstado = match (true) {
        $desvio === null => '',
        $desvio <= -40 => 'stat-card--danger',
        $desvio <= -20 => 'stat-card--warning',
        default => 'stat-card--success',
    };

    $maxHistograma = max([1, ...$histograma['totales']]);
    $maxTendencia = max([1, ...$tendencia['totales']]);
@endphp

@section('contenido')
    <div class="cabecera">
        <div class="cabecera__titulo">
            <span class="cabecera__icono"><x-heroicon-o-home /></span>
            <h1>Escritorio</h1>
        </div>
        <span class="escritorio__fecha">{{ now()->translatedFormat('l j \d\e F, H:i') }}</span>
    </div>

    @if ($agenda['turnos_sin_asignar'])
        <div class="aviso aviso--advertencia escritorio__aviso">
            <x-heroicon-o-exclamation-triangle />
            <div>
                <strong>No hay turnos asignados a ningún funcionario.</strong>
                Sin eso el sistema no puede saber quién tenía que marcar, así que no
                hay control de cumplimiento: solo se cuentan las marcaciones que
                entran. Se resuelve migrando las asignaciones del SIA.
            </div>
        </div>
    @endif

    {{-- Solicitudes esperando decisión. Va arriba de todo porque es lo único
         del escritorio que pide una acción de una persona: el resto informa. --}}
    @if (($licenciasPendientes ?? 0) > 0)
        <div class="aviso aviso--advertencia escritorio__aviso">
            <x-heroicon-o-clipboard-document-check />
            <div>
                {{-- La frase se arma entera en una expresión: partida en varias
                     líneas, el HTML queda con saltos en medio del texto. --}}
                <strong>{{ $licenciasPendientes === 1
                    ? '1 solicitud de licencia espera tu decisión.'
                    : $licenciasPendientes.' solicitudes de licencia esperan tu decisión.' }}</strong>
                Las pidieron los funcionarios desde su perfil. Mientras sigan pendientes
                <strong>no justifican la ausencia</strong>: el cálculo de asistencia solo
                descuenta las aprobadas, así que esos días se les siguen contando como falta.
                <a href="{{ route('licencias.index', ['estado' => \App\Models\Licencia::PENDIENTE]) }}">Revisarlas</a>.
            </div>
        </div>
    @endif

    {{-- 1 · ¿Puedo confiar en los datos de hoy? --}}
    <h2 class="escritorio__seccion">Estado de la captura</h2>
    <div class="stats-grid">
        <div class="stat-card {{ $captura['equipos']['fuera_linea'] > 0 ? 'stat-card--warning' : 'stat-card--success' }}">
            <div class="stat-card__valor">{{ $captura['equipos']['en_linea'] }}/{{ $captura['equipos']['total'] }}</div>
            <div class="stat-card__label">Equipos en línea</div>
            <div class="stat-card__sub">
                @if ($captura['equipos']['total'] === 0)
                    Ningún equipo registrado todavía
                @else
                    {{ $captura['equipos']['fuera_linea'] }} fuera de línea
                @endif
            </div>
        </div>

        <div class="stat-card {{ $capturaEstado }}">
            <div class="stat-card__valor">{{ $antiguedad }}</div>
            <div class="stat-card__label">Desde la última marcación</div>
            <div class="stat-card__sub">
                {{ $captura['ultima_marcacion']?->format('d/m/Y H:i') ?? 'Nunca se recibió una marcación' }}
            </div>
        </div>

        <div class="stat-card {{ $equipoViejo && $equipoViejo->ultima_sync === null ? 'stat-card--warning' : '' }}">
            <div class="stat-card__valor">
                {{ $equipoViejo?->ultima_sync?->diffForHumans(null, true, true) ?? ($equipoViejo ? 'Nunca' : '—') }}
            </div>
            <div class="stat-card__label">Equipo más desactualizado</div>
            <div class="stat-card__sub">
                {{ $equipoViejo?->nombre ?? 'Sin equipos activos' }}
            </div>
        </div>

        <div class="stat-card {{ $comparacionEstado }}">
            <div class="stat-card__valor">{{ $numero($comparacion['hoy']) }}</div>
            <div class="stat-card__label">Marcaciones hasta esta hora</div>
            <div class="stat-card__sub">
                @if ($desvio === null)
                    Sin días comparables todavía
                @else
                    {{ $numero($comparacion['tipico']) }} en un
                    {{ mb_strtolower(now()->translatedFormat('l')) }} normal
                    ({{ $desvio > 0 ? '+' : '' }}{{ $desvio }} %)
                @endif
            </div>
        </div>
    </div>

    {{-- 2 · ¿Qué pasó hoy? --}}
    <h2 class="escritorio__seccion">Hoy</h2>
    <div class="escritorio__par">
        <div class="tarjeta">
            <h2>Marcaciones por hora</h2>
            @if (array_sum($histograma['totales']) === 0)
                <p class="vacio">Todavía no entró ninguna marcación hoy.</p>
            @else
                <div class="mini-chart mini-chart--horas">
                    @foreach ($histograma['totales'] as $hora => $total)
                        <div class="mini-chart__barra {{ $total === 0 ? 'mini-chart__barra--cero' : '' }} {{ $hora === $histograma['pico'] ? 'mini-chart__barra--pico' : '' }}"
                             style="height: {{ max(2, ($total / $maxHistograma) * 100) }}%"
                             title="{{ sprintf('%02d:00', $hora) }} · {{ $total }} marcación(es)"></div>
                    @endforeach
                </div>
                <div class="mini-chart__ejes">
                    <span>00:00</span>
                    @if ($histograma['pico'] !== null)
                        <span>pico {{ sprintf('%02d:00', $histograma['pico']) }}</span>
                    @endif
                    <span>23:00</span>
                </div>
            @endif
        </div>

        <div class="tarjeta">
            <h2>Resumen del día</h2>
            <dl class="datos-lista">
                <div>
                    <dt>Marcaciones</dt>
                    <dd>{{ $numero($hoy['marcaciones']) }}</dd>
                </div>
                <div>
                    <dt>Personas que marcaron</dt>
                    <dd>{{ $numero($hoy['personas']) }}</dd>
                </div>
                <div>
                    <dt>Con turno asignado hoy</dt>
                    <dd>{{ $numero($hoy['con_turno']) }}</dd>
                </div>
                <div class="{{ ($hoy['sin_marcar'] ?? 0) > 0 ? 'datos-lista__alerta' : '' }}">
                    <dt>Aún sin marcar</dt>
                    <dd>{{ $numero($hoy['sin_marcar']) }}</dd>
                </div>
                <div>
                    <dt>De licencia</dt>
                    <dd>{{ $numero($hoy['licenciados']) }}</dd>
                </div>
            </dl>
            @if ($hoy['con_turno'] === null)
                <p class="datos-lista__nota">
                    «Con turno» y «sin marcar» quedan en blanco hasta que se carguen
                    los turnos asignados.
                </p>
            @endif
        </div>
    </div>

    {{-- 3 · Contexto --}}
    <h2 class="escritorio__seccion">Contexto</h2>
    <div class="tarjeta escritorio__tendencia">
        <h2>Marcaciones por día (últimos 30 días)</h2>
        <div class="mini-chart">
            @foreach ($tendencia['totales'] as $indice => $total)
                <div class="mini-chart__barra {{ $total === 0 ? 'mini-chart__barra--cero' : '' }}"
                     style="height: {{ max(2, ($total / $maxTendencia) * 100) }}%"
                     title="{{ $tendencia['etiquetas'][$indice] }} · {{ $total }} marcación(es)"></div>
            @endforeach
        </div>
        <div class="mini-chart__ejes">
            <span>{{ $tendencia['dias'][0] }}</span>
            <span>{{ $tendencia['dias'][count($tendencia['dias']) - 1] }}</span>
        </div>
    </div>

    <div class="escritorio__par">
        {{-- Este panel cuenta la tabla entera de marcaciones (~2 s sobre 4,4
             millones de filas): se carga aparte para no demorar la portada. --}}
        <div class="tarjeta">
            <h2>Calidad de los datos</h2>
            <div id="panel-calidad">
                <p class="vacio">Contando marcaciones…</p>
            </div>
        </div>

        <div class="tarjeta">
            <h2>Agenda</h2>
            <dl class="datos-lista">
                <div>
                    <dt>Funcionarios registrados</dt>
                    <dd>{{ $numero($agenda['funcionarios']) }}</dd>
                </div>
                <div>
                    <dt>Licencias vigentes hoy</dt>
                    <dd>{{ $numero($agenda['licencias_hoy']) }}</dd>
                </div>
                <div>
                    <dt>Próximo día excepcional</dt>
                    <dd>
                        @if ($agenda['proximo_excepcional'])
                            {{ $agenda['proximo_excepcional']->fecha->format('d/m/Y') }}
                        @else
                            —
                        @endif
                    </dd>
                </div>
            </dl>
            @if ($agenda['proximo_excepcional'])
                <p class="datos-lista__nota">
                    {{ $agenda['proximo_excepcional']->motivoInasistencia }}
                </p>
            @endif
        </div>
    </div>

    <div class="tarjeta">
        <h2>Equipos fuera de línea</h2>
        <table>
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>IP</th>
                    <th>Ubicación</th>
                    <th>Última sincronización</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($equiposFueraDeLinea as $equipo)
                    <tr>
                        <td><a href="{{ route('equipos.edit', $equipo) }}"><strong>{{ $equipo->nombre }}</strong></a></td>
                        <td>{{ $equipo->ip }}</td>
                        <td>{{ $equipo->ubicacion ?? 'Sin ubicación' }}</td>
                        <td>{{ $equipo->ultima_sync?->format('d/m/Y H:i') ?? 'Nunca' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="vacio">Todos los equipos están en línea.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <script>
        (function () {
            const destino = document.getElementById('panel-calidad');

            fetch(@json(route('dashboard.calidad')), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            })
                .then((resp) => resp.ok ? resp.text() : Promise.reject(resp.status))
                .then((html) => { destino.innerHTML = html; })
                .catch(() => {
                    destino.innerHTML = '<p class="vacio">No se pudo contar las marcaciones.</p>';
                });
        })();
    </script>
@endsection
