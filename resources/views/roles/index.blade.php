@extends('layouts.app')

@section('titulo', 'Roles')

@section('contenido')
    <div class="cabecera">
        <div class="cabecera__titulo">
            <span class="cabecera__icono"><x-heroicon-o-shield-check /></span>
            <h1>Roles y permisos</h1>
        </div>
        @can('create', \App\Models\Role::class)
            <a href="{{ route('roles.create') }}" class="btn"><x-heroicon-o-plus />Nuevo rol</a>
        @endcan
    </div>

    <x-tabla-filtros :action="route('roles.index')" :busqueda="$busqueda"
                     :por-pagina="$porPagina" placeholder="Buscar por nombre…" />

    <div class="card">
        <table>
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>Permisos</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($roles as $rol)
                    <tr>
                        <td><strong>{{ $rol->name }}</strong></td>
                        <td>{{ $rol->permissions_count }}</td>
                        <td>
                            <div class="acciones">
                                @can('update', $rol)
                                    <a href="{{ route('roles.edit', $rol) }}" class="btn-icon" title="Editar" aria-label="Editar"><x-heroicon-o-pencil-square /></a>
                                @endcan
                                @if ($rol->name !== 'super_admin' && auth()->user()->can('delete', $rol))
                                    <x-boton-eliminar :accion="route('roles.destroy', $rol)"
                                                      :mensaje="'Se elimina el rol «'.$rol->name.'». Los usuarios que lo tengan pierden sus permisos.'" />
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="vacio">No hay roles registrados.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="paginacion">{{ $roles->links() }}</div>
@endsection
