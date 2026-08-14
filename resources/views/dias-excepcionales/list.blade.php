<div class="card">
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Fecha</th>
                <th>Motivo de inasistencia general</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($diasExcepcionales as $dia)
                <tr>
                    <td>{{ $dia->id }}</td>
                    <td><strong>{{ $dia->fecha?->format('d/m/Y') }}</strong></td>
                    <td>
                        {{ $dia->motivoInasistencia ?: '—' }}
                        @if ($dia->adjunto)
                            {{-- El enlace no apunta al bucket: pasa por el
                                 sistema, que comprueba el permiso y recién ahí
                                 firma una URL de vida corta. --}}
                            <a href="{{ route('dias-excepcionales.respaldo', $dia) }}" target="_blank" rel="noopener"
                               class="respaldo" title="{{ $dia->adjuntoNombre ?: 'Ver respaldo' }}">
                                <x-heroicon-o-paper-clip />Respaldo
                            </a>
                        @endif
                    </td>
                    <td class="acciones">
                        <a href="{{ route('dias-excepcionales.edit', $dia) }}" class="btn-icon" title="Editar" aria-label="Editar"><x-heroicon-o-pencil-square /></a>
                        <x-boton-eliminar :accion="route('dias-excepcionales.destroy', $dia)"
                                          :mensaje="'Se elimina el día excepcional del '.$dia->fecha?->format('d/m/Y').'.'" />
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="vacio">{{ $busqueda !== '' ? 'Sin días excepcionales para la búsqueda.' : 'Aún no hay días excepcionales registrados.' }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="paginacion">{{ $diasExcepcionales->links() }}</div>
