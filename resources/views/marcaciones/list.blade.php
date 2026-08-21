@php
    use App\Models\Asistencia;

    $pillPorTipo = [
        Asistencia::TIPO_RELOJ => 'pill--ok',
        Asistencia::TIPO_MANUAL => 'pill--advertencia',
    ];
@endphp
<div class="card">
    <table>
        <thead>
            <tr>
                <th>ID</th>
                {{-- El CI va adentro de «Funcionario», bajo el nombre, igual que
                     en el listado de funcionarios: son el mismo dato —quién es—
                     y separados obligaban a leer dos columnas para identificar
                     a una persona. --}}
                <th>Funcionario</th>
                <th>Fecha</th>
                <th>Hora</th>
                <th>Origen</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($marcaciones as $marcacion)
                <tr>
                    <td>{{ $marcacion->id }}</td>
                    <td>
                        @php($ficha = $fichas[trim((string) $marcacion->ci)] ?? null)
                        @if ($ficha)
                            {{-- Mismo patrón que el listado de funcionarios. La foto
                                 viaja en la misma ficha cacheada que ya trae el
                                 nombre y el cargo, así que no cuesta una consulta
                                 más: si Mamoré no la tiene, queda el ícono. --}}
                            <div class="persona-celda">
                                <x-persona-avatar :thumb="$ficha['imageThumb'] ?? null"
                                                  :full="$ficha['image'] ?? null"
                                                  :nombre="$ficha['nombre'] ?? ''" />
                                <div>
                                    <div class="persona-nombre">{{ $ficha['nombre'] }}</div>
                                    <div class="persona-meta">
                                        {{ trim((string) $marcacion->ci) }}
                                        @if (!empty($ficha['cargo']))
                                            <br>{{ $ficha['cargo'] }}
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @else
                            {{-- Sin ficha igual hay que poder identificar la marca:
                                 el carnet es lo único que se tiene. --}}
                            <div class="persona-celda">
                                <x-persona-avatar />
                                <div>
                                    <div class="persona-nombre" style="color: var(--muted); font-style: italic;">Sin persona</div>
                                    <div class="persona-meta">{{ trim((string) $marcacion->ci) }}</div>
                                </div>
                            </div>
                        @endif
                    </td>
                    <td>{{ $marcacion->fecha?->format('d/m/Y') }}</td>
                    <td>{{ $marcacion->hora?->format('H:i:s') }}</td>
                    @php($tipoMarcacion = trim((string) $marcacion->tipo))
                    <td style="white-space: nowrap;">
                        <span class="pill {{ $pillPorTipo[$tipoMarcacion] ?? 'pill--info' }}">{{ $tipoMarcacion }}</span>
                        <span class="ayuda">{{ Asistencia::TIPOS[$tipoMarcacion] ?? '—' }}</span>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="vacio">Sin marcaciones en el rango seleccionado.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="paginacion">{{ $marcaciones->links() }}</div>
