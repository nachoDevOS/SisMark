@php
    $colorEstado = [
        \App\Models\Licencia::APROBADO => 'pill--ok',
        \App\Models\Licencia::PENDIENTE => 'pill--advertencia',
        \App\Models\Licencia::RECHAZADO => 'pill--no',
    ];
@endphp

{{-- Una fila por solicitud, como el listado general: el alta expande el rango a
     una fila por día y turno, y sueltas un permiso de cinco días parecería cinco
     licencias. --}}
<div class="card">
    <table>
        <thead>
            <tr>
                <th>Periodo</th>
                <th>Días</th>
                <th>Alcance</th>
                <th>Haberes</th>
                <th>Motivo</th>
                <th>Origen</th>
                <th>Estado</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($licencias as $licencia)
                @php
                    // La fila es la que abre la solicitud, así que su `fecha` es
                    // el «desde»; el resto lo trae el resumen de la página.
                    $datos = $resumen[$licencia->solicitud] ?? null;
                    $desde = $licencia->fecha;
                    $hasta = $datos ? \Illuminate\Support\Carbon::parse($datos->hasta) : $desde;
                    $dias = $datos->dias ?? 1;
                    $unSoloDia = $dias <= 1;
                @endphp
                <tr>
                    <td>
                        {{-- Las dos fechas con el mismo peso: el «hasta» es tan
                             parte del permiso como el «desde», y en gris chico
                             se leía como una acotación. --}}
                        <div class="periodo">
                            <span>{{ $desde?->format('d/m/Y') }}</span>
                            @unless ($unSoloDia)
                                <span class="periodo__al">al</span>
                                <span>{{ $hasta?->format('d/m/Y') }}</span>
                            @endunless
                        </div>
                    </td>
                    <td>{{ $dias }}</td>
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
                    <td>
                        {{ $licencia->motivo ?: '—' }}
                        @if ($licencia->adjunto)
                            <a href="{{ route('licencias.respaldo', $licencia) }}" target="_blank" rel="noopener"
                               class="respaldo" title="Ver respaldo">
                                <x-heroicon-o-paper-clip />Respaldo
                            </a>
                        @endif
                    </td>
                    <td>
                        <span class="pill {{ $licencia->origen === \App\Models\Licencia::ORIGEN_MAMORE ? 'pill--info' : 'pill--neutro' }}">
                            {{ $licencia->origen_etiqueta }}
                        </span>
                    </td>
                    <td>
                        @if (($datos->estados ?? 1) > 1)
                            <span class="pill pill--info">Mixto</span>
                        @elseif ($licencia->estado)
                            <span class="pill {{ $colorEstado[$licencia->estado] ?? 'pill--info' }}">{{ $licencia->estado }}</span>
                        @else
                            <span class="ayuda">—</span>
                        @endif
                    </td>
                    <td class="acciones">
                        @can('view', $licencia)
                            <a href="{{ route('licencias.show', $licencia) }}" class="btn-icon btn-icon--gris"
                               title="Ver solicitud" aria-label="Ver solicitud"><x-heroicon-o-eye /></a>
                        @endcan
                        {{-- Lo pedido desde Mamoré y lo rechazado no se eliminan:
                             el criterio lo da `motivoParaNoEliminar()`. --}}
                        @can('delete', $licencia)
                            @if ($licencia->esEliminable)
                            {{-- El mensaje dice cuántos días se van: la baja
                                 alcanza a la solicitud entera, no a la fila. --}}
                            <x-boton-eliminar :accion="route('licencias.destroy', $licencia)"
                                              :ancla="'licencias'"
                                              :mensaje="$unSoloDia
                                                  ? 'Se elimina la licencia del '.$desde?->format('d/m/Y').'.'
                                                  : 'Se eliminan los '.$dias.' días de esta solicitud ('.$desde?->format('d/m/Y').' al '.$hasta?->format('d/m/Y').').'" />
                            @endif
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="vacio">El funcionario no tiene licencias registradas.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="paginacion">{{ $licencias->links() }}</div>
