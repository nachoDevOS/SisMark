@php
    // Si la validación rebotó (archivo que no es CSV, pesa de más o falta), el
    // modal se reabre solo para que el usuario vea el error donde lo cargó.
    $abierto = $errors->has('archivo') ? 'true' : 'false';
@endphp

{{-- Importación del CSV de marcaciones. Va en un modal y no como campo suelto
     en la cabecera: el formato del archivo tiene reglas (columnas, formatos de
     fecha, qué se saltea) que hay que poder leer antes de elegir el archivo. --}}
@can('create', \App\Models\Asistencia::class)
    <div x-data="{ abierto: {{ $abierto }}, archivo: '' }" {{ $attributes }}>
        <button type="button" class="btn" x-on:click="abierto = true">
            <x-heroicon-o-arrow-up-tray />Importar CSV
        </button>

        <div class="modal-fondo" x-show="abierto" x-cloak
             x-on:click.self="abierto = false" x-on:keydown.escape.window="abierto = false">
            <div class="modal-caja modal-caja--ancha">
                <h2>Importar marcaciones desde un CSV</h2>

                <form method="POST" action="{{ route('marcaciones.importar') }}" enctype="multipart/form-data">
                    @csrf

                    <p class="ayuda" style="margin: 0 0 .9rem;">
                        Es el mismo archivo que genera
                        <strong>Biométricos → Ver marcaciones → Descargar CSV</strong>.
                        También sirve uno reguardado desde Excel.
                    </p>

                    <div class="campo">
                        <label for="archivo-csv">Archivo CSV <span class="req">*</span></label>
                        <input type="file" id="archivo-csv" name="archivo" accept=".csv,text/csv" required
                               x-on:change="archivo = $event.target.files[0]?.name ?? ''">
                        <div class="ayuda" x-show="archivo" x-cloak>
                            Seleccionado: <strong x-text="archivo"></strong>
                        </div>
                        @error('archivo') <div class="error">{{ $message }}</div> @enderror
                    </div>

                    <p class="ayuda" style="margin: 0 0 .3rem; font-weight: 600;">Cómo tiene que verse:</p>
                    <pre style="background: var(--bg); border: 1px solid var(--border); border-radius: .4rem;
                                padding: .6rem .7rem; font-size: .72rem; line-height: 1.5; overflow-x: auto;
                                margin: 0 0 .9rem;">CI/ID,Nombre,Fecha,Hora
7633685,MOLINA GUZMAN IGNACIO,27/07/2026,08:25:00
6411757,BELLIDO BECERRA LUIS CARLOS,27/07/2026,16:50:00</pre>

                    {{-- <ul class="ayuda" style="margin: 0 0 1rem; padding-left: 1.1rem; line-height: 1.6;">
                        <li>La primera fila puede ser el encabezado: se descarta sola.</li>
                        <li>Fecha: <strong>31/12/2026</strong>, <strong>31-12-2026</strong> o <strong>2026-12-31</strong>.</li>
                        <li>Hora: <strong>08:25</strong> u <strong>08:25:00</strong>.</li>
                        <li>Separador <strong>,</strong> o <strong>;</strong> — se detecta automáticamente.</li>
                        <li>Se saltea lo que ya existe (mismo CI, fecha y hora) y los CI que no son de ningún funcionario. Al terminar se informa cuántas entraron y cuántas no.</li>
                        <li>Las marcaciones importadas quedan con el tipo del archivo; nada se sobreescribe.</li>
                        <li>Tamaño máximo: 10 MB.</li>
                    </ul> --}}

                    <div class="modal-acciones">
                        <button type="button" class="btn btn--gris" x-on:click="abierto = false">
                            <x-heroicon-o-x-mark />Cancelar
                        </button>
                        <button type="submit" class="btn"><x-heroicon-o-arrow-up-tray />Importar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endcan
