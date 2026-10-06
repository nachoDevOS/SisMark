@extends('layouts.app')

@section('titulo', 'Turno '.$turno->nombre)

@section('contenido')
    <div class="cabecera">
        <div class="cabecera__titulo">
            <span class="cabecera__icono"><x-heroicon-o-rectangle-stack /></span>
            <h1>{{ $turno->nombre }}</h1>
        </div>
        <div class="acciones">
            @can('update', $turno)
                <form action="{{ route('turnos.sugerido', $turno) }}" method="POST" style="margin: 0;">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="btn btn--gris">
                        <x-heroicon-o-star />{{ $turno->sugerido ? 'Quitar de sugeridos' : 'Marcar como sugerido' }}
                    </button>
                </form>
            @endcan
            <a href="{{ route('turnos.index') }}" class="btn btn--gris"><x-heroicon-o-arrow-left />Volver</a>
        </div>
    </div>

    <div class="tarjeta">
        <h2>Datos del turno</h2>
        <dl class="datos grid-2">
            <div>
                <dt>Nombre</dt>
                <dd>{{ $turno->nombre }}</dd>
            </div>
            <div>
                <dt>¿Turno sugerido?</dt>
                <dd>
                    <span class="pill {{ $turno->sugerido ? 'pill--info' : 'pill--neutro' }}">
                        {{ $turno->sugerido ? 'Sí' : 'No' }}
                    </span>
                    @if ($turno->sugerido)
                        <small style="display: block; color: var(--muted); margin-top: .35rem;">
                            Se ofrece en Mamoré para elegir al dar de alta un contrato.
                        </small>
                    @endif
                </dd>
            </div>
            <div>
                <dt>Días</dt>
                <dd>{{ $turno->dias_cubiertos ?: '—' }}</dd>
            </div>
            <div>
                <dt>Horas semanales</dt>
                <dd>{{ number_format($turno->horas_semanales, 2) }}</dd>
            </div>
            @if ($turno->observacion)
                <div style="grid-column: 1 / -1;">
                    <dt>Observación</dt>
                    <dd>{{ $turno->observacion }}</dd>
                </div>
            @endif
        </dl>
    </div>

    <div class="card" style="margin-top: 1rem; overflow-x: auto;">
        @include('turnos._horarios', ['horarios' => $turno->horarios])
    </div>
@endsection
