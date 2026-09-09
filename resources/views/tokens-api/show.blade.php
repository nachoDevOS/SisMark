@extends('layouts.app')

@section('titulo', $sistema->nombre)

@php
    /**
     * Cómo se nombran los alcances de un token.
     *
     * Los emitidos desde esta pantalla los traen todos, y listar los cinco cada
     * vez es ruido: se dice «Toda la API». Los acotados —los que salen por
     * consola con `--alcance=`— sí se enumeran, que ahí el detalle es el dato.
     */
    $alcancesDelToken = function (array $alcances): string {
        $todos = array_keys(App\Models\SistemaExterno::ALCANCES);

        return empty(array_diff($todos, $alcances))
            ? 'Toda la API'
            : implode(', ', $alcances);
    };
@endphp

@section('contenido')
    <div class="cabecera">
        <div class="cabecera__titulo">
            <span class="cabecera__icono"><x-heroicon-o-key /></span>
            <h1>{{ $sistema->nombre }}</h1>
            <span class="pill {{ $sistema->activo ? 'pill--ok' : 'pill--no' }}">
                {{ $sistema->activo ? 'Activo' : 'Inactivo' }}
            </span>
        </div>
        <div class="acciones">
            @can('update', $sistema)
                <form action="{{ route('tokens-api.toggle', $sistema) }}" method="POST" style="margin: 0;">
                    @csrf
                    <button type="submit" class="btn {{ $sistema->activo ? 'btn--gris' : '' }}">
                        @if ($sistema->activo)
                            <x-heroicon-o-pause />Desactivar
                        @else
                            <x-heroicon-o-play />Activar
                        @endif
                    </button>
                </form>
                <a href="{{ route('tokens-api.edit', $sistema) }}" class="btn"><x-heroicon-o-pencil-square />Editar</a>
            @endcan
            <a href="{{ route('tokens-api.index') }}" class="btn btn--gris"><x-heroicon-o-arrow-left />Volver</a>
        </div>
    </div>

    {{-- ===== El token recién emitido =====
         Va en el flash y no en la tabla porque la base guarda solo su hash: esta
         es la única vez que se puede leer. Si se pierde hay que emitir otro, y
         eso corta al consumidor hasta que cargue el nuevo. --}}
    @if (session('token_emitido'))
        <div class="card" style="border: 2px solid #16a34a; margin-bottom: 1rem;">
            <div style="padding: 1rem;">
                <h2 style="margin: 0 0 .35rem; font-size: 1rem;">Token emitido</h2>
                <p class="ayuda" style="margin: 0 0 .75rem;">
                    <strong>Copialo ahora.</strong> La base guarda solo su hash y no se vuelve a
                    mostrar. Va en el <code>.env</code> del sistema consumidor.
                </p>

                <div style="display: flex; gap: .5rem; align-items: stretch;">
                    <input type="text" id="token-emitido" readonly value="{{ session('token_emitido') }}"
                           style="flex: 1; font-family: ui-monospace, monospace; font-size: .8rem;">
                    <button type="button" class="btn" id="btn-copiar"><x-heroicon-o-clipboard />Copiar</button>
                </div>

                <p class="ayuda" style="margin: .75rem 0 0;">
                    {{ $alcancesDelToken(session('token_alcances', [])) }}
                </p>

                @if (session('token_revocados') > 0)
                    <div class="aviso aviso--advertencia" style="margin-top: .75rem;">
                        Se revocó el token anterior: el sistema queda cortado hasta que cargue este.
                    </div>
                @endif
            </div>
        </div>

        <script>
            document.getElementById('btn-copiar').addEventListener('click', async function () {
                const campo = document.getElementById('token-emitido');
                try {
                    await navigator.clipboard.writeText(campo.value);
                } catch (e) {
                    // Sin permiso de portapapeles (o sin HTTPS): se selecciona
                    // para que se pueda copiar a mano con Ctrl+C.
                    campo.select();
                    return;
                }
                this.textContent = 'Copiado';
            });
        </script>
    @endif

    <div class="form-grid" style="grid-template-columns: 1fr 1fr;">
        <div class="tarjeta" style="grid-column: 1 / -1;">
            <h2>Datos del sistema</h2>
            <dl class="datos grid-2">
                <div>
                    <dt>Nombre corto</dt>
                    <dd><code>{{ $sistema->slug }}</code></dd>
                </div>
                <div>
                    <dt>Credencial</dt>
                    <dd>
                        @if ($tokens->isNotEmpty())
                            <span class="pill pill--info">Con token</span>
                        @else
                            <span class="pill pill--neutro">Sin token</span>
                        @endif
                    </dd>
                </div>
                <div style="grid-column: 1 / -1;">
                    <dt>Observaciones</dt>
                    <dd>{{ $sistema->observaciones ?: '—' }}</dd>
                </div>
            </dl>
        </div>

        {{-- ===== Token vivo ===== --}}
        <div class="tarjeta" style="grid-column: 1 / -1;">
            <h2>Token</h2>

            @if ($tokens->isEmpty())
                <p class="ayuda">
                    Este sistema todavía no tiene credencial, así que no puede consultar la API
                    aunque esté activo.
                </p>
            @else
                <table class="tabla--compacta">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Alcances</th>
                            <th>Emitido</th>
                            <th>Último uso</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tokens as $token)
                            <tr>
                                <td><strong>{{ $token->name }}</strong></td>
                                <td>{{ $alcancesDelToken($token->abilities ?? []) }}</td>
                                <td>{{ $token->created_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                <td>
                                    {{-- Sin uso es la señal de que el token nunca llegó a
                                         pegarse del otro lado. --}}
                                    {{ $token->last_used_at?->format('d/m/Y H:i') ?? 'Nunca' }}
                                </td>
                                <td>
                                    @can('token', $sistema)
                                        <x-boton-eliminar :accion="route('tokens-api.tokens.revocar', [$sistema, $token->id])"
                                                          :mensaje="'Se revoca el token de «'.$sistema->nombre.'». El sistema queda sin acceso hasta que se le emita otro.'" />
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- ===== Emisión ===== --}}
        @can('token', $sistema)
            <div class="tarjeta" style="grid-column: 1 / -1;">
                <h2>Emitir token</h2>

                <div class="aviso aviso--advertencia" style="margin-bottom: 1rem;">
                    Un sistema tiene <strong>un solo token vivo</strong>: emitir uno nuevo revoca el
                    anterior y el consumidor queda cortado hasta que cargue el nuevo. El token se
                    muestra una sola vez.
                </div>

                <form action="{{ route('tokens-api.tokens.emitir', $sistema) }}" method="POST">
                    @csrf

                    {{-- El token sale con acceso a toda la API. Elegir alcances de a uno
                         obligaba a saber de antemano qué endpoints va a usar el
                         consumidor, que es lo que no se sabe al darlo de alta. El corte
                         fino queda en `php artisan sismark:token --alcance=…`. --}}
                    <p class="ayuda" style="margin-top: -.35rem;">
                        El token da acceso a toda la API de asistencia: marcaciones, asistencia
                        procesada, licencias y horarios.
                    </p>

                    {{-- La contraseña no es una formalidad: un token abre la asistencia
                         de todo el personal y no caduca, así que una sesión dejada
                         abierta en un escritorio no alcanza para entregar uno. --}}
                    <div class="campo" style="max-width: 20rem;">
                        <label for="password">Tu contraseña <span class="req">*</span></label>
                        <input type="password" id="password" name="password" autocomplete="current-password" required>
                        @error('password') <div class="error">{{ $message }}</div> @enderror
                    </div>

                    <div class="form-acciones">
                        <button type="submit" class="btn" @disabled(! $sistema->activo)>
                            <x-heroicon-o-key />Emitir token
                        </button>
                    </div>

                    @unless ($sistema->activo)
                        <p class="ayuda">Activá el sistema antes de emitirle un token.</p>
                    @endunless
                </form>
            </div>
        @endcan

        {{-- ===== Bitácora ===== --}}
        <div class="tarjeta" style="grid-column: 1 / -1;">
            <h2>Bitácora</h2>
            <p class="ayuda" style="margin-top: -.35rem;">
                Quién entregó o cortó el acceso, y cuándo. No se edita ni se borra.
            </p>

            <table class="tabla--compacta">
                <thead>
                    <tr>
                        <th>Cuándo</th>
                        <th>Qué</th>
                        <th>Quién</th>
                        <th>Alcances</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($bitacora as $entrada)
                        <tr>
                            <td>{{ $entrada->created_at?->format('d/m/Y H:i') ?? '—' }}</td>
                            <td>{{ $entrada->accion_etiqueta }}</td>
                            <td>{{ $entrada->usuario?->name ?? '—' }}</td>
                            <td>{{ $entrada->alcances ?: '—' }}</td>
                            <td>{{ $entrada->ip ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="vacio">Todavía no se emitió ningún token.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
