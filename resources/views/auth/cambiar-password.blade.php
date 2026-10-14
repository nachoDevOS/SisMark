<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ app()->environment('local') ? '[LOCAL] ' : '' }}Cambiar contraseña · {{ config('app.name') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('image/huella.png') }}">
    <style>
        :root {
            --verde: #00a65a; --verde-osc: #008d4c; --sidebar: #0d3b3e; --sidebar-header: #082628;
            --bg: #f4f6f9; --card: #fff; --fg: #1f2937; --muted: #6b7280; --border: #e5e7eb; --danger: #ef4444;
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            padding: 2rem 1.25rem; font-size: .9rem; line-height: 1.45; background: var(--bg); color: var(--fg);
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif; }
        .tarjeta { width: 100%; max-width: 25rem; background: var(--card); border: 1px solid var(--border);
            border-radius: .75rem; box-shadow: 0 4px 16px rgba(0,0,0,.06); padding: 2rem; }
        .escudo { display: flex; flex-direction: column; align-items: center; gap: .5rem; margin-bottom: 1.25rem;
            text-align: center; }
        .escudo img { height: 3rem; width: auto; }
        .escudo span { font-size: .72rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase;
            color: var(--sidebar-header); }
        h1 { font-size: 1.2rem; font-weight: 800; margin: 0; color: var(--sidebar-header); }
        .sub { margin: .3rem 0 1.25rem; font-size: .82rem; color: var(--muted); }
        .aviso { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; padding: .65rem .8rem;
            border-radius: .5rem; font-size: .8rem; margin-bottom: 1.25rem; }
        .campo { margin-bottom: 1rem; }
        .campo label { display: block; font-weight: 600; font-size: .8rem; margin-bottom: .3rem; }
        .campo input { width: 100%; padding: .6rem .75rem; border: 1px solid var(--border); border-radius: .5rem;
            font-size: .85rem; font-family: inherit; background: #fff; color: var(--fg); }
        .campo input:focus { outline: none; border-color: var(--verde); box-shadow: 0 0 0 3px rgba(0,166,90,.25); }
        .campo .ayuda { font-size: .75rem; color: var(--muted); margin-top: .25rem; }
        .campo .error { color: var(--danger); font-size: .78rem; margin-top: .25rem; }
        .btn { width: 100%; border: 0; cursor: pointer; background: var(--verde); color: #fff;
            padding: .65rem .85rem; border-radius: .5rem; font-size: .875rem; font-weight: 700; font-family: inherit; }
        .btn:hover { background: var(--verde-osc); }
        .pie { display: flex; justify-content: space-between; align-items: center; gap: 1rem; margin-top: 1.25rem;
            padding-top: 1rem; border-top: 1px solid var(--border); font-size: .78rem; }
        .pie form { margin: 0; }
        .enlace { background: none; border: 0; padding: 0; cursor: pointer; font-family: inherit;
            font-size: .78rem; color: var(--muted); text-decoration: none; }
        .enlace:hover { color: var(--fg); text-decoration: underline; }
        .enlace--salir { color: var(--danger); }
    </style>
</head>
<body>
    <main class="tarjeta">
        <div class="escudo">
            <img src="{{ asset('image/icon.png') }}" alt="Escudo del Departamento del Beni">
            <span>Gobierno Autónomo Departamental del Beni</span>
        </div>

        <h1>Cambiar contraseña</h1>
        <p class="sub">{{ auth()->user()->name }} · {{ auth()->user()->email }}</p>

        @if ($obligatorio)
            <div class="aviso" role="alert">
                Por seguridad, antes de seguir tenés que reemplazar la contraseña que te
                dieron por una que solo vos conozcas.
            </div>
        @endif

        <form method="POST" action="{{ route('password.cambiar.update') }}">
            @csrf
            @method('PUT')

            <div class="campo">
                <label for="password_actual">Contraseña actual</label>
                <x-campo-clave id="password_actual" autocomplete="current-password" required autofocus />
                @error('password_actual') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="campo">
                <label for="password">Contraseña nueva</label>
                <x-campo-clave id="password" autocomplete="new-password" minlength="8" required />
                <div class="ayuda">Mínimo 8 caracteres y distinta de la actual.</div>
                @error('password') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="campo">
                <label for="password_confirmation">Repetir contraseña nueva</label>
                <x-campo-clave id="password_confirmation" autocomplete="new-password" minlength="8" required />
            </div>

            <button type="submit" class="btn">Guardar contraseña</button>
        </form>

        <div class="pie">
            @if ($obligatorio)
                <span></span>
            @else
                <a href="{{ route('dashboard') }}" class="enlace">Volver</a>
            @endif
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="enlace enlace--salir">Cerrar sesión</button>
            </form>
        </div>
    </main>
</body>
</html>
