@php
    // Mismos tonos que la ficha y que el perfil del funcionario en Mamoré.
    $colorEstado = [
        \App\Models\Licencia::APROBADO => 'pill--ok',
        \App\Models\Licencia::PENDIENTE => 'pill--advertencia',
        \App\Models\Licencia::RECHAZADO => 'pill--no',
    ];
@endphp

{{-- Una fila por solicitud, no por día: el alta expande el rango a una fila por
     día y horario, y sin agrupar «14 al 15 de agosto» se ve como dos licencias
     distintas. El desglose día por día está en la ficha. --}}
{{-- Datos para resolver en lote: cuántas pendientes abarca el filtro entero y
     con qué filtro se armó esta tabla. Los lee la barra de la pantalla
     (`licencias.index`) cada vez que la tabla se recarga. --}}
@if ($lote)
    <div id="lote-datos" hidden
         data-solicitudes="{{ $lote['solicitudes'] }}"
         data-dias="{{ $lote['dias'] }}"
         data-filtros="{{ json_encode($lote['filtros']) }}"></div>
@endif

<div class="card">
    <table>
        <thead>
            <tr>
                @if ($lote)
                    <th class="lote-celda">
                        <input type="checkbox" class="lote-pagina" aria-label="Marcar las pendientes de esta página"
                               title="Marcar las pendientes de esta página">
                    </th>
                @endif
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
                    // Para el detalle desplegable. Lo migrado del SIA trae muchas
                    // veces el pedido sin hora: ahí va solo la fecha, no un «00:00».
                    $horario = $licencia->horario;
                    $pedido = $licencia->fechaPedido ?? $licencia->created_at;
                    $registradoPor = $licencia->registrador?->name
                        ?: ($licencia->usuario ?: ($licencia->origen === \App\Models\Licencia::ORIGEN_MAMORE ? 'El funcionario, desde Mamoré' : null));
                @endphp
                <tr>
                    @if ($lote)
                        <td class="lote-celda">
                            {{-- Solo lo pendiente se resuelve en lote: lo demás ya
                                 tiene su decisión. --}}
                            @if ($licencia->esPendiente)
                                {{-- Lo que el modal de confirmación muestra de cada
                                     una, así se revisa sin volver a la tabla. --}}
                                <input type="checkbox" class="lote-check" value="{{ $licencia->id }}"
                                       data-dias="{{ $dias }}"
                                       data-nombre="{{ ($fichas[trim((string) $licencia->ci)]['nombre'] ?? null) ?: 'Sin persona' }}"
                                       data-ci="{{ trim((string) $licencia->ci) }}"
                                       data-periodo="{{ $desde?->format('d/m/Y') }}{{ $unSoloDia ? '' : ' al '.$hasta?->format('d/m/Y') }}"
                                       data-alcance="{{ $licencia->tCompleto ? 'Horario completo' : ($licencia->lEntra?->format('H:i') ?? '—').' – '.($licencia->lSale?->format('H:i') ?? '—') }}"
                                       data-completo="{{ $licencia->tCompleto ? 1 : 0 }}"
                                       data-goce="{{ $licencia->goceHaberes ? 1 : 0 }}"
                                       data-tipo="{{ $licencia->tipo_etiqueta }}"
                                       data-tipo-pill="{{ $licencia->tipo_pill }}"
                                       data-personal="{{ $licencia->tipo === \App\Models\Licencia::TIPO_PERSONAL ? 1 : 0 }}"
                                       data-motivo="{{ $licencia->motivo }}"
                                       aria-label="Marcar esta solicitud">
                            @endif
                        </td>
                    @endif
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
                        {{-- Días con horario dentro del rango, que no son los días
                             corridos: un pedido de viernes a lunes licencia dos. --}}
                        {{ $dias }}
                    </td>
                    <td>
                        @if ($licencia->tCompleto)
                            <span class="pill pill--info">Horario completo</span>
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
                        {{-- Despliega la fila de detalle que va debajo. Con un
                             manejador en línea porque la tabla llega por fetch e
                             `innerHTML`, que no ejecuta scripts. --}}
                        <button type="button" class="btn-icon btn-icon--gris licencia-desplegar"
                                title="Ver más información" aria-label="Ver más información" aria-expanded="false"
                                onclick="const detalle = this.closest('tr').nextElementSibling;
                                         detalle.hidden = ! detalle.hidden;
                                         this.setAttribute('aria-expanded', String(! detalle.hidden));
                                         this.querySelector('svg').style.transform = detalle.hidden ? '' : 'rotate(180deg)';">
                            <x-heroicon-o-chevron-down style="transition: transform .15s;" />
                        </button>
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
                {{-- Detalle de la solicitud: se despliega con el botón de la fila. --}}
                <tr class="licencia-detalle" hidden>
                    <td colspan="{{ $lote ? 11 : 10 }}" style="background: var(--bg);">
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr)); gap: 1rem; padding: .25rem .5rem;">
                            <div>
                                <h4 style="margin: 0 0 .4rem;">Registro</h4>
                                <dl class="datos" style="margin: 0;">
                                    <div><dt>Registrado por</dt><dd>{{ $registradoPor ?: '—' }}</dd></div>
                                    <div><dt>Fecha y hora del registro</dt>
                                        <dd>{{ $pedido ? $pedido->format($pedido->format('H:i:s') === '00:00:00' ? 'd/m/Y' : 'd/m/Y H:i') : '—' }}</dd></div>
                                    <div><dt>Origen</dt><dd>{{ $licencia->origen_etiqueta }}</dd></div>                                </dl>
                            </div>
                            <div>
                                <h4 style="margin: 0 0 .4rem;">Licencia</h4>
                                <dl class="datos" style="margin: 0;">
                                    <div><dt>Período</dt>
                                        <dd>{{ $desde?->format('d/m/Y') }}{{ $unSoloDia ? '' : ' al '.$hasta?->format('d/m/Y') }} · {{ $dias }} día(s)</dd></div>
                                    <div><dt>Horario licenciado</dt>
                                        <dd>
                                            @if ($horario)
                                                {{ $horario->nombreHorario }} ({{ $horario->hEntrada?->format('H:i') ?? '—' }} – {{ $horario->hSalida?->format('H:i') ?? '—' }})
                                            @else
                                                —
                                            @endif
                                        </dd></div>
                                    <div><dt>Alcance</dt>
                                        <dd>{{ $licencia->tCompleto ? 'Horario completo' : 'De '.($licencia->lEntra?->format('H:i') ?? '—').' a '.($licencia->lSale?->format('H:i') ?? '—') }}</dd></div>
                                    <div><dt>Haberes</dt><dd>{{ $licencia->goceHaberes ? 'Con goce de haberes' : 'Sin goce de haberes' }}</dd></div>
                                    <div><dt>Tipo</dt><dd>{{ $licencia->tipo_etiqueta }}</dd></div>
                                    <div><dt>Motivo</dt><dd>{{ $licencia->motivo ?: '—' }}</dd></div>
                                    <div><dt>Respaldo</dt>
                                        <dd>
                                            @if ($licencia->adjunto)
                                                <a href="{{ route('licencias.respaldo', $licencia) }}" target="_blank" rel="noopener">{{ $licencia->adjuntoNombre ?: 'Ver respaldo' }}</a>
                                            @else
                                                Sin respaldo
                                            @endif
                                        </dd></div>
                                </dl>
                            </div>
                            <div>
                                <h4 style="margin: 0 0 .4rem;">Revisión</h4>
                                <dl class="datos" style="margin: 0;">
                                    <div><dt>Estado</dt><dd>{{ ($datos->estados ?? 1) > 1 ? 'Mixto (días resueltos de distinta manera)' : ($licencia->estado ?: '—') }}</dd></div>
                                    <div><dt>Revisado por</dt><dd>{{ $licencia->revisor?->name ?? ($licencia->esPendiente ? 'Todavía sin revisar' : '—') }}</dd></div>
                                    <div><dt>Fecha y hora de la revisión</dt><dd>{{ $licencia->revisadoEn?->format('d/m/Y H:i') ?? '—' }}</dd></div>
                                    <div><dt>Observación</dt><dd>{{ $licencia->observacion ?: '—' }}</dd></div>
                                </dl>
                            </div>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="{{ $lote ? 11 : 10 }}" class="vacio">{{ $busqueda !== '' ? 'Sin licencias para la búsqueda.' : 'Aún no hay licencias registradas.' }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="paginacion">{{ $licencias->links() }}</div>
