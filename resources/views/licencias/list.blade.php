<div class="card">
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Fecha</th>
                <th>Funcionario</th>
                <th>Turno</th>
                <th>Alcance</th>
                <th>Haberes</th>
                <th>Motivo</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($licencias as $licencia)
                <tr>
                    <td>{{ $licencia->id }}</td>
                    <td><strong>{{ $licencia->fecha?->format('d/m/Y') }}</strong></td>
                    <td>
                        @php($ficha = $fichas[trim((string) $licencia->ci)] ?? null)
                        <div class="persona-celda">
                            {{-- La foto solo la tiene Mamoré; con el respaldo local
                                 (o sin ficha) queda el ícono genérico. --}}
                            <x-persona-avatar :thumb="$ficha['imageThumb'] ?? null"
                                              :full="$ficha['image'] ?? null"
                                              :nombre="$ficha['nombre'] ?? ''" />
                            <div>
                                @if ($ficha)
                                    {{ $ficha['nombre'] }}
                                @else
                                    <span style="color: var(--muted); font-style: italic;">Sin persona</span>
                                @endif
                                <div class="ayuda">
                                    CI {{ trim((string) $licencia->ci) }}
                                    @if (!empty($ficha['cargo']))
                                        · {{ $ficha['cargo'] }}
                                    @endif
                                </div>
                            </div>
                        </div>
                    </td>
                    <td>{{ $licencia->resumen_turno }}</td>
                    <td>
                        @if ($licencia->tCompleto)
                            <span class="pill pill--info">Turno completo</span>
                        @else
                            {{ $licencia->lEntra?->format('H:i') ?? '—' }} – {{ $licencia->lSale?->format('H:i') ?? '—' }}
                        @endif
                    </td>
                    <td>
                        <span class="pill {{ $licencia->goceHaberes ? 'pill--ok' : 'pill--no' }}">
                            {{ $licencia->goceHaberes ? 'Con goce' : 'Sin goce' }}
                        </span>
                    </td>
                    <td>{{ $licencia->motivo ?: '—' }}</td>
                    <td class="acciones">
                        <x-boton-eliminar :accion="route('licencias.destroy', $licencia)"
                                          :mensaje="'Se elimina la licencia del '.$licencia->fecha?->format('d/m/Y').'.'" />
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="vacio">{{ $busqueda !== '' ? 'Sin licencias para la búsqueda.' : 'Aún no hay licencias registradas.' }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="paginacion">{{ $licencias->links() }}</div>
