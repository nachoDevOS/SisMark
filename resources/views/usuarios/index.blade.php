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

    <div class="card">
        <table>
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>Correo</th>
                    <th>Roles</th>
                    <th>Creado</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($usuarios as $usuario)
                    <tr>
                        <td><strong>{{ $usuario->name }}</strong></td>
                        <td>{{ $usuario->email }}</td>
                        <td>
                            @forelse ($usuario->roles as $rol)
                                <span class="pill pill--ok">{{ $rol->name }}</span>
                            @empty
                                <span style="color: #9ca3af;">Sin roles</span>
                            @endforelse
                        </td>
                        <td>{{ $usuario->created_at?->format('d/m/Y H:i') }}</td>
                        <td>
                            <div class="acciones">
                                @can('update', $usuario)
                                    <a href="{{ route('usuarios.edit', $usuario) }}" class="btn-icon" title="Editar" aria-label="Editar"><x-heroicon-o-pencil-square /></a>
                                @endcan
                                @can('delete', $usuario)
                                <div class="dropdown" x-data="{ open: false }" x-on:click.outside="open = false">
                                    <button type="button" class="dropdown-toggle" x-on:click="open = !open" aria-haspopup="true" :aria-expanded="open">
                                        Mas <x-heroicon-o-chevron-down />
                                    </button>
                                    <div class="dropdown-menu" x-show="open" x-cloak x-transition.opacity.duration.100ms>
                                        <div class="dropdown-menu__peligro">
                                            <x-boton-eliminar variante="menu"
                                                              :accion="route('usuarios.destroy', $usuario)"
                                                              :mensaje="'Se elimina el usuario «'.$usuario->name.'» y su acceso al sistema.'" />
                                        </div>
                                    </div>
                                </div>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="vacio">No hay usuarios.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="paginacion">{{ $usuarios->links() }}</div>
@endsection
