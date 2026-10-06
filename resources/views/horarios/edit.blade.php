@extends('layouts.app')

@section('titulo', 'Editar horario')

@section('contenido')
    <div class="cabecera">
        <h1>Editar horario · {{ trim($horario->nombreHorario) }}</h1>
        <a href="{{ route('horarios.index') }}" class="btn btn--gris"><x-heroicon-o-arrow-left />Volver</a>
    </div>

    <form action="{{ route('horarios.update', $horario) }}" method="POST">
        @csrf
        @method('PUT')
        @include('horarios._form')

        <div class="form-acciones">
            <button type="submit" class="btn"><x-heroicon-o-check />Guardar cambios</button>
            <a href="{{ route('horarios.index') }}" class="btn btn--gris"><x-heroicon-o-x-mark />Cancelar</a>
        </div>
    </form>
@endsection
