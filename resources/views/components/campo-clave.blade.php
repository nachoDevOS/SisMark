@props(['id', 'name' => null])

{{-- Campo de contraseña con el ojo adentro del recuadro para verla u
     ocultarla. Los demás atributos (autocomplete, required, minlength…) pasan
     directo al <input>. El estilo y el script van una sola vez por página,
     aunque haya varios campos. --}}
@once
    <style>
        .clave { position: relative; }
        /* `[type]` iguala el peso de `.campo input[type=password]` del panel,
           y como esta regla va después, gana: el texto no pasa bajo el ojo. */
        .clave input[type] { padding-right: 2.6rem; }
        .clave__ojo { position: absolute; top: 0; right: 0; height: 100%; width: 2.6rem;
            display: flex; align-items: center; justify-content: center;
            border: 0; padding: 0; background: none; cursor: pointer; color: var(--muted); }
        .clave__ojo:hover { color: var(--fg); }
        .clave__ojo:focus-visible { outline: 2px solid var(--verde); outline-offset: -2px; border-radius: .5rem; }
        .clave__ojo svg { width: 1.1rem; height: 1.1rem; }
        /* El navegador no oculta solo un <svg> con `hidden`: sin esto se ven los dos ojos. */
        .clave__ojo svg[hidden] { display: none; }
    </style>
    <script>
        /* El ojo solo cambia el tipo del campo: nada sale del navegador y el
           foco vuelve a la contraseña para no cortar el tecleo. Va delegado en
           el documento para servir a todos los campos de la página. */
        document.addEventListener('click', function (evento) {
            var boton = evento.target.closest('.clave__ojo');

            if (!boton) {
                return;
            }

            var campo = document.getElementById(boton.getAttribute('aria-controls'));
            var visible = campo.type === 'text';
            var posicion = campo.selectionStart;

            campo.type = visible ? 'password' : 'text';
            boton.setAttribute('aria-pressed', String(!visible));

            var rotulo = visible ? 'Mostrar la contraseña' : 'Ocultar la contraseña';
            boton.setAttribute('aria-label', rotulo);
            boton.title = rotulo;

            /* Va por atributo y no por `.hidden`: los <svg> son SVGElement y
               no heredan esa propiedad de HTMLElement, así que asignarla no
               escribe nada en el DOM y el icono se quedaba congelado. */
            boton.querySelector('[data-ojo="mostrar"]').toggleAttribute('hidden', !visible);
            boton.querySelector('[data-ojo="ocultar"]').toggleAttribute('hidden', visible);

            campo.focus();

            if (posicion !== null) {
                try {
                    campo.setSelectionRange(posicion, posicion);
                } catch (e) {
                    /* Algunos navegadores no permiten mover el cursor recién cambiado el tipo. */
                }
            }
        });
    </script>
@endonce

<div class="clave">
    <input type="password" id="{{ $id }}" name="{{ $name ?? $id }}" {{ $attributes }}>
    <button type="button" class="clave__ojo" aria-controls="{{ $id }}" aria-pressed="false"
            aria-label="Mostrar la contraseña" title="Mostrar la contraseña">
        <svg data-ojo="mostrar" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"/>
            <circle cx="12" cy="12" r="3"/>
        </svg>
        {{-- El mismo ojo, pero tachado: la raja cruzada se lee de un vistazo
             como «apagar», cosa que el ojo partido no. --}}
        <svg data-ojo="ocultar" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" hidden>
            <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"/>
            <circle cx="12" cy="12" r="3"/>
            <line x1="3.5" y1="3.5" x2="20.5" y2="20.5"/>
        </svg>
    </button>
</div>
