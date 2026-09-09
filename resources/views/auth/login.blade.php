<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Acceso · {{ config('app.name') }}</title>
    <meta name="description" content="Sistema de sincronización de biométricos del Gobierno Autónomo Departamental del Beni.">
    <link rel="icon" href="{{ asset('image/icon.png') }}">
    <style>
        :root {
            --verde: #00a65a; --verde-osc: #008d4c; --sidebar: #0d3b3e; --sidebar-header: #082628;
            --sidebar-hover: #164e52; --sidebar-activo: #1c6266; --verde-claro: #7ee2b0;
            --bg: #f4f6f9; --card: #fff; --fg: #1f2937; --muted: #6b7280; --border: #e5e7eb; --danger: #ef4444;
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; font-size: .9rem; line-height: 1.45;
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: var(--bg); color: var(--fg); }
        svg { display: block; }

        /* Dos mitades: la marca institucional a la izquierda, el ingreso a la
           derecha. Debajo de 900px la marca se retira y queda solo la tarjeta. */
        .acceso { display: grid; grid-template-columns: 1.15fr .85fr; min-height: 100vh; }

        /* ===== Mitad institucional ===== */
        .marca { position: relative; overflow: hidden; padding: 3rem 3.25rem;
            display: flex; flex-direction: column; gap: 2rem;
            background: var(--sidebar); color: #fff; }
        /* Dos halos apenas visibles para que el fondo no sea un plano liso. */
        .marca::before { content: ""; position: absolute; inset: 0; pointer-events: none;
            background:
                radial-gradient(50rem 32rem at 12% -10%, rgba(0,166,90,.28), transparent 60%),
                radial-gradient(38rem 30rem at 105% 108%, rgba(28,98,102,.55), transparent 62%); }
        .marca > * { position: relative; }

        .marca__escudo { display: flex; align-items: center; gap: .9rem; }
        .marca__escudo img { height: 3.5rem; width: auto; flex-shrink: 0; }
        .marca__entidad { font-size: .8rem; font-weight: 700; letter-spacing: .04em;
            text-transform: uppercase; line-height: 1.3; }
        .marca__entidad small { display: block; font-size: .72rem; font-weight: 500;
            letter-spacing: .02em; text-transform: none; color: rgba(255,255,255,.72); }

        .marca__titulo { font-size: 1.9rem; font-weight: 800; line-height: 1.15; margin: 0; }
        .marca__titulo span { color: var(--verde-claro); }
        .marca__bajada { margin: .75rem 0 0; max-width: 34rem; font-size: .9rem;
            color: rgba(255,255,255,.8); }

        /* ===== Los otros sistemas del Gobierno Departamental ===== */
        .portales { margin-top: auto; }
        .portales__rotulo { font-size: .7rem; font-weight: 700; letter-spacing: .09em;
            text-transform: uppercase; color: rgba(255,255,255,.6); margin-bottom: .85rem; }
        .portales__grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr));
            gap: .6rem; }
        .portal { display: flex; align-items: flex-start; gap: .6rem; padding: .7rem .8rem;
            border: 1px solid rgba(255,255,255,.14); border-radius: .6rem;
            background: rgba(255,255,255,.05); color: #fff; text-decoration: none;
            transition: background .15s ease, border-color .15s ease, transform .15s ease; }
        .portal:hover { background: rgba(255,255,255,.11); border-color: rgba(126,226,176,.5);
            transform: translateY(-1px); }
        .portal:focus-visible { outline: 2px solid var(--verde-claro); outline-offset: 2px; }
        .portal svg { width: 1.05rem; height: 1.05rem; margin-top: .15rem; flex-shrink: 0; color: var(--verde-claro); }
        .portal__nombre { display: block; font-size: .8rem; font-weight: 700; }
        .portal__que { display: block; font-size: .72rem; color: rgba(255,255,255,.68); }

        .marca__pie { font-size: .72rem; color: rgba(255,255,255,.55); }
        .marca__pie a { color: rgba(255,255,255,.8); }

        /* ===== Mitad del ingreso ===== */
        .ingreso { display: flex; align-items: center; justify-content: center;
            padding: 3rem 2rem; background: var(--card); }
        .login-card { width: 100%; max-width: 23rem; }
        .login-card__titulo { font-size: 1.25rem; font-weight: 800; margin: 0; color: var(--sidebar-header); }
        .login-card__sub { margin: .3rem 0 1.75rem; font-size: .82rem; color: var(--muted); }

        /* El escudo se repite acá solo cuando la mitad institucional no está
           en pantalla (móvil), para que la tarjeta no quede sin identidad. */
        .login-card__escudo { display: none; }

        .campo { margin-bottom: 1rem; }
        .campo label { display: block; font-weight: 600; font-size: .8rem; margin-bottom: .3rem; }
        .campo input { width: 100%; padding: .6rem .75rem; border: 1px solid var(--border); border-radius: .5rem;
            font-size: .85rem; font-family: inherit; background: #fff; color: var(--fg); }
        .campo input:focus { outline: none; border-color: var(--verde); box-shadow: 0 0 0 3px rgba(0,166,90,.25); }
        .campo .error { color: var(--danger); font-size: .78rem; margin-top: .25rem; }
        .check { display: flex; align-items: center; gap: .5rem; margin-bottom: 1.25rem; font-size: .82rem; }
        .btn { width: 100%; border: 0; cursor: pointer; background: var(--verde); color: #fff;
            padding: .65rem .85rem; border-radius: .5rem; font-size: .875rem; font-weight: 700;
            font-family: inherit; }
        .btn:hover { background: var(--verde-osc); }
        .btn:focus-visible { outline: 2px solid var(--sidebar); outline-offset: 2px; }
        .ayuda { margin-top: 1.5rem; padding-top: 1.1rem; border-top: 1px solid var(--border);
            font-size: .75rem; color: var(--muted); }

        /* En pantalla angosta la mitad institucional se retira entera: apilada
           empujaba el formulario fuera de la vista en un teléfono. La tarjeta
           vuelve a ser la de antes, centrada sobre el fondo gris. */
        @media (max-width: 900px) {
            .acceso { grid-template-columns: 1fr; }
            .marca { display: none; }
            .ingreso { background: var(--bg); padding: 2rem 1.25rem; }
            .login-card { background: var(--card); border: 1px solid var(--border); border-radius: .75rem;
                box-shadow: 0 4px 16px rgba(0,0,0,.06); padding: 2rem; }
            .login-card__escudo { display: flex; flex-direction: column; align-items: center;
                gap: .5rem; margin-bottom: 1.5rem; text-align: center; }
            .login-card__escudo img { height: 3rem; width: auto; }
            .login-card__escudo span { font-size: .72rem; font-weight: 700; letter-spacing: .04em;
                text-transform: uppercase; color: var(--sidebar-header); }
        }

        @media (prefers-reduced-motion: reduce) {
            .portal { transition: none; }
            .portal:hover { transform: none; }
        }
    </style>
</head>
<body>
    @php
        /**
         * Los otros sistemas del Gobierno Autónomo Departamental del Beni.
         *
         * Se listan acá porque el ingreso es la única pantalla pública del
         * sistema: quien llega buscando otra cosa encuentra a dónde ir, y el
         * personal ve que esto es una pieza más del mismo gobierno y no un
         * sitio suelto.
         *
         * @var array<int, array{nombre: string, que: string, url: string}>
         */
        $portales = [
            ['nombre' => 'Portal del Beni', 'que' => 'Sitio institucional de la Gobernación', 'url' => 'https://beni.gob.bo/'],
            ['nombre' => 'Mamoré', 'que' => 'Personal, contratos y planillas', 'url' => 'https://mamore.beni.gob.bo/'],
            ['nombre' => 'SisCor', 'que' => 'Correspondencia y trámites', 'url' => 'https://siscor.beni.gob.bo/'],
            ['nombre' => 'Almacenes', 'que' => 'Existencias y activos', 'url' => 'https://almacen.beni.gob.bo/'],
            ['nombre' => 'Gaceta', 'que' => 'Normativa departamental publicada', 'url' => 'https://gaceta.beni.gob.bo/'],
        ];
    @endphp

    <div class="acceso">
        <aside class="marca">
            <div class="marca__escudo">
                <img src="{{ asset('image/icon.png') }}" alt="Escudo del Departamento del Beni">
                <div class="marca__entidad">
                    Gobierno Autónomo Departamental del Beni
                    <small>Trinidad · Beni · Bolivia</small>
                </div>
            </div>

            <div>
                <h1 class="marca__titulo">Sistema de sincronización de <span>biométricos</span></h1>
                <p class="marca__bajada">
                    Reúne las marcas de los relojes de cada oficina y las cruza con los turnos,
                    las licencias y los contratos vigentes en Mamoré para armar la asistencia
                    del personal del Gobierno Departamental.
                </p>
            </div>

            <div class="portales">
                <div class="portales__rotulo">Otros sistemas del Gobierno Departamental</div>
                <div class="portales__grid">
                    @foreach ($portales as $portal)
                        <a class="portal" href="{{ $portal['url'] }}" target="_blank" rel="noopener noreferrer">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                                <polyline points="15 3 21 3 21 9"/>
                                <line x1="10" y1="14" x2="21" y2="3"/>
                            </svg>
                            <span>
                                <span class="portal__nombre">{{ $portal['nombre'] }}</span>
                                <span class="portal__que">{{ $portal['que'] }}</span>
                            </span>
                        </a>
                    @endforeach
                </div>
            </div>

            <div class="marca__pie">
                © {{ date('Y') }} Gobierno Autónomo Departamental del Beni ·
                <a href="https://beni.gob.bo/" target="_blank" rel="noopener noreferrer">beni.gob.bo</a>
            </div>
        </aside>

        <main class="ingreso">
            <div class="login-card">
                <div class="login-card__escudo">
                    <img src="{{ asset('image/icon.png') }}" alt="Escudo del Departamento del Beni">
                    <span>Gobierno Autónomo Departamental del Beni</span>
                </div>

                <h2 class="login-card__titulo">Acceso al sistema</h2>
                <p class="login-card__sub">Uso interno del personal autorizado.</p>

                <form method="POST" action="{{ route('login') }}">
                    @csrf
                    <div class="campo">
                        <label for="email">Correo electrónico</label>
                        <input type="email" id="email" name="email" value="{{ old('email') }}"
                               autocomplete="username" required autofocus>
                        @error('email')
                            <div class="error">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="campo">
                        <label for="password">Contraseña</label>
                        <input type="password" id="password" name="password"
                               autocomplete="current-password" required>
                    </div>

                    <label class="check">
                        <input type="checkbox" name="remember">
                        Recordarme
                    </label>

                    <button type="submit" class="btn">Entrar</button>
                </form>

                <p class="ayuda">
                    ¿Sin cuenta o con la contraseña olvidada? La habilita la unidad de
                    Recursos Humanos del Gobierno Departamental.
                </p>
            </div>
        </main>
    </div>
</body>
</html>
