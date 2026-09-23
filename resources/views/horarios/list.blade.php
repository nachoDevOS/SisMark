<div class="card">
    <table>
        <thead>
            <tr>
                <th>Día</th>
                <th>Turno</th>
                <th>Entrada</th>
                <th>Salida</th>
                <th>Tol. entrada</th>
                <th>Tol. salida</th>
                <th>Horas</th>
                <th>Día sig.</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($horarios as $horario)
                <tr>
                    <td><strong>{{ $horario->nombre_dia }}</strong></td>
                    <td>
                        {{ trim($horario->nombreTurno) }}
                        {{-- El horario que se ofrece por defecto al dar de alta un
                             contrato en Mamoré. Son varias filas, una por día. --}}
                        @if ($horario->sugerido)
                            <span class="pill pill--info" title="Se ofrece por defecto al dar de alta un contrato">Sugerido</span>
                        @endif
                    </td>
                    <td>{{ $horario->hEntrada?->format('H:i') }}</td>
                    <td>{{ $horario->hSalida?->format('H:i') }}</td>
                    <td>{{ $horario->hTolerancia?->format('H:i') }}</td>
                    <td>{{ $horario->sTolerancia?->format('H:i') }}</td>
                    <td>{{ number_format((float) $horario->hTrabajadas, 2) }}</td>
                    <td>
                        <span class="pill {{ $horario->siguienteDia ? 'pill--advertencia' : 'pill--no' }}">
                            {{ $horario->siguienteDia ? 'Sí' : 'No' }}
                        </span>
                    </td>
                    <td>
                        <div class="acciones">
                            @can('view', $horario)
                                <a href="{{ route('horarios.show', $horario) }}" class="btn-icon btn-icon--gris" title="Ver" aria-label="Ver"><x-heroicon-o-eye /></a>
                            @endcan
                            @can('update', $horario)
                                <a href="{{ route('horarios.edit', $horario) }}" class="btn-icon" title="Editar" aria-label="Editar"><x-heroicon-o-pencil-square /></a>
                            @endcan
                            @can('delete', $horario)
                                <x-boton-eliminar :accion="route('horarios.destroy', $horario)"
                                                  :mensaje="'Se elimina el turno «'.trim($horario->nombreTurno).'».'" />
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" class="vacio">Aún no hay turnos registrados.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="paginacion">
    {{ $horarios->links() }}
</div>
