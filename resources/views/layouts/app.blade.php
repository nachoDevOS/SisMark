<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('titulo', 'Biométricos') · {{ config('app.name') }}</title>
    {{-- Alpine.js autohospedado (sin CDN externo, sin build): para el dropdown
         "Mas" de las acciones de fila y el toggle del sidebar en móvil. --}}
    <script defer src="{{ asset('vendor/alpine/alpine.min.js') }}"></script>
    <style>
        [x-cloak] { display: none !important; }
        :root {
            --verde: #00a65a; --verde-osc: #008d4c; --bg: #f4f6f9; --card: #fff;
            --fg: #1f2937; --muted: #6b7280; --border: #e5e7eb; --danger: #ef4444;
            --sidebar: #0d3b3e; --sidebar-header: #082628; --sidebar-hover: #164e52; --sidebar-activo: #1c6266;
            --sidebar-fg: #ffffff; --sidebar-fg-activo: #fff;
            --thead: #0d3b3e; --thead-fg: #fff;
            --sidebar-w: 15.5rem; --sidebar-rail: 3.5rem;
        }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            background: var(--bg); color: var(--fg); font-size: .9rem; line-height: 1.45;
            display: flex; min-height: 100vh; }
        a { color: inherit; text-decoration: none; }
        svg { display: block; }

        /* ===== Sidebar ===== */
        .sidebar { width: var(--sidebar-w); flex-shrink: 0; background: var(--sidebar);
            color: var(--sidebar-fg); display: flex; flex-direction: column;
            position: fixed; top: 0; left: 0; bottom: 0; overflow-y: auto; overflow-x: hidden; z-index: 30;
            transition: transform .2s ease, width .2s ease; }
        .sidebar__marca { display: flex; align-items: center; gap: .6rem; padding: 1rem 1.1rem;
            font-weight: 700; font-size: .8rem; color: #fff; background: var(--sidebar-header);
            line-height: 1.25; }
        .sidebar__marca img { height: 2rem; width: auto; flex-shrink: 0; }
        .sidebar__nav { padding: .6rem; display: flex; flex-direction: column; gap: .15rem; }
        .sidebar__link { display: flex; align-items: center; gap: .7rem; padding: .55rem .7rem;
            border-radius: .45rem; font-size: .8rem; font-weight: 500; color: var(--sidebar-fg); }
        .sidebar__link svg { width: 1.15rem; height: 1.15rem; flex-shrink: 0; }
        .sidebar__link:hover { background: var(--sidebar-hover); color: #fff; }
        .sidebar__link.activo { background: var(--sidebar-activo); color: var(--sidebar-fg-activo);
            font-weight: 600; box-shadow: inset .2rem 0 0 var(--verde); }
        .sidebar__overlay { display: none; }

        /* Grupo colapsable del sidebar ("Parámetros" → submenú). */
        .sidebar__grouptoggle { display: flex; align-items: center; gap: .7rem; width: 100%;
            padding: .55rem .7rem; border: 0; background: none; color: var(--sidebar-fg);
            font-family: inherit; font-size: .8rem; font-weight: 500; cursor: pointer; border-radius: .45rem; }
        .sidebar__grouptoggle:hover { background: var(--sidebar-hover); color: #fff; }
        /* El grupo se marca cuando la pantalla actual es una de sus opciones,
           así se sabe dónde se está aunque el submenú esté plegado. */
        .sidebar__grouptoggle.activo { background: var(--sidebar-activo); color: var(--sidebar-fg-activo);
            font-weight: 600; box-shadow: inset .2rem 0 0 var(--verde); }
        .sidebar__grouptoggle > svg { width: 1.15rem; height: 1.15rem; flex-shrink: 0; }
        .sidebar__chevron { margin-left: auto; display: inline-flex; transition: transform .15s ease; }
        .sidebar__chevron svg { width: .9rem; height: .9rem; }
        .sidebar__submenu { display: flex; flex-direction: column; gap: .15rem;
            padding-left: 1.1rem; margin-top: .15rem; }
        .sidebar__sublink { display: flex; align-items: center; gap: .6rem; padding: .5rem .7rem;
            border-radius: .45rem; font-size: .78rem; color: var(--sidebar-fg); }
        .sidebar__sublink svg { width: 1.05rem; height: 1.05rem; flex-shrink: 0; }
        .sidebar__sublink:hover { background: var(--sidebar-hover); color: #fff; }
        .sidebar__sublink.activo { background: var(--sidebar-activo); color: var(--sidebar-fg-activo);
            font-weight: 600; box-shadow: inset .2rem 0 0 var(--verde); }

        /* ===== Zona principal: topbar + contenido ===== */
        .zona { margin-left: var(--sidebar-w); flex: 1; min-width: 0; display: flex; flex-direction: column; }
        .topbar { background: #fff; border-bottom: 1px solid var(--border); padding: .65rem 1.5rem;
            display: flex; align-items: center; justify-content: space-between; gap: 1rem;
            position: sticky; top: 0; z-index: 20; }
        .topbar__toggle { display: inline-flex; background: none; border: 0; cursor: pointer;
            padding: .3rem; border-radius: .4rem; color: var(--muted); }
        .topbar__toggle:hover { background: var(--bg); color: var(--fg); }
        .topbar__toggle svg { width: 1.4rem; height: 1.4rem; }
        .topbar__vol { font-size: .8125rem; color: var(--muted); }
        .topbar__vol:hover { color: var(--fg); text-decoration: underline; }

        /* ===== Menú de la cuenta (topbar, a la derecha) ===== */
        .cuenta { position: relative; }
        .cuenta__boton { display: inline-flex; align-items: center; gap: .55rem; cursor: pointer;
            background: none; border: 1px solid transparent; border-radius: 2rem; padding: .25rem .6rem .25rem .25rem;
            font-family: inherit; font-size: .8125rem; color: var(--fg); }
        .cuenta__boton:hover { background: var(--bg); border-color: var(--border); }
        .cuenta__avatar { display: inline-flex; align-items: center; justify-content: center; overflow: hidden;
            width: 2rem; height: 2rem; border-radius: 50%; background: var(--thead); color: #fff;
            font-size: .75rem; font-weight: 700; flex-shrink: 0; }
        .cuenta__avatar img { width: 100%; height: 100%; object-fit: cover; }
        .cuenta__nombre { font-weight: 600; max-width: 12rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .cuenta__chevron { display: inline-flex; color: var(--muted); transition: transform .15s ease; }
        .cuenta__chevron svg { width: .9rem; height: .9rem; }
        .cuenta__menu { position: absolute; right: 0; top: calc(100% + .45rem); z-index: 40; min-width: 15rem;
            background: var(--card); border: 1px solid var(--border); border-radius: .6rem;
            box-shadow: 0 .6rem 1.5rem rgba(0,0,0,.12); overflow: hidden; }
        .cuenta__ficha { padding: .75rem .9rem; border-bottom: 1px solid var(--border); }
        .cuenta__ficha-nombre { font-weight: 700; font-size: .8125rem; }
        .cuenta__ficha-dato { font-size: .75rem; color: var(--muted); word-break: break-all; }
        .cuenta__salir { display: flex; align-items: center; gap: .55rem; width: 100%; cursor: pointer;
            background: none; border: 0; padding: .7rem .9rem; font-family: inherit; font-size: .8125rem;
            font-weight: 600; color: var(--danger); text-align: left; }
        .cuenta__salir:hover { background: #fef2f2; }
        .cuenta__salir svg { width: 1.05rem; height: 1.05rem; }

        /* Periodo de una licencia: las dos fechas pesan igual, con el «al»
           chico entre medio. Apiladas porque la columna es angosta. */
        .periodo { display: inline-flex; flex-direction: column; align-items: flex-start;
            line-height: 1.25; font-weight: 700; white-space: nowrap; }
        .periodo__al { font-weight: 400; font-size: .7rem; color: var(--muted); }

        /* Aviso de solicitudes por resolver, a la izquierda de la cuenta */
        /* `margin-left: auto` lo pega a la cuenta: la barra usa
           `space-between`, y sin esto quedaba flotando en el medio. */
        .aviso-pendientes { display: inline-flex; align-items: center; gap: .35rem; margin-left: auto;
            padding: .2rem .5rem; border-radius: 9999px; background: #fef3c7; color: #92400e;
            font-size: .75rem; font-weight: 600; border: 1px solid #fde68a; }
        .aviso-pendientes:hover { background: #fde68a; }
        .aviso-pendientes svg { width: .9rem; height: .9rem; }
        .aviso-pendientes__conteo { display: inline-flex; align-items: center; justify-content: center;
            min-width: 1.1rem; height: 1.1rem; padding: 0 .25rem; border-radius: 9999px;
            background: var(--danger); color: #fff; font-size: .6875rem; font-weight: 700;
            animation: tintineo 1.6s ease-in-out infinite; }

        /* El conteo late para que se note desde cualquier pantalla. Va sobre el
           número y no sobre todo el aviso: animar el bloque entero mueve el
           renglón de la barra. `box-shadow` y `transform` no reflowean. */
        @keyframes tintineo {
            0%, 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(239, 68, 68, .6); }
            50%      { transform: scale(1.15); box-shadow: 0 0 0 .25rem rgba(239, 68, 68, 0); }
        }

        /* Quien pidió menos movimiento en el sistema operativo no lo recibe. */
        @media (prefers-reduced-motion: reduce) {
            .aviso-pendientes__conteo { animation: none; }
        }

        @media (max-width: 640px) {
            .cuenta__nombre { display: none; }
            .aviso-pendientes__texto { display: none; }
        }

        .contenedor { padding: 1.5rem; }
        .cabecera { display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 1.1rem; gap: 1rem; flex-wrap: wrap; }
        .cabecera__titulo { display: flex; align-items: center; gap: .65rem; }
        .cabecera__icono { display: inline-flex; align-items: center; justify-content: center;
            width: 2.25rem; height: 2.25rem; border-radius: .55rem; background: #e6f2f1; color: var(--thead); flex-shrink: 0; }
        .cabecera__icono svg { width: 1.25rem; height: 1.25rem; }
        .cabecera h1 { font-size: 1.15rem; margin: 0; }

        /* ===== Botones ===== */
        .btn { display: inline-flex; align-items: center; gap: .4rem; border: 0; cursor: pointer;
            background: var(--verde); color: #fff; padding: .5rem .85rem; border-radius: .5rem;
            font-size: .8125rem; font-weight: 600; line-height: 1.2; white-space: nowrap; }
        .btn svg { width: 1.125rem; height: 1.125rem; flex-shrink: 0; }
        .btn:hover { background: var(--verde-osc); }
        .btn--gris { background: #6b7280; }
        .btn--gris:hover { background: #4b5563; }
        .btn--peligro { background: var(--danger); }
        .btn--peligro:hover { background: #dc2626; }
        .btn--sm { padding: .3rem .6rem; font-size: .75rem; }
        .btn--sm svg { width: 1rem; height: 1rem; }
        .btn:disabled { opacity: .7; cursor: default; }
        .btn__contenido { display: inline-flex; align-items: center; gap: .4rem; }
        .spinner-anillo { width: 1rem; height: 1rem; border: 2px solid rgba(255,255,255,.4);
            border-top-color: #fff; border-radius: 50%; animation: girar .6s linear infinite; flex-shrink: 0; }
        @keyframes girar { to { transform: rotate(360deg); } }

        /* ===== Cajas ===== */
        .card { background: var(--card); border: 1px solid var(--border); border-radius: .625rem;
            box-shadow: 0 1px 2px rgba(0,0,0,.05); }
        .card--padded { padding: 1.25rem; }

        /* ===== Solapas (pie de la ficha del funcionario) ===== */
        .tabs { display: flex; gap: .25rem; flex-wrap: wrap; border-bottom: 1px solid var(--border);
            margin-bottom: 1rem; }
        .tabs__boton { display: inline-flex; align-items: center; gap: .45rem; cursor: pointer;
            background: none; border: 0; border-bottom: 2px solid transparent; margin-bottom: -1px;
            padding: .55rem .9rem; font-family: inherit; font-size: .8125rem; font-weight: 600;
            color: var(--muted); }
        .tabs__boton svg { width: 1.05rem; height: 1.05rem; }
        .tabs__boton:hover { color: var(--fg); background: var(--bg); border-radius: .4rem .4rem 0 0; }
        .tabs__boton.activo { color: var(--thead); border-bottom-color: var(--verde); }
        .tabs__panel[hidden] { display: none; }

        table { width: 100%; border-collapse: collapse; font-size: .8125rem; }
        thead th { text-align: left; background: var(--thead); color: var(--thead-fg); font-size: .7rem;
            text-transform: uppercase; letter-spacing: .04em; padding: .6rem .75rem; }
        thead tr:first-child th:first-child { border-top-left-radius: .625rem; }
        thead tr:first-child th:last-child { border-top-right-radius: .625rem; }
        tbody tr:last-child td:first-child { border-bottom-left-radius: .625rem; }
        tbody tr:last-child td:last-child { border-bottom-right-radius: .625rem; }
        tbody td { padding: .5rem .75rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
        tbody tr:last-child td { border-bottom: 0; }
        tbody tr:hover { background: #f9fafb; }
        /* Fila de solo lectura (p. ej. asignación de turno vencida). */
        tbody tr.fila--inactiva td { color: var(--muted); background: var(--bg); }

        .pill { display: inline-block; padding: .2rem .6rem; border-radius: 9999px; font-size: .7rem; font-weight: 700; }
        .pill--ok { background: #dcfce7; color: #166534; }
        .pill--no { background: #fee2e2; color: #991b1b; }
        .pill--advertencia { background: #fef3c7; color: #92400e; }
        .pill--info { background: #dbeafe; color: #1e40af; }

        .acciones { display: flex; gap: .35rem; align-items: center; }

        /* Celda de persona: foto + nombre con CI y profesión debajo. */
        .persona-celda { display: flex; align-items: center; gap: .7rem; }
        .persona-foto { width: 2.6rem; height: 2.6rem; border-radius: .45rem; flex-shrink: 0;
            background: #e5e7eb; color: #9ca3af; display: inline-flex; align-items: center; justify-content: center;
            overflow: hidden; }
        .persona-foto svg { width: 1.5rem; height: 1.5rem; }
        .persona-foto img { width: 100%; height: 100%; object-fit: cover; display: block; }

        /* Zoom del avatar: al pasar el mouse (o al tabular hasta la fila) la foto
           se amplía en un globo que escapa de la celda. */
        .persona-avatar { position: relative; display: inline-flex; flex-shrink: 0; }
        .persona-zoom { position: absolute; left: 0; top: calc(100% + .3rem); z-index: 40;
            display: none; padding: .25rem; background: var(--card);
            border: 1px solid var(--border); border-radius: .55rem;
            box-shadow: 0 .6rem 1.5rem rgba(0,0,0,.18); }
        .persona-avatar:hover .persona-zoom,
        .persona-avatar:focus-within .persona-zoom { display: block; }
        .persona-zoom img { display: block; width: 14rem; height: auto; border-radius: .4rem; }

        /* Foto de la ficha del funcionario. */
        .ficha-foto { width: 9rem; height: 9rem; margin: 0 auto 1rem; border-radius: .6rem;
            background: #e5e7eb; color: #9ca3af; display: flex; align-items: center; justify-content: center;
            overflow: hidden; border: 1px solid var(--border); }
        .ficha-foto img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .ficha-foto svg { width: 3.5rem; height: 3.5rem; }
        .persona-nombre { font-weight: 600; }
        .persona-meta { color: var(--muted); font-size: .75rem; line-height: 1.35; }

        /* ===== Botón ícono cuadrado: acción principal de fila (Ver/Editar) ===== */
        .btn-icon { display: inline-flex; align-items: center; justify-content: center;
            width: 1.9rem; height: 1.9rem; border-radius: .4rem; border: 0; cursor: pointer;
            background: var(--verde); color: #fff; flex-shrink: 0; }
        .btn-icon svg { width: 1rem; height: 1rem; }
        .btn-icon:hover { background: var(--verde-osc); }
        .btn-icon--gris { background: #6b7280; }
        .btn-icon--gris:hover { background: #4b5563; }
        .btn-icon--peligro { background: var(--danger); }
        .btn-icon--peligro:hover { background: #dc2626; }

        /* ===== Dropdown "Mas": agrupa acciones secundarias/destructivas ===== */
        .dropdown { position: relative; display: inline-flex; }
        .dropdown-toggle { display: inline-flex; align-items: center; gap: .3rem;
            background: #3b5bdb; color: #fff; border: 0; cursor: pointer;
            padding: 0 .55rem; height: 1.9rem; border-radius: .4rem; font-size: .75rem; font-weight: 600; }
        .dropdown-toggle:hover { background: #3348b0; }
        .dropdown-toggle svg { width: .8rem; height: .8rem; }
        .dropdown-menu { position: absolute; right: 0; top: calc(100% + .3rem); z-index: 20;
            background: #fff; border: 1px solid var(--border); border-radius: .5rem;
            box-shadow: 0 8px 20px rgba(0,0,0,.15); min-width: 13.5rem; padding: .3rem; }
        .dropdown-menu form { margin: 0; }
        .dropdown-menu a, .dropdown-menu button { display: flex; align-items: center; gap: .55rem;
            width: 100%; text-align: left; background: none; border: 0; cursor: pointer;
            padding: .45rem .6rem; border-radius: .35rem; font-size: .8125rem; color: var(--fg);
            font-family: inherit; white-space: nowrap; }
        .dropdown-menu a:hover, .dropdown-menu button:hover { background: #f3f4f6; }
        .dropdown-menu svg { width: 1rem; height: 1rem; flex-shrink: 0; }
        /* Las acciones que destruyen información van al pie, separadas por una
           línea y en rojo, para que no se elijan por error al apuntar a la de
           al lado. El bloque entero se tiñe al pasar el mouse. */
        .dropdown-menu__peligro { margin-top: .3rem; padding-top: .3rem; border-top: 1px solid var(--border); }
        .dropdown-menu__peligro button { color: var(--danger); }
        .dropdown-menu__peligro button:hover { background: #fee2e2; color: #b91c1c; }
        .aviso { background: #dcfce7; color: #166534; padding: .65rem .9rem; border-radius: .5rem;
            margin-bottom: 1.1rem; font-size: .85rem; }
        .aviso--error { background: #fee2e2; color: #991b1b; }
        .aviso--advertencia { background: #fef3c7; color: #92400e; }

        /* ===== Toaster (avisos flotantes) ===== */
        .toaster { position: fixed; top: 1rem; right: 1rem; z-index: 80; display: flex;
            flex-direction: column; gap: .5rem; max-width: min(24rem, calc(100vw - 2rem)); }
        .toast { display: flex; align-items: flex-start; gap: .6rem; padding: .7rem .85rem;
            border-radius: .55rem; font-size: .85rem; line-height: 1.35; color: #fff;
            box-shadow: 0 8px 24px rgba(0,0,0,.18); }
        .toast--ok { background: var(--verde); }
        .toast--error { background: var(--danger); }
        .toast__cuerpo { flex: 1; }
        .toast__cerrar { background: none; border: 0; color: inherit; cursor: pointer;
            font-size: 1.05rem; line-height: 1; opacity: .85; padding: 0; }
        .toast__cerrar:hover { opacity: 1; }
        .toast svg { width: 1.1rem; height: 1.1rem; flex-shrink: 0; margin-top: .05rem; }

        /* ===== Modal (rango de fechas para descargar, etc.) ===== */
        .modal-fondo { position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 50;
            display: flex; align-items: center; justify-content: center; padding: 1rem; }
        .modal-caja { background: var(--card); border-radius: .625rem; width: 100%; max-width: 24rem;
            padding: 1.25rem; box-shadow: 0 20px 45px rgba(0,0,0,.28); text-align: left; }
        .modal-caja h2 { font-size: 1rem; margin: 0 0 1rem; }
        .modal-caja--ancha { max-width: 30rem; }
        .modal-caja--ancha h2 { margin-bottom: .25rem; }
        .modal-bajada { margin: 0 0 1rem; color: var(--muted); font-size: .8125rem; line-height: 1.45; }
        .modal-acciones { display: flex; gap: .6rem; justify-content: flex-end; margin-top: 1.25rem; flex-wrap: wrap; }
        .modal-acciones form { margin: 0; }
        /* Turnos vigentes dentro del modal de licencia: la caja no crece con
           quien tiene muchos, la tabla se desplaza sola. */
        .modal-licencia__turnos { max-height: 13rem; overflow-y: auto; margin-bottom: 1rem; }
        .modal-licencia__turnos table { font-size: .75rem; }
        .modal-licencia__turnos .paginacion { margin-top: .5rem; }

        /* ===== Selector de turno (modal de asignar turno) =====
           Un turno se reconoce por su horario, no por su nombre, así que en vez
           de un <select> se listan tarjetas con día, entrada y salida a la vista. */
        .modal-caja--turnos { max-width: 38rem; }
        .turno-picker { border: 1px solid var(--border); border-radius: .55rem; background: #fff; overflow: hidden; }
        .turno-picker__buscador { display: flex; align-items: center; gap: .45rem; padding: .5rem .7rem;
            border-bottom: 1px solid var(--border); color: var(--muted); }
        .turno-picker__buscador > svg { width: 1rem; height: 1rem; flex-shrink: 0; }
        .turno-picker__buscador input { flex: 1; min-width: 0; border: 0; outline: 0; padding: .1rem 0;
            background: none; font-family: inherit; font-size: .85rem; color: var(--fg); }
        .turno-picker__buscador input::-webkit-search-cancel-button { display: none; }
        .turno-picker__buscador:focus-within { color: var(--verde); }
        .turno-picker__limpiar { display: inline-flex; background: none; border: 0; cursor: pointer;
            color: var(--muted); padding: .1rem; }
        .turno-picker__limpiar:hover { color: var(--fg); }
        .turno-picker__limpiar svg { width: .95rem; height: .95rem; }
        .turno-picker__dias { display: flex; align-items: center; gap: .3rem; flex-wrap: wrap;
            padding: .55rem .6rem; border-bottom: 1px solid var(--border); background: var(--bg); }
        /* El conteo se va al extremo: dice cuántos quedaron tras filtrar. */
        .turno-picker__conteo { margin-left: auto; font-size: .7rem; color: var(--muted); white-space: nowrap; }
        .chip { border: 1px solid var(--border); background: #fff; border-radius: 999px;
            padding: .22rem .7rem; font-size: .72rem; font-family: inherit; color: var(--muted); cursor: pointer; }
        .chip:hover { border-color: var(--verde); color: var(--verde); }
        .chip--activo { background: var(--verde); border-color: var(--verde); color: #fff; font-weight: 600; }
        .chip--activo:hover { background: var(--verde-osc); border-color: var(--verde-osc); color: #fff; }
        .turno-picker__lista { display: grid; grid-template-columns: repeat(auto-fill, minmax(14.5rem, 1fr));
            gap: .5rem; padding: .6rem; max-height: 17rem; overflow-y: auto; }
        .turno-opcion { display: block; position: relative; padding: .6rem .7rem; cursor: pointer;
            border: 1px solid var(--border); border-radius: .5rem; background: #fff; }
        /* El radio queda oculto pero enfocable: la tarjeta entera es el control. */
        .turno-opcion input { position: absolute; opacity: 0; width: 0; height: 0; }
        .turno-opcion:hover { border-color: var(--verde); background: #f0fdf4; }
        .turno-opcion:has(input:focus-visible) { outline: 2px solid var(--verde); outline-offset: 2px; }
        .turno-opcion--elegido { border-color: var(--verde); background: #f0fdf4;
            box-shadow: inset 0 0 0 1px var(--verde); }
        .turno-opcion__cuerpo { display: flex; flex-direction: column; gap: .25rem; }
        .turno-opcion__titulo { display: flex; align-items: center; gap: .4rem;
            font-size: .8125rem; font-weight: 600; line-height: 1.25; }
        .turno-opcion__dia { flex-shrink: 0; background: var(--bg); border: 1px solid var(--border);
            border-radius: .3rem; padding: .05rem .35rem; font-size: .65rem; letter-spacing: .03em; color: var(--muted); }
        .turno-opcion--elegido .turno-opcion__dia { background: #dcfce7; border-color: #86efac; color: #166534; }
        .turno-opcion__horario { font-size: .95rem; font-weight: 700; color: var(--verde);
            font-variant-numeric: tabular-nums; }
        .turno-opcion__meta { display: flex; flex-wrap: wrap; gap: .35rem .6rem;
            font-size: .7rem; color: var(--muted); }
        .turno-picker__lista .vacio { grid-column: 1 / -1; }

        /* Confirmación de lo elegido, al pie del selector: es el dato que se
           mira antes de dar Asignar. */
        .turno-elegido { display: flex; align-items: center; gap: .45rem; flex-wrap: wrap;
            margin: .55rem 0 0; padding: .5rem .7rem; border-radius: .5rem;
            background: #f0fdf4; border: 1px solid #86efac; color: #166534; font-size: .8125rem; }
        .turno-elegido svg { width: 1rem; height: 1rem; flex-shrink: 0; }

        /* Atajos de rango: evitan tipear las dos fechas en los casos de siempre. */
        .rangos-rapidos { display: flex; gap: .35rem; flex-wrap: wrap; margin: -.35rem 0 .25rem; }
        .rango-chip { border: 1px solid var(--border); background: #fff; border-radius: 999px;
            padding: .22rem .65rem; font-size: .72rem; font-family: inherit; color: var(--muted); cursor: pointer; }
        .rango-chip:hover { border-color: var(--verde); color: var(--verde); background: #f0fdf4; }

        /* Acciones del modal como bloques explicados: cada una dice qué hace, así
           no hay que adivinar la diferencia entre bajar un archivo y grabar en la base. */
        .modal-opciones { display: flex; flex-direction: column; gap: .55rem; margin-top: 1rem; }
        .modal-opciones form { margin: 0; }
        .modal-opcion { display: flex; align-items: flex-start; gap: .7rem; width: 100%; text-align: left;
            padding: .7rem .85rem; border: 1px solid var(--border); border-radius: .55rem;
            background: #fff; color: var(--fg); font-family: inherit; cursor: pointer; }
        .modal-opcion:hover { border-color: var(--verde); background: #f0fdf4; }
        .modal-opcion:disabled { opacity: .6; cursor: default; background: #fff; border-color: var(--border); }
        .modal-opcion--principal { border-color: var(--verde); background: #f0fdf4; }
        .modal-opcion--principal:hover { background: #dcfce7; }
        .modal-opcion__icono { display: inline-flex; margin-top: .1rem; color: var(--verde); }
        .modal-opcion__icono svg { width: 1.2rem; height: 1.2rem; }
        .modal-opcion__titulo { display: block; font-weight: 600; font-size: .85rem; }
        .modal-opcion__ayuda { display: block; color: var(--muted); font-size: .75rem; line-height: 1.35; margin-top: .1rem; }
        .spinner-anillo--verde { border-color: rgba(0,166,90,.3); border-top-color: var(--verde); }

        .modal-pie { display: flex; justify-content: flex-end; margin-top: .9rem; }
        .enlace-cancelar { background: none; border: 0; cursor: pointer; font-family: inherit;
            font-size: .8125rem; color: var(--muted); padding: .25rem .1rem; }
        .enlace-cancelar:hover { color: var(--fg); text-decoration: underline; }
        .enlace-cancelar:disabled { opacity: .5; cursor: default; text-decoration: none; }

        /* ===== Modal global de eliminación: el mismo aviso para todas las
             bajas del sistema, con casilla de confirmación ===== */
        .modal-caja--peligro { max-width: 26rem; text-align: center; }
        .modal-caja--peligro h2 { margin: .8rem 0 .4rem; }
        .modal-caja--peligro form { margin: 0; }
        .modal-peligro__icono { display: inline-flex; align-items: center; justify-content: center;
            width: 3.25rem; height: 3.25rem; border-radius: 50%; background: #fee2e2; color: var(--danger); }
        .modal-peligro__icono svg { width: 1.6rem; height: 1.6rem; }
        .modal-peligro__motivo { text-align: left; margin: 1rem 0 0; }
        .modal-peligro__confirmar { display: flex; align-items: flex-start; gap: .55rem; text-align: left;
            margin-top: 1rem; padding: .65rem .8rem; border: 1px solid var(--border); border-radius: .55rem;
            background: var(--bg); font-size: .8125rem; line-height: 1.4; cursor: pointer; }
        .modal-peligro__confirmar input { width: 1.05rem; height: 1.05rem; margin: .1rem 0 0; flex-shrink: 0; }
        .modal-acciones--centro { justify-content: center; }

        /* ===== Filtros de tabla estilo DataTables: «Mostrar N registros» a la
             izquierda y buscador pill a la derecha, en una fila sobre la tabla ===== */
        .tabla-filtros { display: flex; align-items: center; justify-content: space-between;
            gap: 1rem; flex-wrap: wrap; margin-bottom: 1rem; }
        .tabla-filtros__mostrar { display: flex; align-items: center; gap: .5rem;
            font-size: .8125rem; color: var(--muted); margin: 0; }
        .tabla-filtros__mostrar select { padding: .4rem 1.8rem .4rem .6rem; border: 1px solid var(--border);
            border-radius: .5rem; font-size: .8125rem; background: #fff; color: var(--fg);
            font-family: inherit; cursor: pointer; }
        .buscador { position: relative; flex: 1; max-width: 28rem; min-width: 13rem; margin-left: auto; }
        .buscador svg { position: absolute; left: .85rem; top: 50%; transform: translateY(-50%);
            width: 1.05rem; height: 1.05rem; color: var(--muted); pointer-events: none; }
        .buscador input { width: 100%; padding: .6rem .9rem .6rem 2.4rem; border: 1px solid var(--border);
            border-radius: 9999px; font-size: .85rem; background: #fff; color: var(--fg);
            font-family: inherit; box-shadow: 0 1px 2px rgba(0,0,0,.04); }
        .buscador input:focus { outline: none; border-color: var(--verde);
            box-shadow: 0 0 0 2px rgba(0, 166, 90, .25); }
        .tabla-filtros__extra { display: flex; align-items: center; gap: .6rem; flex-wrap: wrap; }
        .tabla-filtros__extra select, .tabla-filtros__extra input { padding: .45rem .6rem;
            border: 1px solid var(--border); border-radius: .5rem; font-size: .8125rem;
            background: #fff; color: var(--fg); font-family: inherit; }
        /* Filtro con su nombre al lado: un campo de fecha suelto no dice qué filtra. */
        .filtro { display: inline-flex; align-items: center; gap: .35rem;
            font-size: .78rem; color: var(--muted); white-space: nowrap; }

        /* ===== Barra de herramientas: buscador y filtros en caja, con etiqueta arriba ===== */
        .toolbar { display: flex; gap: .9rem; align-items: flex-end; flex-wrap: wrap; margin-bottom: 1rem;
            background: var(--card); border: 1px solid var(--border); border-radius: .625rem; padding: .9rem 1rem; }
        .toolbar .campo { margin-bottom: 0; }
        .toolbar input[type=text].input { min-width: 16rem; }

        /* ===== Formularios ===== */
        .campo { margin-bottom: .9rem; }
        .campo label { display: block; font-weight: 600; font-size: .8rem; margin-bottom: .3rem; }
        .campo input[type=text], .campo input[type=number], .campo input[type=date],
        .campo input[type=email], .campo input[type=password], .campo input[type=time], .campo select,
        .campo textarea,
        .input { width: 100%; padding: .5rem .7rem;
            border: 1px solid var(--border); border-radius: .5rem; font-size: .85rem;
            background: #fff; color: var(--fg); font-family: inherit; }
        .campo textarea { resize: vertical; line-height: 1.4; }
        .campo input:focus, .campo select:focus, .campo textarea:focus, .input:focus { outline: none;
            border-color: var(--verde); box-shadow: 0 0 0 2px rgba(0, 166, 90, .35); }
        .campo input[readonly] { background: var(--bg); color: var(--muted); cursor: default; }
        .campo .req { color: var(--danger); }

        /* Formulario tipo panel: cards por sección en grilla de dos columnas. */
        .form-grid { display: grid; grid-template-columns: 1.4fr 1fr; gap: 1rem;
            align-items: start; }
        .tarjeta { background: var(--card); border: 1px solid var(--border); border-radius: .625rem;
            box-shadow: 0 1px 2px rgba(0,0,0,.05); padding: 1rem 1.25rem; }
        .tarjeta h2 { font-size: .9375rem; margin: 0 0 .85rem; }
        .tarjeta fieldset { border: 0; padding: 0; margin: 0; }
        .tarjeta fieldset[disabled] .campo { opacity: .55; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0 1rem; }
        @media (max-width: 900px) { .form-grid { grid-template-columns: 1fr; } }
        @media (max-width: 640px) { .grid-2 { grid-template-columns: 1fr; } }
        /* Texto secundario (aclaraciones, cargo bajo el nombre, referencias de
           una tabla). Vale suelto y no solo dentro de un `.campo`, que es como
           lo venían usando varias pantallas sin que tuviera estilo. */
        .ayuda { color: var(--muted); font-size: .75rem; }

        /* Enlace al respaldo de una licencia (certificado, memorándum). */
        .respaldo { display: inline-flex; align-items: center; gap: .25rem; margin-left: .4rem;
            font-size: .72rem; color: var(--verde-osc); font-weight: 600; white-space: nowrap; }
        .respaldo svg { width: .85rem; height: .85rem; }
        .respaldo:hover { text-decoration: underline; }
        .campo .ayuda { margin-top: .25rem; }
        .campo .error { color: var(--danger); font-size: .78rem; margin-top: .25rem; }
        .check { display: flex; align-items: center; gap: .5rem; }
        .check input { width: 1.1rem; height: 1.1rem; }
        .form-acciones { display: flex; gap: .6rem; margin-top: 1.25rem; }

        .datos dt { color: var(--muted); font-size: .75rem; text-transform: uppercase; letter-spacing: .03em; }
        .datos dd { margin: .2rem 0 .9rem; font-size: .9rem; font-weight: 500; }

        .vacio { padding: 2.5rem; text-align: center; color: var(--muted); }
        .paginacion { margin-top: 1rem; }

        /* ===== Paginación: misma pinta en todas las tablas (vendor/pagination/custom) ===== */
        .pag { display: flex; align-items: center; justify-content: space-between; gap: 1rem;
            flex-wrap: wrap; font-size: .8125rem; color: var(--muted); }
        .pag__info strong { color: var(--fg); font-weight: 600; }
        .pag__nav { display: flex; gap: .3rem; align-items: center; flex-wrap: wrap; }
        .pag__link { display: inline-flex; align-items: center; justify-content: center;
            min-width: 2rem; height: 2rem; padding: 0 .5rem; border-radius: .4rem;
            font-size: .8125rem; font-weight: 600; border: 1px solid var(--border);
            background: #fff; color: var(--fg); }
        a.pag__link:hover { background: #f3f4f6; }
        .pag__link--activo { background: var(--thead); border-color: var(--thead); color: #fff; }
        .pag__link--disabled { color: #c1c7cf; cursor: not-allowed; }
        .pag__link--puntos { border-color: transparent; background: none; color: var(--muted); }

        /* ===== Escritorio: tarjetas de estadística y mini gráfico ===== */
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1.5rem; }
        /* Fila de tres tarjetas: sin esto la cuarta columna queda vacía y las
           tres se ven angostas y descolgadas a la izquierda. */
        .stats-grid--3 { grid-template-columns: repeat(3, 1fr); }
        @media (max-width: 960px) { .stats-grid, .stats-grid--3 { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 560px) { .stats-grid, .stats-grid--3 { grid-template-columns: 1fr; } }
        .stat-card { background: var(--card); border: 1px solid var(--border); border-radius: .625rem;
            padding: 1rem 1.1rem; box-shadow: 0 1px 2px rgba(0,0,0,.05); }
        .stat-card__valor { font-size: 1.5rem; font-weight: 700; line-height: 1.2; }
        .stat-card__label { font-size: .8rem; color: var(--muted); margin-top: .2rem; }
        .stat-card--success .stat-card__valor { color: #166534; }
        .stat-card--danger .stat-card__valor { color: #991b1b; }
        .stat-card--warning .stat-card__valor { color: #92400e; }
        .stat-card--info .stat-card__valor { color: #1e40af; }

        .mini-chart { display: flex; align-items: flex-end; gap: .3rem; height: 8rem; padding-top: .5rem; }
        .mini-chart__barra { flex: 1; background: var(--verde); border-radius: .2rem .2rem 0 0; min-height: 2px; }
        .mini-chart__ejes { display: flex; justify-content: space-between; color: var(--muted); font-size: .7rem; margin-top: .4rem; }
        /* Las 24 barras del día no entran con la separación del gráfico de
           tendencia: quedan más juntas y más bajas. */
        .mini-chart--horas { height: 6.5rem; gap: .15rem; }
        /* Una hora sin marcaciones no es lo mismo que una con pocas: se dibuja
           apenas insinuada para que el hueco se lea como hueco. */
        .mini-chart__barra--cero { background: var(--border); }
        .mini-chart__barra--pico { background: var(--sidebar); }

        /* ===== Escritorio: tercera línea de la tarjeta, la que interpreta el
           número de arriba («68 en un lunes normal»). Sin esto el valor es un
           dato suelto que nadie sabe si está bien o mal. ===== */
        .stat-card__sub { font-size: .72rem; color: var(--muted); margin-top: .35rem; line-height: 1.35; }

        .escritorio__seccion { font-size: .78rem; font-weight: 700; letter-spacing: .06em;
            text-transform: uppercase; color: var(--muted); margin: 0 0 .75rem; }
        .escritorio__fecha { font-size: .8rem; color: var(--muted); margin-left: auto;
            text-transform: capitalize; }
        .escritorio__aviso { display: flex; align-items: flex-start; gap: .6rem; margin-bottom: 1.5rem; }
        .escritorio__aviso svg { width: 1.2rem; height: 1.2rem; flex-shrink: 0; margin-top: .1rem; }

        /* Dos tarjetas a la par: gráfico ancho + resumen angosto. */
        .escritorio__par { display: grid; grid-template-columns: 2fr 1fr; gap: 1rem; margin-bottom: 1.5rem;
            align-items: start; }
        @media (max-width: 860px) { .escritorio__par { grid-template-columns: 1fr; } }
        .escritorio__tendencia { margin-bottom: 1.5rem; }

        /* Lista de dato/valor de los paneles del escritorio: el número alineado
           a la derecha y en cifras de ancho fijo, para que las columnas de
           dígitos queden a plomo entre filas. */
        .datos-lista { margin: 0; display: flex; flex-direction: column; }
        .datos-lista > div { display: flex; align-items: baseline; justify-content: space-between;
            gap: 1rem; padding: .45rem 0; border-bottom: 1px solid var(--border); }
        .datos-lista > div:last-child { border-bottom: 0; padding-bottom: 0; }
        .datos-lista dt { font-size: .8125rem; color: var(--muted); margin: 0; }
        .datos-lista dd { margin: 0; font-size: .95rem; font-weight: 600;
            font-variant-numeric: tabular-nums; white-space: nowrap; }
        .datos-lista__alerta dd { color: var(--danger); }
        .datos-lista__nota { font-size: .72rem; color: var(--muted); margin: .7rem 0 0; line-height: 1.45; }

        /* ===== Escritorio: el botón de la topbar pliega el menú a una franja
           de iconos (el nombre de cada opción queda en su `title`) ===== */
        @media (min-width: 961px) {
            .zona { transition: margin-left .2s ease; }
            body.sidebar-plegado .sidebar { width: var(--sidebar-rail); }
            body.sidebar-plegado .zona { margin-left: var(--sidebar-rail); }
            body.sidebar-plegado .sidebar__texto,
            body.sidebar-plegado .sidebar__chevron { display: none; }
            body.sidebar-plegado .sidebar__marca { justify-content: center; padding: 1rem .35rem; }
            body.sidebar-plegado .sidebar__nav { padding: .6rem .35rem; }
            body.sidebar-plegado .sidebar__link,
            body.sidebar-plegado .sidebar__sublink,
            body.sidebar-plegado .sidebar__grouptoggle { justify-content: center; padding: .55rem .35rem; gap: 0; }
            /* Un submenú no tiene dónde desplegarse en la franja: se oculta y el
               botón del grupo pasa a abrir el menú entero. */
            body.sidebar-plegado .sidebar__submenu { display: none; }
        }

        /* ===== Móvil: sidebar oculto tras el botón de la topbar ===== */
        @media (max-width: 960px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.abierto { transform: translateX(0); box-shadow: 0 0 0 100vmax rgba(0,0,0,.35); }
            .zona { margin-left: 0; }
        }
    </style>
</head>
{{--
    El botón de la topbar hace dos cosas según el ancho: en móvil abre el menú
    encima del contenido, y en escritorio lo pliega y ensancha la pantalla. La
    decisión de escritorio se recuerda en el navegador, así se mantiene al
    cambiar de pantalla.
--}}
<body x-data="{
        sidebarAbierto: false,
        sidebarPlegado: localStorage.getItem('sidebarPlegado') === '1',
        alternarMenu() {
            if (window.matchMedia('(max-width: 960px)').matches) {
                this.sidebarAbierto = !this.sidebarAbierto;

                return;
            }

            this.sidebarPlegado = !this.sidebarPlegado;
            localStorage.setItem('sidebarPlegado', this.sidebarPlegado ? '1' : '0');
        },
      }"
      :class="{ 'sidebar-plegado': sidebarPlegado }">
    {{-- Alpine se carga con defer: sin esto, un menú plegado se vería un instante desplegado. --}}
    <script>
        if (localStorage.getItem('sidebarPlegado') === '1') { document.body.classList.add('sidebar-plegado'); }
    </script>
    <aside class="sidebar" :class="{ abierto: sidebarAbierto }">
        <a href="{{ route('dashboard') }}" class="sidebar__marca" title="{{ config('app.name') }}">
            <img src="{{ asset('image/icon.png') }}" alt="">
            <span class="sidebar__texto">{{ config('app.name') }}</span>
        </a>
        @php
            // Opción del menú que corresponde a la pantalla actual. Cada una
            // cubre todas las rutas de su módulo (listado, ficha, alta…), así
            // que la marca no se pierde al entrar a una ficha o un formulario.
            $enMenu = [
                'dashboard' => request()->routeIs('dashboard'),
                'funcionarios' => request()->routeIs('funcionarios.*'),
                'marcaciones' => request()->routeIs('marcaciones.*'),
                'dias-excepcionales' => request()->routeIs('dias-excepcionales.*'),
                'horarios' => request()->routeIs('horarios.*'),
                'turnos-asignados' => request()->routeIs('turnos-asignados.*'),
                'licencias' => request()->routeIs('licencias.*'),
                'reportes' => request()->routeIs('reportes.*'),
                'reporte-sin-procesar' => request()->routeIs('reportes.marcaciones.sin-procesar*'),
                'reporte-procesado' => request()->routeIs('reportes.marcaciones.procesado*'),
                'equipos' => request()->routeIs('equipos.*'),
                'usuarios' => request()->routeIs('usuarios.*'),
                'roles' => request()->routeIs('roles.*'),
            ];
            // El grupo «Parámetros» arranca desplegado cuando la pantalla actual
            // es una de las suyas; si no, el ítem activo quedaría escondido.
            $enParametros = $enMenu['dias-excepcionales'] || $enMenu['horarios']
                || $enMenu['turnos-asignados'] || $enMenu['licencias'];
        @endphp

        {{-- El texto de cada opción va en su propio span: plegado queda solo el
             icono, y el `title` lo nombra al pasar el mouse por la franja. --}}
        {{-- Cada opción se muestra solo si el rol puede abrirla: antes se veían
             todas y el 403 aparecía recién al hacer clic. Los grupos se ocultan
             enteros cuando ninguna de sus opciones está permitida. --}}
        @php
            $puedeParametros = auth()->user()?->canAny([
                'ViewAny:DiaExcepcional', 'ViewAny:DiaTurno', 'ViewAny:AsignacionTurno', 'ViewAny:Licencia',
            ]);
        @endphp

        <nav class="sidebar__nav">
            @can('ViewAny:Escritorio')
                <a href="{{ route('dashboard') }}" @class(['sidebar__link', 'activo' => $enMenu['dashboard']]) title="Escritorio"@if ($enMenu['dashboard']) aria-current="page"@endif>
                    <x-heroicon-o-home /><span class="sidebar__texto">Escritorio</span>
                </a>
            @endcan
            @can('ViewAny:Persona')
                <a href="{{ route('funcionarios.index') }}" @class(['sidebar__link', 'activo' => $enMenu['funcionarios']]) title="Funcionarios"@if ($enMenu['funcionarios']) aria-current="page"@endif>
                    <x-heroicon-o-user-group /><span class="sidebar__texto">Funcionarios</span>
                </a>
            @endcan
            @can('ViewAny:Asistencia')
                <a href="{{ route('marcaciones.index') }}" @class(['sidebar__link', 'activo' => $enMenu['marcaciones']]) title="Marcaciones"@if ($enMenu['marcaciones']) aria-current="page"@endif>
                    <x-heroicon-o-finger-print /><span class="sidebar__texto">Marcaciones</span>
                </a>
            @endcan

            {{-- Parámetros: grupo colapsable con las tablas de configuración.
                 Plegado, el botón despliega el menú entero: en una franja de
                 iconos un submenú no tendría dónde mostrarse. --}}
            <div x-data="{ abierto: {{ $enParametros ? 'true' : 'false' }} }" @if (! $puedeParametros) hidden @endif>
                <button type="button" @class(['sidebar__grouptoggle', 'activo' => $enParametros]) title="Parámetros"
                        x-on:click="sidebarPlegado ? (alternarMenu(), abierto = true) : (abierto = !abierto)" :aria-expanded="abierto">
                    <x-heroicon-o-adjustments-horizontal /><span class="sidebar__texto">Parámetros</span>
                    <span class="sidebar__chevron" :style="abierto ? 'transform: rotate(180deg)' : ''"><x-heroicon-o-chevron-down /></span>
                </button>
                <div class="sidebar__submenu" x-show="abierto" x-cloak>
                    @can('ViewAny:DiaExcepcional')
                        <a href="{{ route('dias-excepcionales.index') }}" @class(['sidebar__sublink', 'activo' => $enMenu['dias-excepcionales']]) title="Días excepcionales"@if ($enMenu['dias-excepcionales']) aria-current="page"@endif>
                            <x-heroicon-o-calendar-days /><span class="sidebar__texto">Días excepcionales</span>
                        </a>
                    @endcan
                    @can('ViewAny:DiaTurno')
                        <a href="{{ route('horarios.index') }}" @class(['sidebar__sublink', 'activo' => $enMenu['horarios']]) title="Turnos"@if ($enMenu['horarios']) aria-current="page"@endif>
                            <x-heroicon-o-clock /><span class="sidebar__texto">Turnos</span>
                        </a>
                    @endcan
                    @can('ViewAny:AsignacionTurno')
                        <a href="{{ route('turnos-asignados.index') }}" @class(['sidebar__sublink', 'activo' => $enMenu['turnos-asignados']]) title="Turnos asignados"@if ($enMenu['turnos-asignados']) aria-current="page"@endif>
                            <x-heroicon-o-user-group /><span class="sidebar__texto">Turnos asignados</span>
                        </a>
                    @endcan
                    @can('ViewAny:Licencia')
                        <a href="{{ route('licencias.index') }}" @class(['sidebar__sublink', 'activo' => $enMenu['licencias']]) title="Licencias"@if ($enMenu['licencias']) aria-current="page"@endif>
                            <x-heroicon-o-clipboard-document-check /><span class="sidebar__texto">Licencias</span>
                        </a>
                    @endcan
                </div>
            </div>
            {{-- Reportes: mismo patrón colapsable que «Parámetros». --}}
            <div x-data="{ abierto: {{ $enMenu['reportes'] ? 'true' : 'false' }} }" @cannot('ViewAny:Reporte') hidden @endcannot>
                <button type="button" @class(['sidebar__grouptoggle', 'activo' => $enMenu['reportes']]) title="Reportes"
                        x-on:click="sidebarPlegado ? (alternarMenu(), abierto = true) : (abierto = !abierto)" :aria-expanded="abierto">
                    <x-heroicon-o-document-chart-bar /><span class="sidebar__texto">Reportes</span>
                    <span class="sidebar__chevron" :style="abierto ? 'transform: rotate(180deg)' : ''"><x-heroicon-o-chevron-down /></span>
                </button>
                <div class="sidebar__submenu" x-show="abierto" x-cloak>
                    <a href="{{ route('reportes.marcaciones.sin-procesar') }}" @class(['sidebar__sublink', 'activo' => $enMenu['reporte-sin-procesar']]) title="Marcaciones sin procesar"@if ($enMenu['reporte-sin-procesar']) aria-current="page"@endif>
                        <x-heroicon-o-finger-print /><span class="sidebar__texto">Sin procesar</span>
                    </a>
                    <a href="{{ route('reportes.marcaciones.procesado') }}" @class(['sidebar__sublink', 'activo' => $enMenu['reporte-procesado']]) title="Marcaciones procesadas"@if ($enMenu['reporte-procesado']) aria-current="page"@endif>
                        <x-heroicon-o-clipboard-document-list /><span class="sidebar__texto">Procesado</span>
                    </a>
                </div>
            </div>
            @can('ViewAny:Equipo')
                <a href="{{ route('equipos.index') }}" @class(['sidebar__link', 'activo' => $enMenu['equipos']]) title="Biométricos"@if ($enMenu['equipos']) aria-current="page"@endif>
                    <x-heroicon-o-computer-desktop /><span class="sidebar__texto">Biométricos</span>
                </a>
            @endcan
            @can('ViewAny:User')
                <a href="{{ route('usuarios.index') }}" @class(['sidebar__link', 'activo' => $enMenu['usuarios']]) title="Usuarios"@if ($enMenu['usuarios']) aria-current="page"@endif>
                    <x-heroicon-o-user /><span class="sidebar__texto">Usuarios</span>
                </a>
            @endcan
            @can('ViewAny:Role')
                <a href="{{ route('roles.index') }}" @class(['sidebar__link', 'activo' => $enMenu['roles']]) title="Roles"@if ($enMenu['roles']) aria-current="page"@endif>
                    <x-heroicon-o-shield-check /><span class="sidebar__texto">Roles</span>
                </a>
            @endcan


        </nav>

        {{-- Si el menú quedó con scroll, la opción activa se trae a la vista. --}}
        <script>
            document.querySelector('.sidebar [aria-current="page"]')?.scrollIntoView({ block: 'nearest' });
        </script>
    </aside>

    <div class="zona">
        <header class="topbar">
            <button type="button" class="topbar__toggle" x-on:click="alternarMenu()"
                    :aria-expanded="window.matchMedia('(max-width: 960px)').matches ? sidebarAbierto : !sidebarPlegado"
                    aria-label="Mostrar u ocultar el menú" title="Mostrar u ocultar el menú">
                <x-heroicon-o-bars-3 />
            </button>
            <span></span>

            {{-- Solicitudes de licencia esperando decisión. Solo aparece si hay
                 alguna: un cero permanente deja de mirarse a los dos días. El
                 número son solicitudes, no filas —un pedido de cinco días es
                 uno—, y el enlace lleva al listado ya filtrado. --}}
            @if (($licenciasPendientes ?? 0) > 0)
                <a href="{{ route('licencias.index', ['estado' => \App\Models\Licencia::PENDIENTE]) }}"
                   class="aviso-pendientes"
                   title="{{ $licenciasPendientes }} solicitud(es) de licencia esperando tu decisión">
                    <x-heroicon-o-clipboard-document-check />
                    <span class="aviso-pendientes__texto">Licencias pendientes</span>
                    <span class="aviso-pendientes__conteo">{{ $licenciasPendientes }}</span>
                </a>
            @endif

            {{-- Menú de la cuenta: quién está usando el sistema y cómo salir. --}}
            @auth
                @php
                    $usuarioActual = auth()->user();
                    $inicialesUsuario = collect(preg_split('/\s+/', trim((string) $usuarioActual->name), -1, PREG_SPLIT_NO_EMPTY))
                        ->take(2)
                        ->map(fn (string $parte): string => mb_strtoupper(mb_substr($parte, 0, 1)))
                        ->implode('') ?: '?';
                @endphp
                <div class="cuenta" x-data="{ abierto: false }" x-on:keydown.escape.window="abierto = false">
                    <button type="button" class="cuenta__boton" x-on:click="abierto = !abierto" :aria-expanded="abierto">
                        <span class="cuenta__avatar">@if ($usuarioActual->avatar_path)<img src="{{ asset('storage/'.$usuarioActual->avatar_path) }}" alt="">@else{{ $inicialesUsuario }}@endif</span>
                        <span class="cuenta__nombre">{{ $usuarioActual->name }}</span>
                        <span class="cuenta__chevron" :style="abierto ? 'transform: rotate(180deg)' : ''"><x-heroicon-o-chevron-down /></span>
                    </button>

                    <div class="cuenta__menu" x-show="abierto" x-cloak x-on:click.outside="abierto = false">
                        <div class="cuenta__ficha">
                            <div class="cuenta__ficha-nombre">{{ $usuarioActual->name }}</div>
                            <div class="cuenta__ficha-dato">{{ $usuarioActual->email }}</div>
                            @if ($usuarioActual->roles->isNotEmpty())
                                <div class="cuenta__ficha-dato">{{ $usuarioActual->roles->pluck('name')->implode(', ') }}</div>
                            @endif
                        </div>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="cuenta__salir">
                                <x-heroicon-o-arrow-right-on-rectangle />Cerrar sesión
                            </button>
                        </form>
                    </div>
                </div>
            @endauth
        </header>

        <main class="contenedor">
            @yield('contenido')
        </main>
    </div>

    {{--
        Modal global de eliminación. Los botones <x-boton-eliminar> no envían
        nada por su cuenta: cargan aquí la URL del destroy y el texto de lo que
        se va a borrar, y este único formulario manda el DELETE. El estado vive
        en un store de Alpine (no en un x-data) para que también lo puedan abrir
        los botones de las tablas que se inyectan por AJAX.
    --}}
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.store('eliminar', {
                abierto: false,
                url: '',
                mensaje: '',
                motivo: '',
                confirmado: false,
                enviando: false,
                // Mismo mínimo que valida el servidor: un motivo de una palabra
                // no le sirve a nadie cuando después se revisa por qué se borró.
                motivoMinimo: 5,
                // `ancla` es la solapa desde la que se borró: el controlador la
                // usa para devolver al usuario ahí y no a la primera.
                ancla: '',
                abrir(url, mensaje, ancla = '') {
                    this.url = url;
                    this.mensaje = mensaje;
                    this.ancla = ancla;
                    this.motivo = '';
                    this.confirmado = false;
                    this.enviando = false;
                    this.abierto = true;
                },
                get motivoValido() {
                    return this.motivo.trim().length >= this.motivoMinimo;
                },
                cerrar() {
                    // Ya salió el DELETE: cerrar dejaría creer que no pasó nada.
                    if (this.enviando) { return; }

                    this.abierto = false;
                },
            });
        });
    </script>
    <div class="modal-fondo" x-data x-show="$store.eliminar.abierto" x-cloak
         x-on:click.self="$store.eliminar.cerrar()"
         x-on:keydown.escape.window="$store.eliminar.cerrar()"
         role="dialog" aria-modal="true" aria-labelledby="titulo-eliminar-global">
        <div class="modal-caja modal-caja--peligro">
            <span class="modal-peligro__icono"><x-heroicon-o-trash /></span>
            <h2 id="titulo-eliminar-global">¿Seguro que querés eliminar?</h2>
            <p class="modal-bajada" x-text="$store.eliminar.mensaje"></p>

            <form method="POST" id="form-eliminar-global" :action="$store.eliminar.url"
                  x-on:submit="$store.eliminar.enviando = true">
                @csrf
                @method('DELETE')
                <input type="hidden" name="ancla" :value="$store.eliminar.ancla">
                {{-- El motivo viaja como `deleteObservacion`: el trait
                     RegistersUserEvents lo graba en la fila junto con el usuario
                     que dio la baja, antes de que SoftDeletes marque deleted_at. --}}
                <div class="campo modal-peligro__motivo">
                    <label for="motivo-eliminar-global">¿Por qué se elimina? <span class="req">*</span></label>
                    <textarea id="motivo-eliminar-global" name="deleteObservacion" rows="2" required
                              minlength="5" maxlength="500" x-model="$store.eliminar.motivo"
                              placeholder="Ej.: cargado por error, ya no corresponde al funcionario"></textarea>
                    <p class="ayuda">Queda guardado con la baja: es lo que se lee después para saber qué pasó.</p>
                </div>
                <label class="modal-peligro__confirmar">
                    <input type="checkbox" required x-model="$store.eliminar.confirmado">
                    <span>Sí, confirmo la eliminación. Esta acción no se puede deshacer.</span>
                </label>
                <div class="modal-acciones modal-acciones--centro">
                    <button type="button" class="btn btn--gris" x-on:click="$store.eliminar.cerrar()"
                            :disabled="$store.eliminar.enviando">Cancelar</button>
                    <button type="submit" class="btn btn--peligro"
                            :disabled="! $store.eliminar.confirmado || ! $store.eliminar.motivoValido || $store.eliminar.enviando">
                        <span class="btn__contenido" x-show="! $store.eliminar.enviando"><x-heroicon-o-trash />Sí, eliminar</span>
                        <span class="btn__contenido" x-show="$store.eliminar.enviando" x-cloak><span class="spinner-anillo"></span>Eliminando…</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{--
        Modal global para concluir una asignación de turno. Mismo mecanismo que
        el de eliminación (store de Alpine + un solo formulario), porque los
        botones también viajan en tablas que se cargan por AJAX.

        Concluir no borra: le pone fecha de fin a la asignación, así el turno
        deja de estar vigente pero sigue explicando la historia del funcionario.
    --}}
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.store('concluir', {
                abierto: false,
                url: '',
                mensaje: '',
                hasta: '',
                origen: '',
                enviando: false,
                abrir(url, mensaje, hasta, origen = '') {
                    this.url = url;
                    this.mensaje = mensaje;
                    this.hasta = hasta;
                    this.origen = origen;
                    this.enviando = false;
                    this.abierto = true;
                },
                cerrar() {
                    if (this.enviando) { return; }

                    this.abierto = false;
                },
            });
        });
    </script>
    <div class="modal-fondo" x-data x-show="$store.concluir.abierto" x-cloak
         x-on:click.self="$store.concluir.cerrar()"
         x-on:keydown.escape.window="$store.concluir.cerrar()"
         role="dialog" aria-modal="true" aria-labelledby="titulo-concluir-global">
        <div class="modal-caja">
            <h2 id="titulo-concluir-global">Concluir el turno</h2>
            <p class="modal-bajada" x-text="$store.concluir.mensaje"></p>

            <form method="POST" :action="$store.concluir.url" x-on:submit="$store.concluir.enviando = true">
                @csrf
                @method('PATCH')
                <input type="hidden" name="origen" :value="$store.concluir.origen">
                <div class="campo">
                    <label for="hasta-concluir-global">Vigente hasta <span class="req">*</span></label>
                    <input type="date" id="hasta-concluir-global" name="hasta" required
                           x-model="$store.concluir.hasta">
                    <p class="ayuda">
                        Desde el día siguiente el funcionario deja de tener este turno.
                        La asignación no se borra: queda como historia.
                    </p>
                </div>
                <div class="modal-acciones">
                    <button type="button" class="btn btn--gris" x-on:click="$store.concluir.cerrar()"
                            :disabled="$store.concluir.enviando">
                        <x-heroicon-o-x-mark />Cancelar
                    </button>
                    <button type="submit" class="btn" :disabled="$store.concluir.enviando">
                        <span class="btn__contenido" x-show="! $store.concluir.enviando"><x-heroicon-o-check />Concluir</span>
                        <span class="btn__contenido" x-show="$store.concluir.enviando" x-cloak><span class="spinner-anillo"></span>Concluyendo…</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- Toaster global: cualquier acción que redirija con flash 'estado'
         (éxito) o 'error' aparece aquí. El éxito se oculta solo; el error
         queda hasta que el usuario lo cierra. --}}
    @if (session('estado') || session('error') || $errors->has('deleteObservacion'))
        <div class="toaster" aria-live="polite">
            {{-- El motivo de la baja se pide en un modal, no en una pantalla con
                 sus errores: si el servidor lo rechaza, se avisa acá. --}}
            @error('deleteObservacion')
                <div class="toast toast--error" x-data="{ show: true }" x-show="show" x-cloak
                     x-transition.opacity.duration.200ms>
                    <x-heroicon-o-exclamation-triangle />
                    <div class="toast__cuerpo">{{ $message }}</div>
                    <button type="button" class="toast__cerrar" x-on:click="show = false" aria-label="Cerrar">&times;</button>
                </div>
            @enderror
            @if (session('estado'))
                <div class="toast toast--ok" x-data="{ show: true }" x-show="show" x-cloak
                     x-init="setTimeout(() => show = false, 5000)"
                     x-transition.opacity.duration.200ms>
                    <x-heroicon-o-check-circle />
                    <div class="toast__cuerpo">{{ session('estado') }}</div>
                    <button type="button" class="toast__cerrar" x-on:click="show = false" aria-label="Cerrar">&times;</button>
                </div>
            @endif
            @if (session('error'))
                <div class="toast toast--error" x-data="{ show: true }" x-show="show" x-cloak
                     x-transition.opacity.duration.200ms>
                    <x-heroicon-o-exclamation-triangle />
                    <div class="toast__cuerpo">{{ session('error') }}</div>
                    <button type="button" class="toast__cerrar" x-on:click="show = false" aria-label="Cerrar">&times;</button>
                </div>
            @endif
        </div>
    @endif
</body>
</html>
