@extends('layouts.app')

@section('titulo', $equipo->nombre)

@section('contenido')
    <div class="cabecera">
        <h1>{{ $equipo->nombre }}</h1>
        <div class="acciones">
            @can('update', $equipo)
                <a href="{{ route('equipos.edit', $equipo) }}" class="btn btn--gris"><x-heroicon-o-pencil-square />Editar</a>
            @endcan
            @can('sync', $equipo)
                <a href="{{ route('equipos.sincronizacion.edit', $equipo) }}" class="btn btn--gris"><x-heroicon-o-clock />Sincronización</a>
            @endcan
            <a href="{{ route('equipos.index') }}" class="btn btn--gris"><x-heroicon-o-arrow-left />Volver</a>
        </div>
    </div>

    <div class="card card--padded">
        <dl class="datos">
            <dt>IP</dt>
            <dd>{{ $equipo->ip }}</dd>

            <dt>Puerto</dt>
            <dd>{{ $equipo->puerto }}</dd>

            <dt>COMM key</dt>
            <dd>{{ $equipo->comm_key }}</dd>

            <dt>Ubicación</dt>
            <dd>{{ $equipo->ubicacion ?? '—' }}</dd>

            <dt>Algoritmo</dt>
            <dd>{{ $equipo->algoritmo ?? 'Sin detectar' }}</dd>

            <dt>En línea</dt>
            <dd>
                <span class="pill {{ $equipo->en_linea ? 'pill--ok' : 'pill--no' }}">
                    {{ $equipo->en_linea ? 'Sí' : 'No' }}
                </span>
            </dd>

            <dt>Activo</dt>
            <dd>{{ $equipo->activo ? 'Sí' : 'No' }}</dd>

            <dt>Última sincronización</dt>
            <dd>{{ $equipo->ultima_sync?->format('d/m/Y H:i') ?? 'Nunca' }}</dd>

            {{-- Sincronización automática: los días y horas configurados y
                 cuándo corrió la tarea por última vez sobre este equipo.
                 Copiar las marcaciones no las borra del reloj. --}}
            <dt>Sincronización automática</dt>
            <dd>
                @if ($equipo->sync_automatica && $equipo->horariosSync())
                    <span class="pill pill--ok">Encendida</span>
                    {{ $equipo->nombresDiasSync() ? implode(', ', $equipo->nombresDiasSync()) : 'Todos los días' }},
                    a las {{ implode(' · ', $equipo->horariosSync()) }}
                @else
                    <span class="pill pill--no">Apagada</span>
                    Se sincroniza solo con el botón.
                @endif
            </dd>

            {{-- Dos fechas y no una: «corrió» es cuándo la tarea intentó, «trajo
                 datos» es hasta cuándo el reloj contestó. Verlas separadas es lo
                 que delata al equipo que se sincroniza todos los días sin traer
                 nada —cable suelto, reloj colgado—, que con una sola fecha
                 parecía sano. --}}
            <dt>Última corrida automática</dt>
            <dd>{{ $equipo->sync_ultimo_automatico?->format('d/m/Y H:i') ?? 'Nunca' }}</dd>

            <dt>Último dato traído</dt>
            <dd>
                @if ($equipo->sync_ultimo_exito)
                    {{ $equipo->sync_ultimo_exito->format('d/m/Y H:i') }}
                    @if ($equipo->sync_ultimo_automatico?->gt($equipo->sync_ultimo_exito->copy()->addDay()))
                        <span class="pill pill--advertencia">El equipo no responde desde entonces</span>
                    @endif
                @else
                    Nunca
                @endif
            </dd>
        </dl>
    </div>
@endsection
