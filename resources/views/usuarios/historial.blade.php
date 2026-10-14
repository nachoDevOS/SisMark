@extends('layouts.app')

@section('titulo', 'Historial de '.$usuario->name)

@php
    /** Color e icono de cada acción en la línea de tiempo. */
    $estilos = [
        \App\Models\UserHistorial::ACCION_CREADO => ['clase' => 'hist-punto--creado', 'icono' => 'heroicon-o-plus'],
        \App\Models\UserHistorial::ACCION_ACTUALIZADO => ['clase' => 'hist-punto--actualizado', 'icono' => 'heroicon-o-pencil-square'],
        \App\Models\UserHistorial::ACCION_CAMBIO_PASSWORD => ['clase' => 'hist-punto--password', 'icono' => 'heroicon-o-key'],
        \App\Models\UserHistorial::ACCION_INHABILITADO => ['clase' => 'hist-punto--inhabilitado', 'icono' => 'heroicon-o-no-symbol'],
        \App\Models\UserHistorial::ACCION_HABILITADO => ['clase' => 'hist-punto--creado', 'icono' => 'heroicon-o-check-circle'],
        \App\Models\UserHistorial::ACCION_ELIMINADO => ['clase' => 'hist-punto--eliminado', 'icono' => 'heroicon-o-trash'],
    ];
@endphp

@section('contenido')
    <div class="cabecera">
        <div class="cabecera__titulo">
            <span class="cabecera__icono"><x-heroicon-o-clock /></span>
            <h1>Historial de cambios</h1>
        </div>
        <a href="{{ route('usuarios.index') }}" class="btn btn--gris"><x-heroicon-o-arrow-left />Volver a usuarios</a>
    </div>

    <div class="card card--padded hist-usuario">
        <span class="cuenta__avatar hist-usuario__avatar"><img src="{{ $usuario->avatar_url }}" alt=""></span>
        <div>
            <div class="persona-nombre">{{ $usuario->name }}</div>
            <div class="persona-meta">{{ $usuario->email }} · {{ $usuario->roles->pluck('name')->implode(', ') ?: 'Sin roles' }}</div>
        </div>
        @if ($usuario->trashed())
            <span class="pill pill--no hist-usuario__estado">Eliminado</span>
        @elseif (! $usuario->activo)
            <span class="pill pill--advertencia hist-usuario__estado">Inactivo</span>
        @else
            <span class="pill pill--ok hist-usuario__estado">Activo</span>
        @endif
    </div>

    <div class="card card--padded" style="margin-top: 1rem;">
        @if ($historial->isEmpty())
            <div class="vacio">Este usuario todavía no tiene eventos registrados.</div>
        @else
            <ol class="hist">
                @foreach ($historial as $evento)
                    @php
                        $estilo = $estilos[$evento->accion] ?? ['clase' => '', 'icono' => 'heroicon-o-list-bullet'];
                        $cambios = $evento->cambios();
                        $antes = $evento->antes ?? [];
                        $despues = $evento->despues ?? [];
                        $campos = array_unique([...array_keys($antes), ...array_keys($despues)]);
                    @endphp
                    <li class="hist__evento">
                        <span class="hist-punto {{ $estilo['clase'] }}"><x-dynamic-component :component="$estilo['icono']" /></span>
                        <div class="hist__tarjeta">
                            <div class="hist__titulo">{{ \App\Models\UserHistorial::ETIQUETAS[$evento->accion] ?? ucfirst($evento->accion) }}</div>
                            <div class="persona-meta">
                                {{ $evento->created_at->isoFormat('D [de] MMMM [de] YYYY [a las] HH:mm') }}
                                · {{ $evento->created_at->diffForHumans() }}
                                · por <strong>{{ $evento->autor?->name ?? 'Sistema' }}</strong>
                            </div>

                            @if ($evento->accion === \App\Models\UserHistorial::ACCION_ACTUALIZADO)
                                <div class="hist__resumen">
                                    Se modificó {{ count($cambios) === 1 ? '1 campo' : count($cambios).' campos' }}:
                                    @foreach (array_keys($cambios) as $campo)
                                        <span class="pill pill--info pill--mini">{{ $campo }}</span>
                                    @endforeach
                                </div>
                            @endif

                            <table class="tabla--compacta hist__tabla">
                                <thead>
                                    <tr><th>Campo</th><th>Antes</th><th>Después</th></tr>
                                </thead>
                                <tbody>
                                    @foreach ($campos as $campo)
                                        @php($cambio = array_key_exists($campo, $cambios))
                                        <tr @class(['hist__fila--cambio' => $cambio, 'fila--inactiva' => ! $cambio])>
                                            <td>{{ $campo }}</td>
                                            <td>@if ($cambio)<span class="hist__antes">{{ $antes[$campo] ?? '—' }}</span>@else{{ $antes[$campo] ?? '—' }}@endif</td>
                                            <td>@if ($cambio)<span class="hist__despues">→ {{ $despues[$campo] ?? '—' }}</span>@else{{ $despues[$campo] ?? '—' }}@endif</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </li>
                @endforeach
            </ol>

            <div class="paginacion">{{ $historial->links() }}</div>
        @endif
    </div>
@endsection
