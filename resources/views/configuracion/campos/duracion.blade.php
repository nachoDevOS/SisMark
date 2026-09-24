{{-- Campo de tipo duración: se guarda en minutos y se edita en horas y minutos.
     Recibe $campo (nombre del campo), $valor (minutos guardados o null) y
     $parametro (su definición en Configuracion::PARAMETROS). --}}
@php
    $minutosGuardados = (int) $valor;
@endphp

<div class="grid-2" style="max-width: 22rem;">
    <div class="campo" style="margin-bottom: 0;">
        <label for="{{ $campo }}-horas">Horas</label>
        <input type="number" id="{{ $campo }}-horas" name="{{ $campo }}[horas]" min="0"
               max="{{ intdiv($parametro['maximo'], 60) }}" required
               value="{{ old($campo.'.horas', intdiv($minutosGuardados, 60)) }}">
        @error($campo.'.horas') <div class="error">{{ $message }}</div> @enderror
    </div>
    <div class="campo" style="margin-bottom: 0;">
        <label for="{{ $campo }}-minutos">Minutos</label>
        <input type="number" id="{{ $campo }}-minutos" name="{{ $campo }}[minutos]" min="0" max="59" required
               value="{{ old($campo.'.minutos', $minutosGuardados % 60) }}">
        @error($campo.'.minutos') <div class="error">{{ $message }}</div> @enderror
    </div>
</div>
