@props([
    'ci',
    'origen' => '',
    'etiqueta' => 'Asignar turno',
])

@php
    $ciFijo = trim((string) $ci);
    $sufijo = 'ci'.preg_replace('/\W/', '', $ciFijo);
    // Varios formularios de la ficha comparten nombres de campo (`desde`,
    // `hasta`…): el marcador dice cuál rebotó, para reabrir solo ese modal.
    $abierto = old('_form') === 'turno-asignado' ? 'true' : 'false';
    $turnos = \App\Models\Turno::query()->with('horarios')->orderByDesc('sugerido')->orderBy('nombre')->get();
@endphp

{{-- Asignarle un turno a un funcionario ya conocido, sin salir de su ficha. El
     formulario de `turnos-asignados/create` sigue existiendo para cuando se
     entra por el listado y hay que elegir a la persona. --}}
@can('create', \App\Models\AsignacionTurno::class)
    <div x-data="{ abierto: {{ $abierto }} }" {{ $attributes }}>
        <button type="button" class="btn" x-on:click="abierto = true">
            <x-heroicon-o-plus />{{ $etiqueta }}
        </button>

        <div class="modal-fondo" x-show="abierto" x-cloak
             x-on:click.self="abierto = false" x-on:keydown.escape.window="abierto = false">
            <div class="modal-caja modal-caja--ancha modal-caja--horarios">
                <h2>Asignar turno a CI {{ $ciFijo }}</h2>

                <form method="POST" action="{{ route('turnos-asignados.store') }}">
                    @csrf
                    <input type="hidden" name="_form" value="turno-asignado">
                    <input type="hidden" name="ci" value="{{ $ciFijo }}">
                    <input type="hidden" name="origen" value="{{ $origen }}">
                    @error('ci') <div class="error">{{ $message }}</div> @enderror

                    @include('turnos-asignados._selector-turno', ['turnos' => $turnos, 'sufijo' => $sufijo])

                    <div class="grid-2">
                        <div class="campo">
                            <label for="turno-desde-{{ $sufijo }}">Fecha de inicio <span class="req">*</span></label>
                            <input type="date" id="turno-desde-{{ $sufijo }}" name="desde"
                                   value="{{ old('desde', now()->toDateString()) }}" required>
                            @error('desde') <div class="error">{{ $message }}</div> @enderror
                        </div>
                        <div class="campo">
                            <label for="turno-hasta-{{ $sufijo }}">Fecha de fin <span class="req">*</span></label>
                            <input type="date" id="turno-hasta-{{ $sufijo }}" name="hasta"
                                   value="{{ old('hasta', now()->endOfYear()->toDateString()) }}" required>
                            @error('hasta') <div class="error">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <p class="ayuda" style="margin-top: 0;">
                        Si el funcionario tiene horarios del sistema anterior que siguen vigentes, terminan
                        el día anterior al inicio del turno.
                    </p>

                    <div class="campo">
                        <label for="turno-obs-{{ $sufijo }}">Observación</label>
                        <input type="text" id="turno-obs-{{ $sufijo }}" name="observacion" maxlength="1000"
                               value="{{ old('observacion') }}">
                        @error('observacion') <div class="error">{{ $message }}</div> @enderror
                    </div>

                    <div class="modal-acciones">
                        <button type="button" class="btn btn--gris" x-on:click="abierto = false">
                            <x-heroicon-o-x-mark />Cancelar
                        </button>
                        <button type="submit" class="btn"><x-heroicon-o-check />Asignar turno</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endcan
