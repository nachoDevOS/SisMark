@php
    // Mismos tonos que la ficha y que el perfil del funcionario en Mamoré.
    $colorEstado = [
        \App\Models\Licencia::APROBADO => 'pill--ok',
        \App\Models\Licencia::PENDIENTE => 'pill--advertencia',
        \App\Models\Licencia::RECHAZADO => 'pill--no',
    ];
@endphp

{{-- Una fila por solicitud, no por día: el alta expande el rango a una fila por
     día y turno, y sin agrupar «14 al 15 de agosto» se ve como dos licencias
     distintas. El desglose día por día está en la ficha. --}}
<div class="card">
    <table>
        <thead>
            <tr>
                <th>Periodo</th>
                <th>Funcionario</th>
                <th>Días</th>
                <th>Alcance</th>
                <th>Haberes</th>
                <th>Motivo</th>
                <th>Tipo</th>
                <th>Origen</th>
                <th>Estado</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($licencias as $licencia)
                @php
                    // La fila es la que abre la solicitud, así que su `fecha` es
                    // el «desde». El resto lo trae el resumen de la página.
                    $datos = $resumen[$licencia->clave_agrupadora] ?? null;
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
                    <td>
                        {{-- Días con turno dentro del rango, que no son los días
                             corridos: un pedido de viernes a lunes licencia dos. --}}
                        {{ $dias }}
                    </td>
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
                            {{-- El enlace no apunta al bucket: pasa por el
                                 sistema, que comprueba el permiso y recién ahí
                                 firma una URL de vida corta. --}}
                            <a href="{{ route('licencias.respaldo', $licencia) }}" target="_blank" rel="noopener"
                               class="respaldo" title="Ver respaldo">
                                <x-heroicon-o-paper-clip />Respaldo
                            </a>
                        @endif
                    </td>
                    <td>
                        {{-- Qué es: permiso personal o licencia institucional. --}}
                        <span class="pill {{ $licencia->tipo_pill }}">{{ $licencia->tipo_etiqueta }}</span>
                    </td>
                    <td>
                        {{-- De dónde salió: lo pidió el funcionario desde Mamoré,
                             lo cargó Recursos Humanos acá, o vino de la copia del
                             sistema viejo. --}}
                        <span class="pill {{ $licencia->origen === \App\Models\Licencia::ORIGEN_MAMORE ? 'pill--info' : 'pill--neutro' }}">
                            {{ $licencia->origen_etiqueta }}
                        </span>
                    </td>
                    <td>
                        @if (($datos->estados ?? 1) > 1)
                            {{-- Días resueltos de distinta manera dentro del mismo
                                 pedido: se dice, en vez de mostrar uno de los
                                 estados como si fuera el de todos. --}}
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
                        {{-- La baja alcanza a la solicitud entera, que es lo que
                             muestra la fila. El mensaje dice cuántos días son
                             para que no se confunda con borrar uno solo.

                             Lo pedido desde Mamoré y lo rechazado no se
                             eliminan: se resuelven, o son la constancia de que
                             se resolvieron. El criterio lo da el modelo. --}}
                        @if ($licencia->esEliminable && auth()->user()->can('delete', $licencia))
                        <x-boton-eliminar :accion="route('licencias.destroy', $licencia)"
                                          :mensaje="$unSoloDia
                                              ? 'Se elimina la licencia del '.$desde?->format('d/m/Y').'.'
                                              : 'Se eliminan los '.$dias.' días de esta solicitud ('.$desde?->format('d/m/Y').' al '.$hasta?->format('d/m/Y').').'" />
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="10" class="vacio">{{ $busqueda !== '' ? 'Sin licencias para la búsqueda.' : 'Aún no hay licencias registradas.' }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="paginacion">{{ $licencias->links() }}</div>
