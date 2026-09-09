{{-- Campos compartidos por alta y edición. $horario puede no existir (alta).
     Réplica del formulario «Añadir/editar horario» del SIA de escritorio.
     Los name="..." del form quedan en PascalCase (claves del request); los
     valores de precarga leen los atributos camelCase del modelo Turno local. --}}
@php
    $horario ??= null;
    $hm = fn ($valor) => $valor?->format('H:i');
@endphp

<div class="form-grid" style="grid-template-columns: 1fr 1fr;"
     x-data="{
         dia: '{{ old('Dia', $horario->dia ?? '') }}',
         hEntrada: '{{ old('HEntrada', $hm($horario->hEntrada ?? null)) }}',
         hSalida: '{{ old('HSalida', $hm($horario->hSalida ?? null)) }}',
         nombre: '',
         abreviaturas: { 1: 'DOM', 2: 'LUN', 3: 'MAR', 4: 'MIE', 5: 'JUE', 6: 'VIE', 7: 'SAB' },
     }"
     x-effect="nombre = (abreviaturas[dia] && hEntrada && hSalida) ? (abreviaturas[dia] + ': ' + hEntrada + ' - ' + hSalida) : ''">
    <div class="tarjeta" style="grid-column: 1 / -1;">
        <h2>Descripción del turno</h2>
        <div class="grid-2">
            <div class="campo">
                <label for="Dia">Día <span class="req">*</span></label>
                <select id="Dia" name="Dia" x-model="dia" required>
                    <option value="">Seleccione una opción</option>
                    @foreach (\App\Models\Turno::DIAS as $numero => $nombre)
                        <option value="{{ $numero }}" @selected((string) old('Dia', $horario->dia ?? '') === (string) $numero)>{{ $nombre }}</option>
                    @endforeach
                </select>
                @error('Dia') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="campo">
                <label for="NombreTurno">Nombre del turno <span class="req">*</span></label>
                <input type="text" id="NombreTurno" name="NombreTurno" maxlength="25"
                       :value="nombre" readonly required>
                <div class="ayuda">Se arma solo con el día y las horas de entrada/salida.</div>
                @error('NombreTurno') <div class="error">{{ $message }}</div> @enderror
            </div>
        </div>
    </div>

    <div class="tarjeta">
        <h2>Entrada</h2>
        <div class="grid-2">
            <div class="campo">
                <label for="HEntrada">Hora de entrada <span class="req">*</span></label>
                <input type="time" id="HEntrada" name="HEntrada" x-model="hEntrada" value="{{ old('HEntrada', $hm($horario->hEntrada ?? null)) }}" required>
                @error('HEntrada') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="campo">
                <label for="HTolerancia">Hora de tolerancia de entrada <span class="req">*</span></label>
                <input type="time" id="HTolerancia" name="HTolerancia" value="{{ old('HTolerancia', $hm($horario->hTolerancia ?? null)) }}" required>
                @error('HTolerancia') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="campo">
                <label for="EMinima">Mínima hora de entrada <span class="req">*</span></label>
                <input type="time" id="EMinima" name="EMinima" value="{{ old('EMinima', $hm($horario->eMinima ?? null)) }}" required>
                @error('EMinima') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="campo">
                <label for="EMaxima">Máxima hora de entrada <span class="req">*</span></label>
                <input type="time" id="EMaxima" name="EMaxima" value="{{ old('EMaxima', $hm($horario->eMaxima ?? null)) }}" required>
                @error('EMaxima') <div class="error">{{ $message }}</div> @enderror
            </div>
        </div>
    </div>

    <div class="tarjeta">
        <h2>Salida</h2>
        <div class="grid-2">
            <div class="campo">
                <label for="HSalida">Hora de salida <span class="req">*</span></label>
                <input type="time" id="HSalida" name="HSalida" x-model="hSalida" value="{{ old('HSalida', $hm($horario->hSalida ?? null)) }}" required>
                @error('HSalida') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="campo">
                <label for="STolerancia">Hora de tolerancia de salida <span class="req">*</span></label>
                <input type="time" id="STolerancia" name="STolerancia" value="{{ old('STolerancia', $hm($horario->sTolerancia ?? null)) }}" required>
                @error('STolerancia') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="campo">
                <label for="SMinima">Mínima hora de salida <span class="req">*</span></label>
                <input type="time" id="SMinima" name="SMinima" value="{{ old('SMinima', $hm($horario->sMinima ?? null)) }}" required>
                @error('SMinima') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="campo">
                <label for="SMaxima">Máxima hora de salida <span class="req">*</span></label>
                <input type="time" id="SMaxima" name="SMaxima" value="{{ old('SMaxima', $hm($horario->sMaxima ?? null)) }}" required>
                @error('SMaxima') <div class="error">{{ $message }}</div> @enderror
            </div>
        </div>
        <div class="campo check">
            <input type="checkbox" id="SiguienteDia" name="SiguienteDia" value="1"
                   @checked(old('SiguienteDia', $horario->siguienteDia ?? false))>
            <label for="SiguienteDia" style="margin: 0;">La salida corresponde al día siguiente</label>
        </div>
    </div>

    <div class="tarjeta" style="grid-column: 1 / -1;">
        <h2>Horas trabajadas</h2>
        <div class="campo" style="max-width: 14rem;">
            <label for="HTrabajadas">Horas trabajadas <span class="req">*</span></label>
            <input type="number" id="HTrabajadas" name="HTrabajadas" step="0.01" min="0" max="24"
                   value="{{ old('HTrabajadas', $horario->hTrabajadas ?? '0.00') }}" required>
            @error('HTrabajadas') <div class="error">{{ $message }}</div> @enderror
        </div>

        <div class="campo check" style="margin-bottom: 0;">
            <input type="checkbox" id="Sugerido" name="Sugerido" value="1"
                   @checked(old('Sugerido', $horario->sugerido ?? false))>
            <label for="Sugerido" style="margin: 0;">
                Forma parte del horario sugerido
                <small style="display: block; font-weight: 400; color: #6b7280;">
                    Es el que se ofrece por defecto al dar de alta un contrato. Marcá un turno por
                    cada día que se trabaja: el horario semanal son varias filas, una por día.
                </small>
            </label>
        </div>
    </div>
</div>
