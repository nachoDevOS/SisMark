{{-- Campos compartidos por alta y edición. $diaExcepcional puede no existir (alta). --}}
@php
    $diaExcepcional ??= null;
@endphp

<div class="campo">
    <label for="fecha">Fecha <span class="req">*</span></label>
    <input type="date" id="fecha" name="fecha"
           value="{{ old('fecha', $diaExcepcional?->fecha?->format('Y-m-d') ?? '') }}" required>
    @error('fecha') <div class="error">{{ $message }}</div> @enderror
</div>

<div class="campo">
    <label for="motivoInasistencia">Motivo de inasistencia general <span class="req">*</span></label>
    <input type="text" id="motivoInasistencia" name="motivoInasistencia" maxlength="255"
           value="{{ old('motivoInasistencia', $diaExcepcional->motivoInasistencia ?? '') }}" required>
    <div class="ayuda">Ej. «FERIADO POR CARNAVAL», «ANIVERSARIO DEL BENI».</div>
    @error('motivoInasistencia') <div class="error">{{ $message }}</div> @enderror
</div>

<div class="campo">
    <label for="respaldo">Respaldo</label>
    <input type="file" id="respaldo" name="respaldo" accept=".jpg,.jpeg,.png,.pdf">
    <div class="ayuda">
        Opcional. Decreto, resolución o memorándum que declara el día.
        Imagen (JPG o PNG) o PDF, hasta 5 MB.
        @if ($diaExcepcional?->adjunto)
            {{-- En la edición, no elegir archivo deja el que ya está cargado. --}}
            <br>Cargado:
            <a href="{{ route('dias-excepcionales.respaldo', $diaExcepcional) }}" target="_blank" rel="noopener">
                {{ $diaExcepcional->adjuntoNombre ?: 'ver respaldo' }}
            </a>. Elegí un archivo solo si querés reemplazarlo.
        @endif
    </div>
    @error('respaldo') <div class="error">{{ $message }}</div> @enderror
</div>

<div class="campo">
    <label for="observacion">Observación</label>
    <input type="text" id="observacion" name="observacion" maxlength="255"
           value="{{ old('observacion', $diaExcepcional->observacion ?? '') }}">
    @error('observacion') <div class="error">{{ $message }}</div> @enderror
</div>
