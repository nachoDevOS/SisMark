@extends('layouts.app')

@section('titulo', 'Usuarios')

@section('contenido')
    <div class="cabecera">
        <div class="cabecera__titulo">
            <span class="cabecera__icono"><x-heroicon-o-user /></span>
            <h1>Usuarios del panel</h1>
        </div>
        @can('create', \App\Models\User::class)
            <a href="{{ route('usuarios.create') }}" class="btn"><x-heroicon-o-plus />Nuevo usuario</a>
        @endcan
    </div>

    <x-tabla-filtros :action="route('usuarios.index')" :busqueda="$busqueda"
                     :por-pagina="$porPagina" placeholder="Buscar por nombre o correo…" />

    {{-- Un solo modal para inhabilitar, que cada fila abre con su usuario:
         pide el motivo, que queda en el historial. --}}
    <div x-data="{ abierto: false, url: '', nombre: '', motivo: '', enviando: false }"
         x-on:inhabilitar-usuario.window="abierto = true; url = $event.detail.url; nombre = $event.detail.nombre; motivo = ''; enviando = false; $nextTick(() => $refs.motivo.focus())">
        <div class="modal-fondo" x-show="abierto" x-cloak x-on:click.self="abierto = false"
             x-on:keydown.escape.window="abierto = false" role="dialog" aria-modal="true" aria-labelledby="titulo-inhabilitar">
            <div class="modal-caja modal-caja--ancha">
                <h2 id="titulo-inhabilitar">Inhabilitar usuario</h2>
                <p class="modal-bajada">
                    <strong x-text="nombre"></strong> no va a poder entrar al sistema hasta que se lo
                    vuelva a habilitar. Si tiene la sesión abierta, queda afuera en la próxima pantalla.
                </p>
                <form method="POST" :action="url" x-on:submit="enviando = true">
                    @csrf
                    <div class="campo">
                        <label for="motivo-inhabilitar">Motivo <span class="req">*</span></label>
                        <textarea id="motivo-inhabilitar" name="motivo" rows="2" required minlength="5" maxlength="500"
                                  x-ref="motivo" x-model="motivo" placeholder="Ej.: dejó la institución, vacaciones, sumario…"></textarea>
                        @error('motivo') <div class="error">{{ $message }}</div> @enderror
                    </div>
                    <div class="modal-acciones">
                        <button type="button" class="btn btn--gris" x-on:click="abierto = false" :disabled="enviando">Cancelar</button>
                        <button type="submit" class="btn btn--peligro" :disabled="motivo.trim().length < 5 || enviando">
                            <x-heroicon-o-no-symbol />Inhabilitar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="card">
        <table>
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>Correo</th>
                    <th>Roles</th>
                    <th>Estado</th>
                    <th>Creado</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($usuarios as $usuario)
                    <tr @class(['fila--inactiva' => ! $usuario->activo])>
                        <td><strong>{{ $usuario->name }}</strong></td>
                        <td>{{ $usuario->email }}</td>
                        <td>
                            @forelse ($usuario->roles as $rol)
                                <span class="pill pill--ok">{{ $rol->name }}</span>
                            @empty
                                <span style="color: #9ca3af;">Sin roles</span>
                            @endforelse
                        </td>
                        <td>
                            @if ($usuario->activo)
                                <span class="pill pill--ok">Activo</span>
                            @else
                                <span class="pill pill--no">Inactivo</span>
                            @endif
                        </td>
                        <td>{{ $usuario->created_at?->format('d/m/Y H:i') }}</td>
                        <td>
                            <div class="acciones">
                                @can('update', $usuario)
                                    <a href="{{ route('usuarios.edit', $usuario) }}" class="btn-icon" title="Editar" aria-label="Editar"><x-heroicon-o-pencil-square /></a>
                                @endcan
                                <div class="dropdown" x-data="{ open: false }" x-on:click.outside="open = false">
                                    <button type="button" class="dropdown-toggle" x-on:click="open = !open" aria-haspopup="true" :aria-expanded="open">
                                        Mas <x-heroicon-o-chevron-down />
                                    </button>
                                    <div class="dropdown-menu" x-show="open" x-cloak x-transition.opacity.duration.100ms>
                                        <a href="{{ route('usuarios.historial', $usuario) }}"><x-heroicon-o-clock />Historial de cambios</a>
                                        @can('update', $usuario)
                                            @if (! $usuario->activo)
                                                <form method="POST" action="{{ route('usuarios.habilitar', $usuario) }}">
                                                    @csrf
                                                    <button type="submit"><x-heroicon-o-check-circle />Habilitar</button>
                                                </form>
                                            @elseif ($usuario->isNot(auth()->user()))
                                                <button type="button"
                                                        x-on:click="open = false; $dispatch('inhabilitar-usuario', { url: @js(route('usuarios.inhabilitar', $usuario)), nombre: @js($usuario->name) })">
                                                    <x-heroicon-o-no-symbol />Inhabilitar
                                                </button>
                                            @endif
                                        @endcan
                                        @can('delete', $usuario)
                                            <div class="dropdown-menu__peligro">
                                                <x-boton-eliminar variante="menu"
                                                                  :accion="route('usuarios.destroy', $usuario)"
                                                                  :mensaje="'Se elimina el usuario «'.$usuario->name.'» y su acceso al sistema.'" />
                                            </div>
                                        @endcan
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="vacio">No hay usuarios.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="paginacion">{{ $usuarios->links() }}</div>
@endsection
