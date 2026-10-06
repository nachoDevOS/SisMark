<div class="card">
    <table>
        <thead>
            <tr>
                <th style="width: 2rem;"></th>
                <th>Turno</th>
                <th>Días</th>
                <th>Horarios</th>
                <th>Horas semanales</th>
                <th></th>
            </tr>
        </thead>
        {{-- Un tbody por turno: la fila del turno y, debajo, sus horarios, que se
             despliegan al hacer clic en la fila. --}}
        @forelse ($turnos as $turno)
            <tbody x-data="{ abierto: false }">
                <tr x-on:click="abierto = ! abierto" style="cursor: pointer;" :aria-expanded="abierto">
                    <td>
                        <span style="display: inline-flex; width: 1rem; transition: transform .15s;"
                              :style="abierto ? 'transform: rotate(90deg)' : ''"><x-heroicon-o-chevron-right /></span>
                    </td>
                    <td>
                        <strong>{{ $turno->nombre }}</strong>
                        @if ($turno->sugerido)
                            <span class="pill pill--info" title="Se ofrece en Mamoré para elegir al dar de alta un contrato">Sugerido</span>
                        @endif
                    </td>
                    <td>{{ $turno->dias_cubiertos ?: '—' }}</td>
                    <td>{{ $turno->horarios->count() }}</td>
                    <td>{{ number_format($turno->horas_semanales, 2) }}</td>
                    <td>
                        {{-- Los botones no despliegan la fila. --}}
                        <div class="acciones" x-on:click.stop>
                            @can('view', $turno)
                                <a href="{{ route('turnos.show', $turno) }}" class="btn-icon btn-icon--gris" title="Ver" aria-label="Ver"><x-heroicon-o-eye /></a>
                            @endcan
                            {{-- Lo único que se edita de un turno: si es sugerido o no. --}}
                            @can('update', $turno)
                                <form action="{{ route('turnos.sugerido', $turno) }}" method="POST" style="margin: 0; display: inline-flex;">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="btn-icon"
                                            title="{{ $turno->sugerido ? 'Quitar de sugeridos' : 'Marcar como sugerido' }}"
                                            aria-label="{{ $turno->sugerido ? 'Quitar de sugeridos' : 'Marcar como sugerido' }}">
                                        @if ($turno->sugerido)
                                            <x-heroicon-s-star />
                                        @else
                                            <x-heroicon-o-star />
                                        @endif
                                    </button>
                                </form>
                            @endcan
                            @can('delete', $turno)
                                <x-boton-eliminar :accion="route('turnos.destroy', $turno)"
                                                  :mensaje="'Se elimina el turno «'.$turno->nombre.'».'" />
                            @endcan
                        </div>
                    </td>
                </tr>
                <tr x-show="abierto" x-cloak>
                    <td></td>
                    <td colspan="5" style="padding-top: 0;">
                        @include('turnos._horarios', ['horarios' => $turno->horarios])
                    </td>
                </tr>
            </tbody>
        @empty
            <tbody>
                <tr>
                    <td colspan="6" class="vacio">Aún no hay turnos registrados.</td>
                </tr>
            </tbody>
        @endforelse
    </table>
</div>

<div class="paginacion">
    {{ $turnos->links() }}
</div>
