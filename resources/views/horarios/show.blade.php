@extends('layouts.app')

@section('titulo', 'Horario ' . trim($horario->nombreHorario))

@php
    $hm = fn ($valor) => $valor?->format('H:i') ?? '—';
@endphp

@section('contenido')
    <div class="cabecera">
        <div class="cabecera__titulo">
            <span class="cabecera__icono"><x-heroicon-o-clock /></span>
            <h1>{{ trim($horario->nombreHorario) ?: 'Horario' }}</h1>
        </div>
        <div class="acciones">
            @can('update', $horario)
                <a href="{{ route('horarios.edit', $horario) }}" class="btn"><x-heroicon-o-pencil-square />Editar</a>
            @endcan
            <a href="{{ route('horarios.index') }}" class="btn btn--gris"><x-heroicon-o-arrow-left />Volver</a>
        </div>
    </div>

    <div class="form-grid" style="grid-template-columns: 1fr 1fr;">
        <div class="tarjeta" style="grid-column: 1 / -1;">
            <h2>Descripción del horario</h2>
            <dl class="datos grid-2">
                <div>
                    <dt>Día</dt>
                    <dd>{{ $horario->nombre_dia }}</dd>
                </div>
                <div>
                    <dt>Nombre del horario</dt>
                    <dd>{{ trim($horario->nombreHorario) }}</dd>
                </div>
            </dl>
        </div>

        <div class="tarjeta">
            <h2>Entrada</h2>
            <dl class="datos grid-2">
                <div>
                    <dt>Hora de entrada</dt>
                    <dd>{{ $hm($horario->hEntrada) }}</dd>
                </div>
                <div>
                    <dt>Tolerancia de entrada</dt>
                    <dd>{{ $hm($horario->hTolerancia) }}</dd>
                </div>
                <div>
                    <dt>Mínima hora de entrada</dt>
                    <dd>{{ $hm($horario->eMinima) }}</dd>
                </div>
                <div>
                    <dt>Máxima hora de entrada</dt>
                    <dd>{{ $hm($horario->eMaxima) }}</dd>
                </div>
            </dl>
        </div>

        <div class="tarjeta">
            <h2>Salida</h2>
            <dl class="datos grid-2">
                <div>
                    <dt>Hora de salida</dt>
                    <dd>{{ $hm($horario->hSalida) }}</dd>
                </div>
                <div>
                    <dt>Tolerancia de salida</dt>
                    <dd>{{ $hm($horario->sTolerancia) }}</dd>
                </div>
                <div>
                    <dt>Mínima hora de salida</dt>
                    <dd>{{ $hm($horario->sMinima) }}</dd>
                </div>
                <div>
                    <dt>Máxima hora de salida</dt>
                    <dd>{{ $hm($horario->sMaxima) }}</dd>
                </div>
                <div>
                    <dt>¿Salida al día siguiente?</dt>
                    <dd>
                        <span class="pill {{ $horario->siguienteDia ? 'pill--advertencia' : 'pill--no' }}">
                            {{ $horario->siguienteDia ? 'Sí' : 'No' }}
                        </span>
                    </dd>
                </div>
            </dl>
        </div>

        <div class="tarjeta" style="grid-column: 1 / -1;">
            <h2>Horas trabajadas</h2>
            <dl class="datos grid-2">
                <div>
                    <dt>Horas trabajadas</dt>
                    <dd>{{ number_format((float) $horario->hTrabajadas, 2) }}</dd>
                </div>
            </dl>
        </div>
    </div>
@endsection
