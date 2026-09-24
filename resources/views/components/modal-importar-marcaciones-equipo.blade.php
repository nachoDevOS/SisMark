@php
    // Si la validación rebotó, el modal se reabre solo con lo que se había
    // escrito, para que el usuario vea el error donde lo cargó.
    $abierto = $errors->hasAny(['archivo', 'motivo', 'consentimiento']) ? 'true' : 'false';
@endphp

{{-- Importación del CSV de marcaciones, la única que hay: se ofrece en
     Biométricos y en Marcaciones. Sin equipo, porque el CSV no dice de qué
     reloj salió. Queda en la bitácora: se escribe por qué se sube y se declara
     que las marcaciones son auténticas. --}}
@can('import', \App\Models\Equipo::class)
    <div x-data="{ abierto: {{ $abierto }}, archivo: '', motivo: @js(old('motivo', '')), consiente: {{ old('consentimiento') ? 'true' : 'false' }} }" {{ $attributes }}>
        <button type="button" class="btn btn--gris" x-on:click="abierto = true">
            <x-heroicon-o-arrow-up-tray />Importar CSV
        </button>

        <div class="modal-fondo" x-show="abierto" x-cloak
             x-on:click.self="abierto = false" x-on:keydown.escape.window="abierto = false">
            <div class="modal-caja modal-caja--ancha">
                <h2>Importar marcaciones</h2>

                <form method="POST" action="{{ route('equipos.marcaciones.importar') }}" enctype="multipart/form-data">
                    @csrf

                    <p class="ayuda" style="margin: 0 0 .9rem;">
                        Para lo que no se pudo sincronizar: un reloj sin red cuyo historial se bajó
                        por USB, o un respaldo guardado antes de vaciarlo. La carga queda en la
                        <strong>bitácora</strong> a tu nombre, con el motivo y el nombre del archivo.
                    </p>

                    <div class="campo">
                        <label for="importar-archivo">Archivo CSV <span class="req">*</span></label>
                        <input type="file" id="importar-archivo" name="archivo" accept=".csv,text/csv" required
                               x-on:change="archivo = $event.target.files[0]?.name ?? ''">
                        <div class="ayuda">
                            Mismo formato que baja «Descargar y sincronizar»: <code>CI/ID,Nombre,Fecha,Hora</code>.
                        </div>
                        @error('archivo') <div class="error">{{ $message }}</div> @enderror
                    </div>

                    <div class="campo">
                        <label for="importar-motivo">¿Por qué se suben? <span class="req">*</span></label>
                        <textarea id="importar-motivo" name="motivo" rows="2" maxlength="500" required x-model="motivo"
                                  placeholder="Ej.: el reloj estuvo sin red del 3 al 5, se bajó el historial por USB"></textarea>
                        <div class="ayuda" x-show="motivo.trim().length < 15">
                            Mínimo 15 caracteres (<span x-text="motivo.trim().length"></span>/15).
                        </div>
                        @error('motivo') <div class="error">{{ $message }}</div> @enderror
                    </div>

                    <div class="campo">
                        <label style="display: flex; gap: .5rem; align-items: flex-start; font-weight: normal;">
                            <input type="checkbox" name="consentimiento" value="1" x-model="consiente" style="margin-top: .2rem;">
                            <span>
                                Declaro que las marcaciones del archivo son auténticas y que no las
                                modifiqué, y acepto que esta importación quede registrada a mi nombre
                                en la bitácora.
                            </span>
                        </label>
                        @error('consentimiento') <div class="error">{{ $message }}</div> @enderror
                    </div>

                    <div class="modal-acciones">
                        <button type="button" class="btn btn--gris" x-on:click="abierto = false">
                            <x-heroicon-o-x-mark />Cancelar
                        </button>
                        <button type="submit" class="btn"
                                :disabled="! archivo || motivo.trim().length < 15 || ! consiente">
                            <x-heroicon-o-arrow-up-tray />Importar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endcan
