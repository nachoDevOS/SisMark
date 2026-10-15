@if ($errorFuente)
    <div class="aviso aviso--error">{{ $errorFuente }}</div>
@endif

@php
    // Mamoré entrega el contrato firmado dentro de cada persona, así que el cargo
    // y la dirección se muestran siempre (vacíos en quien no tiene contrato).
    // SIA no conoce los contratos: ahí esas columnas no tienen sentido.
    $muestraContrato = $fuente === 'mamore';
    // Con «Sin turnos/horarios» se agrega hasta cuándo tuvo horario, para
    // distinguir a quien se le venció del que nunca tuvo.
    $muestraUltimoHorario = $horario === 'sin';
    $columnas = ($muestraContrato ? 7 : 5) + ($muestraUltimoHorario ? 1 : 0);
    // La etiqueta (tipo de contrato o «Sin contrato») sobra cuando la lista ya
    // está filtrada por eso mismo.
    $muestraEtiqueta = $muestraContrato && $contrato !== 'sin' && $tipo === null;
    // «Todos» solo se sabe cuando la API informó las dos situaciones.
    $totalTodos = is_null($totales['con']) || is_null($totales['sin'])
        ? null
        : $totales['con'] + $totales['sin'];
@endphp

{{-- Totales que el shell lee para etiquetar los selects de contrato y de turnos/horarios. --}}
<div id="totales-contrato" hidden
     data-todos="{{ $totalTodos }}"
     data-con="{{ $totales['con'] }}"
     data-sin="{{ $totales['sin'] }}"
     data-con-horario="{{ $totales['conHorario'] }}"
     data-sin-horario="{{ $totales['sinHorario'] }}"
     data-tipos="{{ json_encode($totales['tipos']) }}"></div>

<div class="card">
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Nombre completo</th>
                @if ($muestraContrato)
                    <th>Cargo</th>
                    <th>Dirección</th>
                @endif
                @if ($muestraUltimoHorario)
                    <th>Último horario</th>
                @endif
                <th>Fecha nac.</th>
                <th>PIN reloj</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($funcionarios as $persona)
                <tr>
                    <td>{{ $persona['id'] }}</td>
                    <td>
                        <div class="persona-celda">
                            {{-- La foto solo la tiene Mamoré; en SIA queda el ícono genérico. --}}
                            <x-persona-avatar :thumb="$persona['imageThumb'] ?? null"
                                              :full="$persona['image'] ?? null"
                                              :nombre="$persona['nombre'] ?? ''" />
                            <div>
                                <div class="persona-nombre">
                                    {{ $persona['nombre'] }}
                                    @if ($muestraEtiqueta && !is_null($persona['conContrato']))
                                        <span class="pill {{ $persona['conContrato'] ? 'pill--ok' : 'pill--no' }}">
                                            {{ $persona['conContrato'] ? ($persona['tipoContrato'] ?? 'Con contrato') : 'Sin contrato' }}
                                        </span>
                                    @endif
                                </div>
                                <div class="persona-meta">
                                    {{ $persona['ci'] }}
                                    @if (!empty($persona['profesion']))
                                        <br>{{ $persona['profesion'] }}
                                    @endif
                                </div>
                            </div>
                        </div>
                    </td>
                    @if ($muestraContrato)
                        <td>{{ $persona['cargo'] ?: '—' }}</td>
                        <td>{{ $persona['direccion'] ?: '—' }}</td>
                    @endif
                    @if ($muestraUltimoHorario)
                        <td>
                            @if ($persona['ultimoHorario'])
                                Venció el {{ $persona['ultimoHorario']->format('d/m/Y') }}
                            @else
                                <span class="persona-meta">Nunca tuvo</span>
                            @endif
                        </td>
                    @endif
                    <td>
                        {{ $persona['nacimiento'] ?: '—' }}
                        @if (!is_null($persona['edad']))
                            <div class="persona-meta">{{ $persona['edad'] }} años</div>
                        @endif
                    </td>
                    <td>{{ $persona['pinReloj'] ?: 'Sin PIN' }}</td>
                    <td class="acciones">
                        @if ($muestraUltimoHorario && $persona['ci'] !== '' && auth()->user()->can('create', \App\Models\AsignacionTurno::class))
                            <a href="{{ route('turnos-asignados.create', ['ci' => $persona['ci']]) }}" class="btn-icon" title="Asignar turno" aria-label="Asignar turno"><x-heroicon-o-calendar-days /></a>
                        @endif
                        @if ($persona['ver'] && auth()->user()->can('View:Persona'))
                            <a href="{{ $persona['ver'] }}" class="btn-icon btn-icon--gris" title="Ver" aria-label="Ver"><x-heroicon-o-eye /></a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="{{ $columnas }}" class="vacio">Sin funcionarios en el criterio buscado.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="paginacion">{{ $funcionarios->links() }}</div>
