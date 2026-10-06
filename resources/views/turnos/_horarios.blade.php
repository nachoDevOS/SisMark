{{-- Los horarios de un turno, en detalle. Recibe $horarios. --}}
<table style="font-size: .85rem;">
    <thead>
        <tr>
            <th>Día</th>
            <th>Entrada</th>
            <th>Salida</th>
            <th>Tolerancia</th>
            <th>Marca entrada</th>
            <th>Marca salida</th>
            <th>Horas</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($horarios as $horario)
            <tr>
                <td>
                    <strong>{{ $horario->nombre_dia }}</strong>
                    @if ($horario->trashed())
                        <span class="pill pill--advertencia" title="El horario se eliminó, pero el turno lo conserva">Eliminado</span>
                    @endif
                </td>
                <td><strong>{{ $horario->hEntrada?->format('H:i') }}</strong></td>
                <td>
                    <strong>{{ $horario->hSalida?->format('H:i') }}</strong>
                    @if ($horario->siguienteDia)
                        <span class="pill pill--advertencia" title="La salida es al día siguiente">+1 día</span>
                    @endif
                </td>
                <td>{{ $horario->hTolerancia?->format('H:i') }} / {{ $horario->sTolerancia?->format('H:i') }}</td>
                <td>{{ $horario->eMinima?->format('H:i') }} – {{ $horario->eMaxima?->format('H:i') }}</td>
                <td>{{ $horario->sMinima?->format('H:i') }} – {{ $horario->sMaxima?->format('H:i') }}</td>
                <td>{{ number_format((float) $horario->hTrabajadas, 2) }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="7" class="vacio">El turno no tiene horarios.</td>
            </tr>
        @endforelse
    </tbody>
</table>
