@php
    $esNuevo = ! $sistema->exists;
@endphp

<div class="form-grid" style="grid-template-columns: 1fr 1fr;">
    <div class="tarjeta" style="grid-column: 1 / -1;">
        <h2>Identificación</h2>

        <div class="campo">
            <label for="nombre">Nombre <span class="req">*</span></label>
            <input type="text" id="nombre" name="nombre" maxlength="100"
                   value="{{ old('nombre', $sistema->nombre) }}" required>
            @error('nombre') <div class="error">{{ $message }}</div> @enderror
        </div>

        <div class="campo">
            <label for="slug">Nombre corto <span class="req">*</span></label>
            @if ($esNuevo)
                <input type="text" id="slug" name="slug" maxlength="50" pattern="[a-z0-9\-]+"
                       value="{{ old('slug') }}" placeholder="mamore" required>
                <small class="ayuda">
                    Minúsculas, números y guiones. Es el nombre con el que se lo llama desde la
                    consola y con el que queda bautizado el token. No se puede cambiar después.
                </small>
            @else
                {{-- No se edita: el token entregado quedó bautizado con este nombre,
                     y cambiarlo dejaría la credencial viva apuntando a uno que ya no
                     existe. Para renombrar se da de baja y se registra de nuevo. --}}
                <input type="text" id="slug" value="{{ $sistema->slug }}" disabled>
                <small class="ayuda">
                    No se puede cambiar: el token ya entregado quedó bautizado con este nombre.
                </small>
            @endif
            @error('slug') <div class="error">{{ $message }}</div> @enderror
        </div>

        <div class="campo">
            <label for="observaciones">Observaciones</label>
            <textarea id="observaciones" name="observaciones" rows="3" maxlength="1000"
                      placeholder="Para qué consume la API, con quién coordinar…">{{ old('observaciones', $sistema->observaciones) }}</textarea>
            @error('observaciones') <div class="error">{{ $message }}</div> @enderror
        </div>

        <div class="campo check" style="margin-bottom: 0;">
            <input type="checkbox" id="activo" name="activo" value="1"
                   @checked(old('activo', $esNuevo ? true : $sistema->activo))>
            <label for="activo" style="margin: 0;">
                Activo
                <small style="display: block; font-weight: 400; color: #6b7280;">
                    Apagarlo le corta el acceso en el próximo pedido sin borrarle el token, así
                    volver a encenderlo lo devuelve a andar sin coordinar una credencial nueva.
                </small>
            </label>
        </div>
    </div>
</div>
