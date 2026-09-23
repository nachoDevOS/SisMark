@if ($errorFuente)
    <div class="aviso aviso--error">{{ $errorFuente }}</div>
@endif

@php
    // Mamoré entrega el contrato firmado dentro de cada persona, así que el cargo
    // y la dirección se muestran siempre (vacíos en quien no tiene contrato).
    // SIAT no conoce los contratos: ahí esas columnas no tienen sentido.
    $muestraContrato = $fuente === 'mamore';
    $columnas = $muestraContrato ? 7 : 5;
    // La etiqueta Con/Sin contrato solo aporta cuando la lista está sin filtrar.
    $muestraEtiqueta = $muestraContrato && $contrato === 'todos';
    // «Todos» solo se sabe cuando la API informó las dos situaciones.
    $totalTodos = is_null($totales['con']) || is_null($totales['sin'])
        ? null
        : $totales['con'] + $totales['sin'];
@endphp

{{-- Totales que el shell lee para etiquetar el select de situación de contrato. --}}
<div id="totales-contrato" hidden
     data-todos="{{ $totalTodos }}"
     data-con="{{ $totales['con'] }}"
     data-sin="{{ $totales['sin'] }}"></div>

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
                            {{-- La foto solo la tiene Mamoré; en SIAT queda el ícono genérico. --}}
                            <x-persona-avatar :thumb="$persona['imageThumb'] ?? null"
                                              :full="$persona['image'] ?? null"
                                              :nombre="$persona['nombre'] ?? ''" />
                            <div>
                                <div class="persona-nombre">
                                    {{ $persona['nombre'] }}
                                    @if ($muestraEtiqueta && !is_null($persona['conContrato']))
                                        <span class="pill {{ $persona['conContrato'] ? 'pill--ok' : 'pill--no' }}">
                                            {{ $persona['conContrato'] ? 'Con contrato' : 'Sin contrato' }}
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
                    <td>
                        {{ $persona['nacimiento'] ?: '—' }}
                        @if (!is_null($persona['edad']))
                            <div class="persona-meta">{{ $persona['edad'] }} años</div>
                        @endif
                    </td>
                    <td>{{ $persona['pinReloj'] ?: 'Sin PIN' }}</td>
                    <td class="acciones">
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
