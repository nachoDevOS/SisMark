<div class="card">
    <table>
        <thead>
            <tr>
                <th>Día</th>
                <th>Horario</th>
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
                    <td>{{ trim($horario->nombreHorario) }}</td>
                    <td>
                        @if ($horario->turno)
                            {{ $horario->turno->nombre }}
                        @else
                            <span class="pill pill--neutro">Sin turno</span>
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
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="10" class="vacio">Aún no hay horarios registrados.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="paginacion">
    {{ $horarios->links() }}
</div>
