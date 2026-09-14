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
                {{-- De solo lectura: sale del nombre. Escribirlo a mano era pedir dos
                     veces lo mismo y dejaba que se separaran del nombre, y es el corto
                     el que se ve en la consola y en el nombre del token. --}}
                <input type="text" id="slug" name="slug" maxlength="50"
                       value="{{ old('slug') }}" placeholder="mamore" readonly
                       style="background: #f3f4f6; color: #4b5563;">
                <small class="ayuda">
                    Sale solo del nombre: minúsculas, sin acentos y con guiones en lugar de
                    espacios. Es el nombre con el que se lo llama desde la consola y con el que
                    queda bautizado el token. No se escribe ni se cambia después.
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

@if ($esNuevo)
    <script>
        (function () {
            // El nombre corto se arma mientras se tipea el nombre, para que no haya
            // que mandar el formulario para verlo. Es un espejo de lo que hace
            // `StoreSistemaExternoRequest::slugDelNombre()`, que es quien manda: el
            // campo va de solo lectura y el servidor ignora lo que llegue en él.
            const nombre = document.getElementById('nombre');
            const corto = document.getElementById('slug');

            const aNombreCorto = (texto) => texto
                .normalize('NFD').replace(/[̀-ͯ]/g, '')
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '')
                .slice(0, 50)
                .replace(/-+$/, '');

            const sincronizar = () => { corto.value = aNombreCorto(nombre.value); };

            nombre.addEventListener('input', sincronizar);

            // Al volver de un error de validación el nombre ya viene cargado y el
            // corto tiene que acompañarlo.
            sincronizar();
        })();
    </script>
@endif
