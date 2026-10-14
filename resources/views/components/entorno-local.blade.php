{{--
    Distintivo de entorno local.

    La copia local y la de producción son idénticas a la vista y se confunden
    con las dos pestañas abiertas. En local el sidebar se tiñe de otro color;
    en cualquier otro entorno no dibuja nada.
--}}
@if (app()->environment('local'))
    <style class="entorno-local">
        :root { --sidebar: #5b1a1a; --sidebar-header: #3d1010; --sidebar-hover: #742424; --sidebar-activo: #8a2e2e; }
    </style>
@endif
