@extends('layouts.app')

@section('titulo', 'Nuevo horario')

@section('contenido')
    <div class="cabecera">
        <h1>Nuevo horario</h1>
        <a href="{{ route('horarios.index') }}" class="btn btn--gris"><x-heroicon-o-arrow-left />Volver</a>
    </div>

    <form action="{{ route('horarios.store') }}" method="POST">
        @csrf
        @include('horarios._form')

        <div class="form-acciones">
            <button type="submit" class="btn"><x-heroicon-o-check />Guardar horario</button>
            <a href="{{ route('horarios.index') }}" class="btn btn--gris"><x-heroicon-o-x-mark />Cancelar</a>
        </div>
    </form>
@endsection
