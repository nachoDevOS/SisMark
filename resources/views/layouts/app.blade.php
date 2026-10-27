<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('image/huella.png') }}">
    <title>{{ app()->environment('local') ? '[LOCAL] ' : '' }}@yield('titulo', 'Biométricos') · {{ config('app.name') }}</title>
    {{-- Alpine.js autohospedado (sin CDN externo, sin build): para el dropdown
         "Mas" de las acciones de fila y el toggle del sidebar en móvil. --}}
    <script defer src="{{ asset('vendor/alpine/alpine.min.js') }}"></script>

    {{--
        Sesión vencida durante un AJAX.

        Todas las tablas del sistema se cargan con `fetch` y meten la respuesta
        en el contenedor con `innerHTML`. Si la sesión venció, esa respuesta ya
        no son los datos: dejada pasar, el formulario de ingreso queda dibujado
        adentro de la tabla, con el sidebar y el nombre del usuario todavía en
        pantalla. Parece un error del listado y no lo es.

        Se envuelve `fetch` una sola vez acá, en el `<head>` y sin `defer`, para
        que quede instalado antes de que corra el script de cualquier pantalla.
        Son diecisiete llamadas repartidas en catorce vistas: atajarlas en el
        origen las cubre a todas, y también a las que se escriban después.

        Se recarga la pantalla actual en vez de saltar directo al login: la
        recarga pega contra el middleware `auth`, que guarda a dónde se quería
        ir y devuelve al usuario justo acá apenas vuelve a entrar.
    --}}
    <script>
        (function () {
            const original = window.fetch;
            let redirigiendo = false;

            window.fetch = async function (...args) {
                const resp = await original.apply(this, args);

                // 401: la sesión venció (el servidor responde JSON a los AJAX).
                // 419: la página quedó vieja y su token de CSRF ya no sirve.
                if (resp.status !== 401 && resp.status !== 419) {
                    return resp;
                }

                if (!redirigiendo) {
                    redirigiendo = true;
                    window.location.reload();
                }

                // La recarga tarda un instante y quien llamó va a pintar esto
                // igual. Se le devuelve un aviso en vez del JSON del error,
                // que no significa nada para quien lo lee.
                return new Response(
                    '<div class="vacio">La sesión expiró. Redirigiendo al ingreso…</div>',
                    { status: resp.status, headers: { 'Content-Type': 'text/html' } },
                );
            };
        })();
    </script>
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

        /* ===== Menú de la cuenta (topbar, a la derecha) ===== */
        .cuenta { position: relative; }
        .cuenta__boton { display: inline-flex; align-items: center; gap: .55rem; cursor: pointer;
            background: none; border: 1px solid transparent; border-radius: 2rem; padding: .25rem .6rem .25rem .25rem;
            font-family: inherit; font-size: .8125rem; color: var(--fg); }
        .cuenta__boton:hover { background: var(--bg); border-color: var(--border); }
        .cuenta__avatar { display: inline-flex; align-items: center; justify-content: center; overflow: hidden;
            width: 2rem; height: 2rem; border-radius: 50%; background: var(--bg); color: #fff;
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
        .cuenta__opcion { display: flex; align-items: center; gap: .55rem; padding: .7rem .9rem;
            font-size: .8125rem; font-weight: 600; color: var(--fg); border-bottom: 1px solid var(--border); }
        .cuenta__opcion:hover { background: var(--bg); }
        .cuenta__opcion svg { width: 1.05rem; height: 1.05rem; }
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

        /* ===== Actividad de usuarios ===== */
        .actividad-filtros { margin-bottom: 1rem; }
        .actividad-filtros__fila { display: grid; grid-template-columns: repeat(auto-fill, minmax(11rem, 1fr)); gap: 0 .9rem; }
        .actividad-filtros__fila--pie { display: flex; flex-wrap: wrap; align-items: center; gap: .6rem 1.25rem; margin-top: .4rem; }
        .actividad-funcionario { display: flex; align-items: center; gap: .3rem; margin-top: .2rem; }
        .actividad-funcionario svg { width: .85rem; height: .85rem; flex-shrink: 0; }
        .actividad-filtros__fila--pie .check { display: inline-flex; align-items: center; gap: .4rem; font-size: .8125rem; }
        .actividad-vista { display: flex; align-items: center; gap: .3rem; margin-bottom: .75rem; }
        .actividad-vista a { padding: .35rem .85rem; border-radius: 9999px; font-size: .8125rem; font-weight: 600;
            color: var(--muted); background: var(--card); border: 1px solid var(--border); }
        .actividad-vista a:hover { color: var(--fg); }
        .actividad-vista a.activo { background: var(--thead); border-color: var(--thead); color: #fff; }
        .actividad-vista .persona-meta { margin-left: auto; }
        .actividad-url { font-family: ui-monospace, monospace; font-size: .75rem; word-break: break-all; }
        .actividad-fila--fallida td { background: #fef2f2; }
        .actividad-detalle td { background: var(--bg); }
        .actividad-datos { margin: 0; padding: .6rem .75rem; max-height: 14rem; overflow: auto; border-radius: .4rem;
            background: var(--card); border: 1px solid var(--border); font-size: .75rem; white-space: pre-wrap; word-break: break-word; }

        /* ===== Ayuda: botón de la topbar y su ventana de tutoriales ===== */
        /* Se pega a la derecha como el aviso de pendientes; si están los dos,
           el aviso deja de empujar, o el espacio libre se repartiría entre ambos. */
        .ayuda { margin-left: auto; }
        .ayuda + .aviso-pendientes { margin-left: 0; }
        /* Verde lleno: es lo primero que tiene que encontrar quien no sabe cómo seguir. */
        .ayuda__boton { display: inline-flex; align-items: center; gap: .4rem; cursor: pointer;
            border: 0; border-radius: 9999px; padding: .45rem .95rem .45rem .7rem;
            background: var(--verde); color: #fff; font-family: inherit; font-size: .8125rem; font-weight: 700;
            box-shadow: 0 2px 8px rgba(0, 166, 90, .35); transition: background .15s;
            animation: ayuda-tintineo 1.6s ease-in-out infinite; }
        /* Al apuntarlo deja de tintinear: ya se lo encontró. */
        .ayuda__boton:hover { background: var(--verde-osc); animation-play-state: paused; }
        .ayuda__boton:focus-visible { outline: 2px solid var(--verde-osc); outline-offset: 2px; }
        .ayuda__boton svg { width: 1.25rem; height: 1.25rem; }
        /* Tintinea como el conteo de licencias pendientes, en verde. `transform` y
           `box-shadow` no reflowean: la barra no se mueve. */
        @keyframes ayuda-tintineo {
            0%, 100% { transform: scale(1); box-shadow: 0 2px 8px rgba(0, 166, 90, .35), 0 0 0 0 rgba(0, 166, 90, .55); }
            50%      { transform: scale(1.08); box-shadow: 0 2px 8px rgba(0, 166, 90, .35), 0 0 0 .45rem rgba(0, 166, 90, 0); }
        }

        .ayuda__fondo { background: rgba(2, 6, 23, .7); backdrop-filter: blur(6px); animation: ayuda-aparecer .2s ease-out; }
        .ayuda__caja { width: 100%; max-width: 72rem; overflow: hidden; border-radius: 1rem; background: var(--card);
            border: 1px solid rgba(255,255,255,.1); box-shadow: 0 25px 60px rgba(0,0,0,.45);
            animation: ayuda-acercar .3s cubic-bezier(.2, .8, .2, 1); }
        .ayuda__cabeza { display: flex; align-items: center; gap: 1rem; padding: 1rem 1.4rem; color: #fff;
            background: radial-gradient(520px 180px at 92% 0%, rgba(0, 166, 90, .35), transparent 70%),
                        linear-gradient(120deg, var(--sidebar) 0%, var(--sidebar-header) 75%); }
        .ayuda__icono { display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0;
            width: 2.75rem; height: 2.75rem; border-radius: 50%; background: rgba(255,255,255,.1);
            box-shadow: 0 0 0 2px rgba(255,255,255,.2); color: #6ee7b7; }
        .ayuda__icono svg { width: 1.35rem; height: 1.35rem; }
        .ayuda__icono--boton { border: 0; cursor: pointer; color: #fff; transition: background .15s; }
        .ayuda__icono--boton:hover { background: rgba(255,255,255,.2); }
        .ayuda__cabeza-texto { flex: 1; min-width: 0; }
        .ayuda__cabeza h2 { margin: 0; font-size: 1.1rem; line-height: 1.25; }
        .ayuda__cabeza p { margin: .15rem 0 0; font-size: .8125rem; color: rgba(255,255,255,.7);
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .ayuda__contador { display: inline-flex; align-items: center; gap: .35rem; padding: .25rem .7rem; border-radius: 9999px;
            background: rgba(255,255,255,.1); box-shadow: inset 0 0 0 1px rgba(255,255,255,.2); font-size: .75rem; font-weight: 600; }
        .ayuda__contador svg { width: .9rem; height: .9rem; color: #6ee7b7; }
        .ayuda__cerrar { display: inline-flex; background: none; border: 0; cursor: pointer; padding: .45rem;
            border-radius: 50%; color: rgba(255,255,255,.7); transition: background .15s, color .15s; }
        .ayuda__cerrar:hover { background: rgba(255,255,255,.15); color: #fff; }
        .ayuda__cerrar svg { width: 1.3rem; height: 1.3rem; }
        .ayuda__cuerpo { max-height: calc(100vh - 9rem); max-height: calc(100dvh - 9rem); overflow-y: auto;
            overscroll-behavior: contain; padding: 1.4rem; }

        /* Biblioteca */
        .ayuda__consejo { display: flex; align-items: flex-start; gap: .6rem; margin: 0 0 1.25rem; padding: .75rem 1rem;
            border-radius: .75rem; background: #fffbeb; color: #78350f; font-size: .8125rem; }
        .ayuda__consejo svg { width: 1.05rem; height: 1.05rem; flex-shrink: 0; margin-top: .05rem; color: #f59e0b; }
        .ayuda__biblioteca { display: grid; grid-template-columns: repeat(auto-fill, minmax(17rem, 1fr)); gap: 1.25rem; }
        .ayuda-tarjeta { display: flex; flex-direction: column; overflow: hidden; padding: 0; cursor: pointer; text-align: left;
            font-family: inherit; color: var(--fg); background: var(--card); border: 1px solid var(--border); border-radius: .85rem;
            box-shadow: 0 1px 2px rgba(0,0,0,.05); transition: transform .3s, box-shadow .3s, border-color .3s;
            animation: ayuda-subir .45s cubic-bezier(.2, .8, .2, 1) both; }
        .ayuda-tarjeta:hover { transform: translateY(-.25rem); border-color: rgba(0, 166, 90, .45);
            box-shadow: 0 18px 35px rgba(0,0,0,.14); }
        .ayuda-tarjeta:focus-visible { outline: 2px solid var(--verde); outline-offset: 2px; }
        .ayuda-tarjeta__imagen { position: relative; display: block; aspect-ratio: 16 / 9; overflow: hidden; background: var(--bg); }
        .ayuda-tarjeta__imagen img { width: 100%; height: 100%; object-fit: cover; }
        .ayuda-tarjeta__previa { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover;
            opacity: 0; transition: opacity .7s, transform .7s; }
        .ayuda-tarjeta__previa--andando { opacity: 1; }
        .ayuda-tarjeta:hover .ayuda-tarjeta__previa { transform: scale(1.05); }
        .ayuda-tarjeta__sombra { position: absolute; inset: 0;
            background: linear-gradient(to top, rgba(2,6,23,.7), rgba(2,6,23,.1) 45%, transparent); }
        .ayuda-tarjeta__modulo, .ayuda-tarjeta__pasos, .ayuda-tarjeta__vivo, .ayuda-tarjeta__visto {
            position: absolute; display: inline-flex; align-items: center; gap: .3rem; padding: .15rem .55rem;
            border-radius: 9999px; font-size: .6875rem; font-weight: 600; }
        .ayuda-tarjeta__modulo { top: .6rem; left: .6rem; background: #e0f2fe; color: #075985; }
        .ayuda-tarjeta__pasos { top: .6rem; right: .6rem; background: rgba(2,6,23,.6); color: #fff; backdrop-filter: blur(4px); }
        .ayuda-tarjeta__pasos svg { width: .75rem; height: .75rem; }
        .ayuda-tarjeta__vivo { right: .6rem; bottom: .6rem; background: rgba(2,6,23,.65); color: #fff;
            backdrop-filter: blur(4px); font-weight: 500; animation: ayuda-aparecer .3s; }
        .ayuda-tarjeta__visto { left: .6rem; bottom: .6rem; background: var(--verde); color: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.3); }
        .ayuda-tarjeta__visto svg { width: .8rem; height: .8rem; }
        .ayuda-tarjeta__vuelta, .ayuda-tarjeta__avance { position: absolute; inset: auto 0 0 0; height: 2px; background: rgba(255,255,255,.2); }
        .ayuda-tarjeta__avance { height: 4px; background: rgba(255,255,255,.25); }
        .ayuda-tarjeta__vuelta span, .ayuda-tarjeta__avance span { display: block; height: 100%; background: #6ee7b7; }
        .ayuda-tarjeta__play { position: absolute; inset: 0; margin: auto; display: flex; align-items: center; justify-content: center;
            width: 3.5rem; height: 3.5rem; border-radius: 50%; background: rgba(255,255,255,.9); color: var(--verde-osc);
            box-shadow: 0 8px 20px rgba(0,0,0,.3); transition: transform .3s, background .3s; }
        .ayuda-tarjeta__play svg { width: 1.5rem; height: 1.5rem; margin-left: .15rem; }
        .ayuda-tarjeta__play::before { content: ''; position: absolute; inset: 0; border-radius: 50%; background: rgba(255,255,255,.5); opacity: 0; }
        .ayuda-tarjeta:hover .ayuda-tarjeta__play { transform: scale(1.1); background: #fff; }
        .ayuda-tarjeta:hover .ayuda-tarjeta__play::before { animation: ayuda-latido 1s cubic-bezier(0, 0, .2, 1) infinite; }
        .ayuda-tarjeta__texto { display: flex; flex: 1; flex-direction: column; gap: .35rem; padding: 1rem; }
        .ayuda-tarjeta__texto strong { font-size: .9rem; line-height: 1.3; transition: color .2s; }
        .ayuda-tarjeta:hover .ayuda-tarjeta__texto strong { color: var(--verde-osc); }
        .ayuda-tarjeta__descripcion { font-size: .8rem; color: var(--muted); line-height: 1.45;
            display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .ayuda-tarjeta__accion { display: inline-flex; align-items: center; gap: .35rem; margin-top: auto; padding-top: .4rem;
            font-size: .8125rem; font-weight: 600; color: var(--verde-osc); }
        .ayuda-tarjeta__accion svg { width: .85rem; height: .85rem; }
        .ayuda-ecualizador { display: inline-flex; align-items: flex-end; gap: 1px; height: .6rem; }
        .ayuda-ecualizador i { width: 2px; height: 100%; border-radius: 9999px; background: #6ee7b7;
            transform-origin: bottom; animation: ayuda-ecualizador .9s ease-in-out infinite; }
        .ayuda-ecualizador i:nth-child(2) { animation-delay: .25s; }
        .ayuda-ecualizador i:nth-child(3) { animation-delay: .5s; }
        /* Sin vista previa (o con menos movimiento pedido) la portada se pasea despacio. */
        .ayuda-paneo { animation: ayuda-paneo 14s ease-in-out infinite alternate; }

        /* Reproductor */
        .ayuda-reproductor { display: grid; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); gap: 1.5rem;
            animation: ayuda-deslizar .3s ease-out; }
        .ayuda-reproductor__principal { display: flex; flex-direction: column; gap: .9rem; min-width: 0; }
        .ayuda-reproductor__pantalla { position: relative; overflow: hidden; border-radius: .75rem; background: #000;
            box-shadow: 0 10px 25px rgba(0,0,0,.25); }
        .ayuda-reproductor__pantalla video { display: block; width: 100%; aspect-ratio: 16 / 9; }
        .ayuda-reproductor__titulo { display: flex; align-items: center; flex-wrap: wrap; gap: .5rem; }
        .ayuda-reproductor__titulo h3 { margin: 0; font-size: 1.05rem; }
        .ayuda-reproductor__principal .persona-meta { margin: -.5rem 0 0; font-size: .8125rem; }
        .ayuda-retomado { position: absolute; top: .75rem; left: 50%; transform: translateX(-50%);
            display: flex; align-items: center; gap: .75rem; padding: .35rem .35rem .35rem 1rem; border-radius: 9999px;
            background: rgba(15,23,42,.9); color: #fff; font-size: .8125rem; white-space: nowrap;
            box-shadow: 0 8px 20px rgba(0,0,0,.35); animation: ayuda-bajar .3s ease-out; }
        .ayuda-retomado button { display: inline-flex; align-items: center; gap: .3rem; border: 0; cursor: pointer;
            padding: .3rem .75rem; border-radius: 9999px; background: rgba(255,255,255,.15); color: #fff;
            font: inherit; font-size: .75rem; font-weight: 600; }
        .ayuda-retomado button:hover { background: rgba(255,255,255,.25); }
        .ayuda-retomado svg { width: .85rem; height: .85rem; }
        .ayuda-final { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
            background: rgba(2,6,23,.75); backdrop-filter: blur(3px); animation: ayuda-aparecer .3s; }
        .ayuda-final__caja { max-width: 22rem; margin: 0 1rem; padding: 1.5rem; border-radius: 1rem; text-align: center;
            background: var(--card); box-shadow: 0 25px 50px rgba(0,0,0,.4); animation: ayuda-acercar .3s; }
        .ayuda-final__icono { display: inline-flex; align-items: center; justify-content: center; width: 3.5rem; height: 3.5rem;
            border-radius: 50%; background: #dcfce7; color: var(--verde-osc); }
        .ayuda-final__icono svg { width: 1.75rem; height: 1.75rem; }
        .ayuda-final__titulo { margin: .75rem 0 0; font-size: 1.05rem; font-weight: 700; }
        .ayuda-final__texto { margin: .3rem 0 0; font-size: .8125rem; color: var(--muted); }
        .ayuda-final__botones { display: flex; flex-wrap: wrap; justify-content: center; gap: .5rem; margin-top: 1.1rem; }
        .ayuda-tramos { display: flex; gap: .25rem; height: .6rem; }
        .ayuda-tramos__tramo { position: relative; flex-basis: 0; overflow: hidden; padding: 0; border: 0; cursor: pointer;
            border-radius: 9999px; background: var(--border); transition: transform .15s; }
        .ayuda-tramos__tramo:hover { transform: scaleY(1.5); }
        .ayuda-tramos__tramo.activo { box-shadow: 0 0 0 2px rgba(0, 166, 90, .3); }
        .ayuda-tramos__tramo span { position: absolute; inset: 0 auto 0 0; border-radius: 9999px; background: var(--verde);
            transition: width .3s; }
        .ayuda-controles { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem; }
        .ayuda-controles__pasos { display: flex; gap: .5rem; }
        .ayuda-controles .btn:disabled { opacity: .45; cursor: not-allowed; }
        .ayuda-velocidad { display: inline-flex; align-items: center; gap: .2rem; padding: .25rem; border-radius: 9999px; background: var(--bg); }
        .ayuda-velocidad svg { width: 1rem; height: 1rem; margin: 0 .35rem; color: var(--muted); }
        .ayuda-velocidad button { border: 0; cursor: pointer; padding: .2rem .6rem; border-radius: 9999px; background: none;
            font: inherit; font-size: .75rem; font-weight: 600; color: var(--muted); font-variant-numeric: tabular-nums; }
        .ayuda-velocidad button:hover { color: var(--fg); }
        .ayuda-velocidad button.activo { background: var(--card); color: var(--fg); box-shadow: 0 1px 3px rgba(0,0,0,.12); }
        .ayuda-lista__cabeza { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: .6rem; padding: 0 .25rem;
            font-size: .72rem; color: var(--muted); font-variant-numeric: tabular-nums; }
        .ayuda-lista__cabeza span:first-child { font-weight: 700; letter-spacing: .05em; text-transform: uppercase; }
        .ayuda-lista { list-style: none; margin: 0; padding: 0 .25rem 0 0; display: flex; flex-direction: column; gap: .2rem;
            max-height: 28rem; overflow-y: auto; }
        .ayuda-paso { display: flex; align-items: center; gap: .75rem; width: 100%; cursor: pointer; text-align: left;
            padding: .45rem .6rem; border: 0; border-radius: .6rem; background: none; font-family: inherit; font-size: .8125rem;
            color: var(--fg); transition: background .15s, box-shadow .15s; }
        .ayuda-paso:hover { background: var(--bg); }
        .ayuda-paso__numero { position: relative; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0;
            width: 1.75rem; height: 1.75rem; border-radius: 50%; background: var(--bg); color: var(--muted);
            font-size: .72rem; font-weight: 700; }
        .ayuda-paso__numero svg { width: 1rem; height: 1rem; }
        .ayuda-paso__latido { position: absolute; inset: 0; border-radius: 50%; background: rgba(0, 166, 90, .4);
            animation: ayuda-latido 1s cubic-bezier(0, 0, .2, 1) infinite; }
        .ayuda-paso__titulo { flex: 1; min-width: 0; }
        .ayuda-paso__tiempo { flex-shrink: 0; font-family: ui-monospace, monospace; font-size: .72rem; color: var(--muted);
            font-variant-numeric: tabular-nums; }
        .ayuda-paso--hecho .ayuda-paso__numero { background: var(--verde); color: #fff; }
        .ayuda-paso--hecho .ayuda-paso__titulo { color: var(--muted); }
        .ayuda-paso--actual { background: #e6f2f1; box-shadow: inset 0 0 0 1px rgba(13, 59, 62, .15); }
        .ayuda-paso--actual .ayuda-paso__numero { background: var(--thead); color: #fff; }
        .ayuda-paso--actual .ayuda-paso__titulo { color: var(--thead); font-weight: 600; }

        @keyframes ayuda-aparecer { from { opacity: 0; } }
        @keyframes ayuda-acercar { from { opacity: 0; transform: scale(.95) translateY(1rem); } }
        @keyframes ayuda-subir { from { opacity: 0; transform: translateY(.75rem); } }
        @keyframes ayuda-deslizar { from { opacity: 0; transform: translateX(1rem); } }
        @keyframes ayuda-bajar { from { opacity: 0; transform: translate(-50%, -.5rem); } }
        @keyframes ayuda-latido { 75%, 100% { transform: scale(2); opacity: 0; } }
        @keyframes ayuda-ecualizador { 0%, 100% { transform: scaleY(.3); } 50% { transform: scaleY(1); } }
        @keyframes ayuda-paneo { from { transform: scale(1.04); } to { transform: scale(1.16) translate(-3%, -2%); } }
        @media (prefers-reduced-motion: reduce) {
            .ayuda__fondo, .ayuda__caja, .ayuda-tarjeta, .ayuda-reproductor, .ayuda-retomado, .ayuda-final,
            .ayuda-final__caja, .ayuda-ecualizador i, .ayuda-paneo, .ayuda__boton { animation: none; }
            .ayuda-paso__latido, .ayuda-tarjeta__play::before { display: none; }
            .ayuda-tarjeta:hover { transform: none; }
        }
        @media (max-width: 960px) {
            .ayuda-reproductor { grid-template-columns: minmax(0, 1fr); }
        }

        @media (max-width: 640px) {
            .cuenta__nombre { display: none; }
            .aviso-pendientes__texto { display: none; }
            .ayuda__boton-texto { display: none; }
            .ayuda__boton { padding: .45rem; }
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
        /* Fila de totales: alineada con las celdas de arriba (el navegador centra los th). */
        tfoot th, tfoot td { text-align: left; padding: .6rem .75rem; background: var(--bg); border-top: 2px solid var(--border); }
        tbody tr:hover { background: #f9fafb; }
        /* Fila de solo lectura (p. ej. asignación de horario vencida). */
        tbody tr.fila--inactiva td { color: var(--muted); background: var(--bg); }
        /* Cabecera de bloque dentro del cuerpo: agrupa las filas que comparten
           un período de vigencia (los días de un mismo horario asignado). */
        tbody tr.fila--periodo th { text-align: left; background: var(--bg); padding: .3rem .75rem;
            border-bottom: 1px solid var(--border); border-top: 2px solid var(--border); font-weight: 600; }
        tbody tr.fila--periodo:first-child th { border-top: 0; }
        tbody tr.fila--periodo:hover th { background: var(--bg); }
        /* Va acotada a la fila de bloque y con la direccion explicita: suelta,
           heredaba el `flex-direction: column` de la `.periodo` de licencias y
           la cabecera se partia en tres renglones centrados. */
        .fila--periodo .periodo { display: flex; flex-direction: row; align-items: center;
            gap: .45rem; flex-wrap: wrap; font-weight: 400; white-space: normal; }
        .periodo__fechas { font-size: .75rem; font-weight: 700; color: var(--thead);
            letter-spacing: .01em; white-space: nowrap; }
        .periodo__flecha { color: var(--muted); margin: 0 .1rem; font-weight: 400; }
        .periodo__conteo { font-size: .7rem; color: var(--muted); font-weight: 500; }

        /* Filas de un solo dato corto (dia y horario): el alto por defecto las
           deja demasiado aireadas cuando son cinco por cada bloque. */
        .tabla--compacta thead th { padding: .4rem .75rem; }
        .tabla--compacta tbody td { padding: .3rem .75rem; }
        .horario { font-variant-numeric: tabular-nums; white-space: nowrap; }
        .horario__sep { color: var(--muted); margin: 0 .3rem; }

        .pill { display: inline-block; padding: .2rem .6rem; border-radius: 9999px; font-size: .7rem; font-weight: 700; }
        .pill--ok { background: #dcfce7; color: #166534; }
        .pill--no { background: #fee2e2; color: #991b1b; }
        .pill--advertencia { background: #fef3c7; color: #92400e; }
        .pill--info { background: #dbeafe; color: #1e40af; }
        /* Origen del dato, no un estado: va apagado para que no le compita al
           verde de «Con contrato», que es lo que de verdad hay que leer. */
        .pill--neutro { background: var(--bg); color: var(--muted); border: 1px solid var(--border); }
        .pill--mini { padding: .05rem .4rem; font-size: .65rem; }

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

        /* Ficha del funcionario: la foto **al lado** de los datos y no encima.
           Centrada arriba dejaba una franja vacía a los dos lados del retrato y
           empujaba los campos hacia abajo. */
        .ficha-persona { display: flex; gap: 1.25rem; align-items: flex-start; }
        .ficha-persona .ficha-foto { margin: 0; flex-shrink: 0; }
        /* `min-width: 0` para que un correo largo se acomode en vez de estirar
           la columna y desbordar la tarjeta. */
        .ficha-persona__datos { flex: 1; min-width: 0; }

        /* Segundo bloque dentro de la misma tarjeta (identidad y contacto son
           el mismo dato: quién es la persona). La línea los separa sin abrir
           otra tarjeta. */
        .ficha-bloque { margin-top: .25rem; padding-top: 1.1rem; border-top: 1px solid var(--border); }
        .ficha-bloque h3 { font-size: .75rem; margin: 0 0 .9rem; color: var(--muted);
            text-transform: uppercase; letter-spacing: .04em; }

        @media (max-width: 640px) {
            .ficha-persona { flex-direction: column; }
            .ficha-persona .ficha-foto { margin: 0 auto; }
        }
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
        /* Tope de alto con scroll propio: en pantallas bajas el modal no se
           sale de la ventana y los botones del pie siguen al alcance. */
        .modal-caja { background: var(--card); border-radius: .625rem; width: 100%; max-width: 24rem;
            padding: 1.25rem; box-shadow: 0 20px 45px rgba(0,0,0,.28); text-align: left;
            max-height: calc(100vh - 2rem); max-height: calc(100dvh - 2rem); overflow-y: auto;
            overscroll-behavior: contain; }
        /* Al llegar al final de una lista dentro de un modal, la rueda no sigue
           de largo a la página de atrás. */
        .modal-caja * { overscroll-behavior: contain; }
        .modal-caja h2 { font-size: 1rem; margin: 0 0 1rem; }
        .modal-caja--ancha { max-width: 30rem; }
        .modal-caja--ancha h2 { margin-bottom: .25rem; }
        .modal-bajada { margin: 0 0 1rem; color: var(--muted); font-size: .8125rem; line-height: 1.45; }
        .modal-acciones { display: flex; gap: .6rem; justify-content: flex-end; margin-top: 1.25rem; flex-wrap: wrap; }
        .modal-acciones form { margin: 0; }
        /* ===== «¿Cuándo?» de la pantalla Licenciar: el atajo elegido manda ===== */
        .cuando-opciones { display: grid; grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr)); gap: .5rem; }
        .cuando-opcion { display: inline-flex; align-items: center; justify-content: center; gap: .45rem;
            padding: .7rem .8rem; border: 1px solid var(--border); border-radius: .6rem; background: #fff;
            font: inherit; font-size: .875rem; font-weight: 600; color: var(--fg); cursor: pointer;
            transition: border-color .15s, background .15s, color .15s; }
        .cuando-opcion svg { width: 1.1rem; height: 1.1rem; color: var(--muted); }
        .cuando-opcion:hover { border-color: var(--verde); }
        .cuando-opcion--activa { border-color: var(--verde); background: #f0fdf4; color: var(--verde-osc);
            box-shadow: 0 0 0 3px rgba(22,163,74,.12); }
        .cuando-opcion--activa svg { color: var(--verde); }
        .cuando-resumen { display: flex; align-items: flex-start; gap: .5rem; margin: .85rem 0 0;
            font-size: .8125rem; color: #14532d; }
        .cuando-resumen svg { width: 1.05rem; height: 1.05rem; flex-shrink: 0; color: var(--verde); margin-top: .1rem; }
        input[type="date"][readonly] { background: var(--bg); color: var(--muted); }

        /* ===== Resolución en lote del listado de licencias ===== */
        .lote-celda { width: 2.25rem; text-align: center; }
        .lote-celda input { width: 1.05rem; height: 1.05rem; cursor: pointer; accent-color: var(--verde); }
        tr:has(.lote-check:checked) td { background: #f0fdf4; }
        .lote-aviso { display: flex; align-items: center; gap: .6rem; margin-bottom: .75rem;
            padding: .65rem .9rem; border-radius: .55rem; font-size: .8125rem;
            background: #eff6ff; border: 1px solid #bfdbfe; color: #1e3a8a; }
        .lote-aviso svg { width: 1.15rem; height: 1.15rem; flex-shrink: 0; }
        .lote-aviso--todas { background: #f0fdf4; border-color: #bbf7d0; color: #14532d; }
        .lote-aviso__enlace { background: none; border: 0; padding: 0 0 0 .25rem; cursor: pointer;
            font: inherit; font-weight: 700; color: inherit; text-decoration: underline; }
        .lote-barra { position: fixed; left: 50%; bottom: 1.25rem; transform: translateX(-50%); z-index: 40;
            display: flex; align-items: center; gap: 1.5rem; flex-wrap: wrap; justify-content: space-between;
            width: min(44rem, calc(100vw - 2rem)); padding: .75rem .9rem .75rem 1rem;
            background: #0f2f2b; color: #fff; border-radius: .85rem;
            box-shadow: 0 18px 40px rgba(0,0,0,.28), 0 0 0 1px rgba(255,255,255,.06) inset; }
        .lote-barra--entra { transition: opacity .2s ease, transform .2s ease; }
        .lote-barra--oculta { opacity: 0; transform: translate(-50%, 1rem); }
        .lote-barra__cuenta { display: flex; align-items: center; gap: .7rem; }
        .lote-barra__cuenta strong { display: block; font-size: .875rem; }
        .lote-barra__cuenta small { color: rgba(255,255,255,.7); font-size: .75rem; }
        .lote-barra__numero { display: inline-flex; align-items: center; justify-content: center;
            min-width: 2.2rem; height: 2.2rem; padding: 0 .5rem; border-radius: 999px;
            background: var(--verde); font-weight: 800; font-size: 1rem; }
        .lote-barra__acciones { display: flex; align-items: center; gap: .5rem; }
        .lote-barra__limpiar { background: none; border: 0; color: rgba(255,255,255,.75); cursor: pointer;
            font: inherit; font-size: .8125rem; padding: .25rem .5rem; }
        .lote-barra__limpiar:hover { color: #fff; text-decoration: underline; }
        .lote-modal { max-width: 54rem; }
        .lote-modal__cabeza { display: flex; align-items: center; gap: .85rem; margin-bottom: .85rem; }
        .lote-modal__cabeza h2 { margin: 0; }
        .lote-modal__sub { margin: .15rem 0 0; color: var(--muted); font-size: .8125rem; }
        .lote-modal__icono { display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0;
            width: 2.9rem; height: 2.9rem; border-radius: 50%; background: #dcfce7; color: var(--verde); }
        .lote-modal__icono svg { width: 1.45rem; height: 1.45rem; }
        .lote-modal__icono--rechazo { background: #fee2e2; color: var(--danger); }
        .lote-modal__resumen { display: flex; flex-wrap: wrap; gap: .35rem; margin-bottom: .85rem; }
        .lote-modal__efectos { list-style: none; margin: 0 0 .9rem; padding: .7rem .85rem;
            display: grid; gap: .5rem; border-radius: .6rem; font-size: .8125rem; line-height: 1.45;
            background: #f0fdf4; border: 1px solid #bbf7d0; color: #14532d; }
        .lote-modal__efectos--rechazo { background: #fef2f2; border-color: #fecaca; color: #7f1d1d; }
        .lote-modal__efectos li { display: flex; gap: .55rem; align-items: flex-start; }
        .lote-modal__efectos svg { width: 1.05rem; height: 1.05rem; flex-shrink: 0; margin-top: .1rem; }
        .lote-modal__tabla { max-height: 16rem; overflow: auto; margin-bottom: 1rem;
            border: 1px solid var(--border); border-radius: .6rem; }
        .lote-modal__tabla table { margin: 0; font-size: .78rem; }
        .lote-modal__tabla thead th { position: sticky; top: 0; z-index: 1; }
        .lote-modal__tabla td { vertical-align: top; }
        .lote-modal__tabla .pill { white-space: nowrap; }
        .lote-modal__motivo { max-width: 11rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .lote-modal__quitar { display: inline-flex; align-items: center; justify-content: center;
            width: 1.7rem; height: 1.7rem; border-radius: 50%; border: 1px solid var(--border);
            background: #fff; color: var(--muted); cursor: pointer; }
        .lote-modal__quitar svg { width: .9rem; height: .9rem; }
        .lote-modal__quitar:hover { background: #fee2e2; border-color: #fecaca; color: var(--danger); }
        .lote-modal__mas { color: var(--muted); font-style: italic; text-align: center; }

        /* Ficha corta dentro de un modal: etiqueta a la izquierda, dato a la derecha. */
        .modal-resumen { display: grid; grid-template-columns: auto 1fr; gap: .35rem .9rem;
            margin: 0 0 1rem; font-size: .8125rem; }
        .modal-resumen dt { color: var(--muted); }
        .modal-resumen dd { margin: 0; font-weight: 600; }
        /* Horarios vigentes dentro del modal de licencia: la caja no crece con
           quien tiene muchos, la tabla se desplaza sola. */
        .modal-licencia__horarios { max-height: 13rem; overflow-y: auto; margin-bottom: 1rem; }
        .modal-licencia__horarios table { font-size: .75rem; }
        .modal-licencia__horarios .paginacion { margin-top: .5rem; }

        /* Modal de asignar turno: más ancho, para leer la semana de cada turno. */
        .modal-caja--horarios { max-width: 38rem; }

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
        .grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0 1rem; }
        @media (max-width: 900px) { .grid-3 { grid-template-columns: 1fr 1fr; } }
        @media (max-width: 640px) { .grid-3 { grid-template-columns: 1fr; } }
        @media (max-width: 900px) { .form-grid { grid-template-columns: 1fr; } }
        /* Una sola columna a cualquier ancho: para las pantallas donde las dos
           tarjetas necesitan la fila entera —una tabla larga al lado de una
           ficha corta se lee peor apretada que apilada. */
        .form-grid--apilado { grid-template-columns: 1fr; }
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

        /* ===== Historial de un usuario: línea de tiempo con antes/después ===== */
        .hist-usuario { display: flex; align-items: center; gap: .9rem; }
        .hist-usuario__avatar { width: 3rem; height: 3rem; font-size: 1rem; }
        .hist-usuario__estado { margin-left: auto; }
        .hist { list-style: none; margin: 0; padding: 0 0 0 2.6rem; position: relative; }
        .hist::before { content: ""; position: absolute; left: .95rem; top: .5rem; bottom: .5rem;
            width: 2px; background: var(--border); }
        .hist__evento { position: relative; margin-bottom: 1.25rem; }
        .hist__evento:last-child { margin-bottom: 0; }
        .hist-punto { position: absolute; left: -2.6rem; top: 0; width: 2rem; height: 2rem; border-radius: 50%;
            display: inline-flex; align-items: center; justify-content: center; color: #fff;
            background: var(--muted); box-shadow: 0 0 0 .25rem var(--card); }
        .hist-punto svg { width: 1rem; height: 1rem; }
        .hist-punto--creado { background: var(--verde); }
        .hist-punto--actualizado { background: #3b82f6; }
        .hist-punto--password { background: #8b5cf6; }
        .hist-punto--inhabilitado { background: #f59e0b; }
        .hist-punto--eliminado { background: var(--danger); }
        .hist__tarjeta { border: 1px solid var(--border); border-radius: .55rem; padding: .8rem 1rem; }
        .hist__titulo { font-weight: 700; }
        .hist__resumen { margin-top: .5rem; font-size: .8rem; display: flex; flex-wrap: wrap; gap: .3rem; align-items: center; }
        .hist__tabla { margin-top: .7rem; border: 1px solid var(--border); }
        .hist__tabla thead th { border-radius: 0 !important; }
        .hist__tabla tbody td { border-radius: 0 !important; }
        .hist__tabla td:first-child { width: 28%; }
        tbody tr.hist__fila--cambio td { background: #fefce8; }
        .hist__antes { color: #b91c1c; text-decoration: line-through; }
        .hist__despues { color: #15803d; font-weight: 600; }
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

        /* ===== Escritorio: cuatro números del día, avisos y accesos ===== */
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1.5rem; }
        @media (max-width: 960px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 560px) { .stats-grid { grid-template-columns: 1fr; } }
        .stat-card { background: var(--card); border: 1px solid var(--border); border-radius: .625rem;
            padding: 1.25rem 1.1rem; box-shadow: 0 1px 2px rgba(0,0,0,.05); text-align: center; }
        .stat-card__valor { font-size: 2.25rem; font-weight: 700; line-height: 1.1; font-variant-numeric: tabular-nums; }
        .stat-card__label { font-size: .85rem; color: var(--muted); margin-top: .35rem; }
        .stat-card__sub { font-size: .75rem; color: var(--muted); margin-top: .25rem; }
        .stat-card--success .stat-card__valor { color: #166534; }
        .stat-card--danger .stat-card__valor { color: #991b1b; }

        .escritorio__seccion { font-size: .78rem; font-weight: 700; letter-spacing: .06em;
            text-transform: uppercase; color: var(--muted); margin: 0 0 .75rem; }
        .escritorio__fecha { font-size: .8rem; color: var(--muted); margin-left: auto;
            text-transform: capitalize; }
        .escritorio__aviso { display: flex; align-items: flex-start; gap: .6rem; margin-bottom: 1rem; }
        .escritorio__aviso svg { width: 1.2rem; height: 1.2rem; flex-shrink: 0; margin-top: .1rem; }
        .escritorio__accesos { display: flex; flex-wrap: wrap; gap: .6rem; margin-bottom: 1.5rem; }
        .escritorio__pie { font-size: .8rem; color: var(--muted); margin: 0; }

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
    <x-entorno-local />
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
                'turnos' => request()->routeIs('turnos.*'),
                'turnos-asignados' => request()->routeIs('turnos-asignados.*'),
                'licencias' => request()->routeIs('licencias.*'),
                'reportes' => request()->routeIs('reportes.*'),
                'reporte-sin-procesar' => request()->routeIs('reportes.marcaciones.sin-procesar*'),
                'reporte-procesado' => request()->routeIs('reportes.marcaciones.procesado*'),
                'reporte-direccion' => request()->routeIs('reportes.marcaciones.direccion*'),
                'reporte-sin-turno' => request()->routeIs('reportes.funcionarios.sin-turno*'),
                'equipos' => request()->routeIs('equipos.*'),
                'usuarios' => request()->routeIs('usuarios.*'),
                'actividades' => request()->routeIs('actividades.*'),
                'roles' => request()->routeIs('roles.*'),
                'tokens-api' => request()->routeIs('tokens-api.*'),
                'configuracion' => request()->routeIs('configuracion.*'),
            ];
            // El grupo «Parámetros» arranca desplegado cuando la pantalla actual
            // es una de las suyas; si no, el ítem activo quedaría escondido.
            $enParametros = $enMenu['dias-excepcionales'] || $enMenu['turnos']
                || $enMenu['turnos-asignados'];
            $enSeguridad = $enMenu['usuarios'] || $enMenu['roles']
                || $enMenu['actividades'] || $enMenu['tokens-api'];
        @endphp

        {{-- El texto de cada opción va en su propio span: plegado queda solo el
             icono, y el `title` lo nombra al pasar el mouse por la franja. --}}
        {{-- Cada opción se muestra solo si el rol puede abrirla: antes se veían
             todas y el 403 aparecía recién al hacer clic. Los grupos se ocultan
             enteros cuando ninguna de sus opciones está permitida. --}}
        @php
            $puedeParametros = auth()->user()?->canAny([
                'ViewAny:DiaExcepcional', 'ViewAny:Turno', 'ViewAny:AsignacionTurno',
            ]);
            $puedeSeguridad = auth()->user()?->canAny(['ViewAny:User', 'ViewAny:Role', 'ViewAny:SistemaExterno'])
                || auth()->user()?->can('viewAny', \App\Models\Actividad::class);
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
            @can('ViewAny:Licencia')
                <a href="{{ route('licencias.index') }}" @class(['sidebar__link', 'activo' => $enMenu['licencias']]) title="Licencias"@if ($enMenu['licencias']) aria-current="page"@endif>
                    <x-heroicon-o-clipboard-document-check /><span class="sidebar__texto">Licencias</span>
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
                    @can('ViewAny:Turno')
                        <a href="{{ route('turnos.index') }}" @class(['sidebar__sublink', 'activo' => $enMenu['turnos']]) title="Turnos"@if ($enMenu['turnos']) aria-current="page"@endif>
                            <x-heroicon-o-rectangle-stack /><span class="sidebar__texto">Turnos</span>
                        </a>
                    @endcan
                    @can('ViewAny:AsignacionTurno')
                        <a href="{{ route('turnos-asignados.index') }}" @class(['sidebar__sublink', 'activo' => $enMenu['turnos-asignados']]) title="Turnos asignados"@if ($enMenu['turnos-asignados']) aria-current="page"@endif>
                            <x-heroicon-o-user-plus /><span class="sidebar__texto">Turnos asignados</span>
                        </a>
                    @endcan
                </div>
            </div>
            {{-- Reportes: mismo patrón colapsable que «Parámetros». --}}
            <div x-data="{ abierto: {{ $enMenu['reportes'] ? 'true' : 'false' }} }" @cannot('ViewAny:Reporte') hidden @endcannot>
                <button type="button" @class(['sidebar__grouptoggle', 'activo' => $enMenu['reportes']]) title="Reporte de marcaciones"
                        x-on:click="sidebarPlegado ? (alternarMenu(), abierto = true) : (abierto = !abierto)" :aria-expanded="abierto">
                    <x-heroicon-o-document-chart-bar /><span class="sidebar__texto">Reporte de marcaciones</span>
                    <span class="sidebar__chevron" :style="abierto ? 'transform: rotate(180deg)' : ''"><x-heroicon-o-chevron-down /></span>
                </button>
                <div class="sidebar__submenu" x-show="abierto" x-cloak>
                    <a href="{{ route('reportes.marcaciones.sin-procesar') }}" @class(['sidebar__sublink', 'activo' => $enMenu['reporte-sin-procesar']]) title="Marcaciones sin procesar"@if ($enMenu['reporte-sin-procesar']) aria-current="page"@endif>
                        <x-heroicon-o-finger-print /><span class="sidebar__texto">Sin procesar</span>
                    </a>
                    <a href="{{ route('reportes.marcaciones.procesado') }}" @class(['sidebar__sublink', 'activo' => $enMenu['reporte-procesado']]) title="Marcaciones procesadas"@if ($enMenu['reporte-procesado']) aria-current="page"@endif>
                        <x-heroicon-o-clipboard-document-list /><span class="sidebar__texto">Procesado</span>
                    </a>
                    <a href="{{ route('reportes.marcaciones.direccion') }}" @class(['sidebar__sublink', 'activo' => $enMenu['reporte-direccion']]) title="Marcaciones por dirección"@if ($enMenu['reporte-direccion']) aria-current="page"@endif>
                        <x-heroicon-o-building-office-2 /><span class="sidebar__texto">Por dirección</span>
                    </a>
                    <a href="{{ route('reportes.funcionarios.sin-turno') }}" @class(['sidebar__sublink', 'activo' => $enMenu['reporte-sin-turno']]) title="Funcionarios sin turno"@if ($enMenu['reporte-sin-turno']) aria-current="page"@endif>
                        <x-heroicon-o-calendar-days /><span class="sidebar__texto">Sin turno</span>
                    </a>
                </div>
            </div>
            @can('ViewAny:Equipo')
                <a href="{{ route('equipos.index') }}" @class(['sidebar__link', 'activo' => $enMenu['equipos']]) title="Biométricos"@if ($enMenu['equipos']) aria-current="page"@endif>
                    <x-heroicon-o-computer-desktop /><span class="sidebar__texto">Biométricos</span>
                </a>
            @endcan
            {{-- Seguridad: quién entra, con qué permisos, qué hizo y qué
                 sistemas externos tienen llave. Mismo patrón que «Parámetros». --}}
            <div x-data="{ abierto: {{ $enSeguridad ? 'true' : 'false' }} }" @if (! $puedeSeguridad) hidden @endif>
                <button type="button" @class(['sidebar__grouptoggle', 'activo' => $enSeguridad]) title="Seguridad"
                        x-on:click="sidebarPlegado ? (alternarMenu(), abierto = true) : (abierto = !abierto)" :aria-expanded="abierto">
                    <x-heroicon-o-lock-closed /><span class="sidebar__texto">Seguridad</span>
                    <span class="sidebar__chevron" :style="abierto ? 'transform: rotate(180deg)' : ''"><x-heroicon-o-chevron-down /></span>
                </button>
                <div class="sidebar__submenu" x-show="abierto" x-cloak>
                    @can('ViewAny:User')
                        <a href="{{ route('usuarios.index') }}" @class(['sidebar__sublink', 'activo' => $enMenu['usuarios']]) title="Usuarios"@if ($enMenu['usuarios']) aria-current="page"@endif>
                            <x-heroicon-o-user /><span class="sidebar__texto">Usuarios</span>
                        </a>
                    @endcan
                    @can('ViewAny:Role')
                        <a href="{{ route('roles.index') }}" @class(['sidebar__sublink', 'activo' => $enMenu['roles']]) title="Roles"@if ($enMenu['roles']) aria-current="page"@endif>
                            <x-heroicon-o-shield-check /><span class="sidebar__texto">Roles</span>
                        </a>
                    @endcan
                    @can('viewAny', \App\Models\Actividad::class)
                        <a href="{{ route('actividades.index') }}" @class(['sidebar__sublink', 'activo' => $enMenu['actividades']]) title="Actividad de usuarios"@if ($enMenu['actividades']) aria-current="page"@endif>
                            <x-heroicon-o-eye /><span class="sidebar__texto">Actividad de usuarios</span>
                        </a>
                    @endcan
                    @can('ViewAny:SistemaExterno')
                        <a href="{{ route('tokens-api.index') }}" @class(['sidebar__sublink', 'activo' => $enMenu['tokens-api']]) title="Tokens de API"@if ($enMenu['tokens-api']) aria-current="page"@endif>
                            <x-heroicon-o-key /><span class="sidebar__texto">Tokens de API</span>
                        </a>
                    @endcan
                </div>
            </div>
            @can('Update:Configuracion')
                <a href="{{ route('configuracion.edit') }}" @class(['sidebar__link', 'activo' => $enMenu['configuracion']]) title="Configuración"@if ($enMenu['configuracion']) aria-current="page"@endif>
                    <x-heroicon-o-cog-6-tooth /><span class="sidebar__texto">Configuración</span>
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

            @auth
                <x-manual-usuario />
            @endauth

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
                @php($usuarioActual = auth()->user())
                <div class="cuenta" x-data="{ abierto: false }" x-on:keydown.escape.window="abierto = false">
                    <button type="button" class="cuenta__boton" x-on:click="abierto = !abierto" :aria-expanded="abierto">
                        <span class="cuenta__avatar"><img src="{{ $usuarioActual->avatar_url }}" alt=""></span>
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
                        <a href="{{ route('password.cambiar') }}" class="cuenta__opcion">
                            <x-heroicon-o-key />Cambiar contraseña
                        </a>
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
        Modal global para concluir una asignación de horario. Mismo mecanismo que
        el de eliminación (store de Alpine + un solo formulario), porque los
        botones también viajan en tablas que se cargan por AJAX.

        Concluir no borra: le pone fecha de fin a la asignación, así el horario
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
            <h2 id="titulo-concluir-global">Concluir el horario</h2>
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
                        Desde el día siguiente el funcionario deja de tener este horario.
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
