{{-- Campo de tipo opciones: una sola de una lista cerrada, con radios para que
     se lea la explicación de cada una sin abrir un desplegable.
     Recibe $campo (nombre del campo), $valor (el que rige) y $parametro (su
     definición en Configuracion::PARAMETROS, con `opciones`: valor → etiqueta). --}}
@php
    $elegida = old($campo, $valor);
@endphp

<div style="display: flex; flex-direction: column; gap: .5rem;">
    @foreach ($parametro['opciones'] as $opcion => $etiqueta)
        <label style="display: flex; gap: .5rem; align-items: flex-start; font-weight: normal; margin: 0;">
            <input type="radio" name="{{ $campo }}" value="{{ $opcion }}" required
                   @checked((string) $elegida === (string) $opcion) style="margin-top: .2rem;">
            <span>{{ $etiqueta }}</span>
        </label>
    @endforeach
</div>
@error($campo) <div class="error">{{ $message }}</div> @enderror
