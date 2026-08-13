@props([
    'ci',
    'origen' => '',
    'etiqueta' => 'Registrar licencia',
])

@php
    $ciFijo = trim((string) $ci);
    $sufijo = 'ci'.preg_replace('/\W/', '', $ciFijo);
    // Si la validación rebotó (o no había turnos en el rango), el modal se
    // reabre con lo que se había cargado. El marcador `_form` distingue cuál de
    // los formularios de la ficha fue, porque comparten nombres de campo.
    $abierto = old('_form') === 'licencia' ? 'true' : 'false';
    $verTurnos = auth()->user()?->can('viewAny', \App\Models\AsignacionTurno::class) ?? false;
    // `acciones=0`: acá los turnos se muestran solo como referencia, sin los
    // botones de concluir ni eliminar de la solapa de la ficha.
    $urlTurnos = route('funcionarios.turnos.list', ['ci' => $ciFijo, 'situacion' => 'vigentes', 'por_pagina' => 100, 'acciones' => 0]);
@endphp

{{-- Alta de licencia para **un** funcionario ya conocido: el mismo alcance
     «uno» de la pantalla «Licenciar», pero sin el combo ni los modos de varios
     o todos, y con sus turnos vigentes a la vista. La pantalla general sigue
     existiendo para los feriados y las altas en lote. --}}
@can('create', \App\Models\Licencia::class)
    <div x-data="{
             abierto: {{ $abierto }},
             cargado: false,
             completo: {{ old('tCompleto', 1) ? 'true' : 'false' }},
             async abrir() {
                 this.abierto = true;
                 if (this.cargado) { return; }
                 this.cargado = true;
                 await this.cargarTurnos(@js($urlTurnos));
             },
             async cargarTurnos(url) {
                 if (! this.$refs.turnos) { return; }
                 this.$refs.turnos.innerHTML = `<div class='vacio'>Cargando…</div>`;
                 try {
                     const resp = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                     this.$refs.turnos.innerHTML = await resp.text();
                 } catch (e) {
                     this.$refs.turnos.innerHTML = `<div class='aviso aviso--error'>No se pudieron cargar los turnos vigentes.</div>`;
                 }
             },
         }"
         x-init="if (abierto) { abrir(); }" {{ $attributes }}>
        <button type="button" class="btn" x-on:click="abrir()">
            <x-heroicon-o-plus />{{ $etiqueta }}
        </button>

        <div class="modal-fondo" x-show="abierto" x-cloak
             x-on:click.self="abierto = false" x-on:keydown.escape.window="abierto = false">
            <div class="modal-caja" style="max-width: 46rem;">
                <h2>Licenciar a CI {{ $ciFijo }}</h2>

                @if ($verTurnos)
                    <p class="ayuda" style="margin-top: 0;">
                        Se anota una licencia por cada día del rango en que el funcionario
                        tenga turno. Estos son sus <strong>turnos vigentes</strong>:
                    </p>
                    <div x-ref="turnos" class="modal-licencia__turnos"
                         x-on:click="
                             const enlace = $event.target.closest('a.pag__link');
                             if (enlace) { $event.preventDefault(); cargarTurnos(enlace.href); }
                         ">
                        <div class="vacio">Cargando…</div>
                    </div>
                @endif

                {{-- `multipart/form-data`: el formulario sube el respaldo que
                     justifica la licencia. Sin esto el archivo no viaja. --}}
                <form method="POST" action="{{ route('licencias.store') }}" enctype="multipart/form-data">
                    @csrf
                    <input type="hidden" name="_form" value="licencia">
                    <input type="hidden" name="modo" value="uno">
                    <input type="hidden" name="ci" value="{{ $ciFijo }}">
                    <input type="hidden" name="origen" value="{{ $origen }}">
                    @error('ci') <div class="error">{{ $message }}</div> @enderror

                    <div class="grid-2">
                        <div class="campo">
                            <label for="lic-desde-{{ $sufijo }}">Desde <span class="req">*</span></label>
                            <input type="date" id="lic-desde-{{ $sufijo }}" name="desde"
                                   value="{{ old('desde', now()->toDateString()) }}" required>
                            @error('desde') <div class="error">{{ $message }}</div> @enderror
                        </div>
                        <div class="campo">
                            <label for="lic-hasta-{{ $sufijo }}">Hasta <span class="req">*</span></label>
                            <input type="date" id="lic-hasta-{{ $sufijo }}" name="hasta"
                                   value="{{ old('hasta', now()->toDateString()) }}" required>
                            @error('hasta') <div class="error">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div class="campo check">
                        <input type="checkbox" id="lic-completo-{{ $sufijo }}" name="tCompleto" value="1" x-model="completo">
                        <label for="lic-completo-{{ $sufijo }}" style="margin: 0;">Turno completo</label>
                    </div>

                    {{-- Solo para las salidas parciales: llegó tarde o se fue temprano. --}}
                    <div class="grid-2" x-show="! completo" x-cloak>
                        <div class="campo">
                            <label for="lic-entra-{{ $sufijo }}">Hora de entrada</label>
                            <input type="time" id="lic-entra-{{ $sufijo }}" name="lEntra"
                                   value="{{ old('lEntra') }}" :disabled="completo">
                            @error('lEntra') <div class="error">{{ $message }}</div> @enderror
                        </div>
                        <div class="campo">
                            <label for="lic-sale-{{ $sufijo }}">Hora de salida</label>
                            <input type="time" id="lic-sale-{{ $sufijo }}" name="lSale"
                                   value="{{ old('lSale') }}" :disabled="completo">
                            @error('lSale') <div class="error">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div class="campo check">
                        <input type="checkbox" id="lic-goce-{{ $sufijo }}" name="goceHaberes" value="1"
                               @checked(old('goceHaberes', 1))>
                        <label for="lic-goce-{{ $sufijo }}" style="margin: 0;">Con goce de haberes</label>
                    </div>

                    <div class="campo">
                        <label for="lic-motivo-{{ $sufijo }}">Motivo de la ausencia <span class="req">*</span></label>
                        <input type="text" id="lic-motivo-{{ $sufijo }}" name="motivo" maxlength="255"
                               value="{{ old('motivo') }}" required>
                        @error('motivo') <div class="error">{{ $message }}</div> @enderror
                    </div>

                    <div class="campo">
                        <label for="lic-respaldo-{{ $sufijo }}">Respaldo <span class="req">*</span></label>
                        <input type="file" id="lic-respaldo-{{ $sufijo }}" name="respaldo"
                               accept=".jpg,.jpeg,.png,.pdf" required>
                        <p class="ayuda">
                            Certificado médico, memorándum o nota que justifica la ausencia.
                            Imagen (JPG o PNG) o PDF, hasta 5 MB.
                        </p>
                        @error('respaldo') <div class="error">{{ $message }}</div> @enderror
                    </div>

                    <div class="modal-acciones">
                        <button type="button" class="btn btn--gris" x-on:click="abierto = false">
                            <x-heroicon-o-x-mark />Cancelar
                        </button>
                        <button type="submit" class="btn"><x-heroicon-o-check />Anotar licencia</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endcan
