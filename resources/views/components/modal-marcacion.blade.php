@props([
    'ci' => null,
    'origen' => '',
    'etiqueta' => 'Nueva marcación',
])

@php
    // Con `ci` el funcionario ya viene dado (se abre desde su ficha) y el campo
    // no se muestra. Los ids se sufijan para que dos modales en la misma
    // pantalla no compartan el `for` de sus etiquetas.
    $ciFijo = trim((string) $ci);
    $sufijo = $ciFijo === '' ? 'global' : 'ci'.preg_replace('/\W/', '', $ciFijo);
    // Si la validación rebotó, el modal se reabre con los datos cargados. El
    // marcador `_form` distingue cuál de los formularios de la pantalla fue,
    // porque comparten nombres de campo (`ci`, `fecha`…).
    $abierto = old('_form') === 'marcacion' ? 'true' : 'false';
@endphp

{{-- Alta manual de una marcación (tipo M) sobre la base local. Vive en un
     componente porque se usa en dos lados: la pantalla de Marcaciones y la
     solapa de marcaciones de la ficha del funcionario. --}}
@can('create', \App\Models\Asistencia::class)
    <div x-data="{ abierto: {{ $abierto }} }" {{ $attributes }}>
        <button type="button" class="btn" x-on:click="abierto = true">
            <x-heroicon-o-plus />{{ $etiqueta }}
        </button>

        <div class="modal-fondo" x-show="abierto" x-cloak
             x-on:click.self="abierto = false" x-on:keydown.escape.window="abierto = false">
            <div class="modal-caja">
                <h2>Nueva marcación manual</h2>
                <form method="POST" action="{{ route('marcaciones.store') }}">
                    @csrf
                    <input type="hidden" name="_form" value="marcacion">
                    <input type="hidden" name="origen" value="{{ $origen }}">

                    @if ($ciFijo !== '')
                        <input type="hidden" name="ci" value="{{ $ciFijo }}">
                        <dl class="datos" style="margin-top: 0;">
                            <dt>Funcionario</dt>
                            <dd>CI {{ $ciFijo }}</dd>
                        </dl>
                        @error('ci') <div class="error">{{ $message }}</div> @enderror
                    @else
                        <div class="campo">
                            <label for="ci-{{ $sufijo }}">CI del funcionario <span class="req">*</span></label>
                            <input type="text" id="ci-{{ $sufijo }}" name="ci" value="{{ old('ci') }}" required>
                            @error('ci') <div class="error">{{ $message }}</div> @enderror
                        </div>
                    @endif

                    <div class="grid-2">
                        <div class="campo">
                            <label for="fecha-{{ $sufijo }}">Fecha <span class="req">*</span></label>
                            <input type="date" id="fecha-{{ $sufijo }}" name="fecha"
                                   value="{{ old('fecha', now()->toDateString()) }}" required>
                            @error('fecha') <div class="error">{{ $message }}</div> @enderror
                        </div>
                        <div class="campo">
                            <label for="hora-{{ $sufijo }}">Hora <span class="req">*</span></label>
                            <input type="time" id="hora-{{ $sufijo }}" name="hora" step="1"
                                   value="{{ old('hora') }}" required>
                            @error('hora') <div class="error">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    {{-- Obligatoria: es la única marcación que no viene del reloj, así que
                         el motivo tiene que quedar escrito junto a la fila. --}}
                    <div class="campo">
                        <label for="observacion-{{ $sufijo }}">Motivo <span class="req">*</span></label>
                        <textarea id="observacion-{{ $sufijo }}" name="observacion" rows="2" required
                                  maxlength="500" placeholder="Por qué se carga a mano: papeleta, equipo caído, olvido del funcionario…"
                        >{{ old('observacion') }}</textarea>
                        @error('observacion') <div class="error">{{ $message }}</div> @enderror
                    </div>

                    <p class="ayuda">Se registra como tipo <strong>M</strong> (manual).</p>

                    <div class="modal-acciones">
                        <button type="button" class="btn btn--gris" x-on:click="abierto = false">
                            <x-heroicon-o-x-mark />Cancelar
                        </button>
                        <button type="submit" class="btn"><x-heroicon-o-check />Registrar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endcan
