@extends('layouts.app')

@section('titulo', 'Editar ' . $sistema->nombre)

@section('contenido')
    <div class="cabecera">
        <h1>Editar sistema · {{ $sistema->nombre }}</h1>
        <a href="{{ route('tokens-api.show', $sistema) }}" class="btn btn--gris"><x-heroicon-o-arrow-left />Volver</a>
    </div>

    <form action="{{ route('tokens-api.update', $sistema) }}" method="POST">
        @csrf
        @method('PUT')
        @include('tokens-api._form')

        <div class="form-acciones">
            <button type="submit" class="btn"><x-heroicon-o-check />Guardar cambios</button>
            <a href="{{ route('tokens-api.show', $sistema) }}" class="btn btn--gris"><x-heroicon-o-x-mark />Cancelar</a>
        </div>
    </form>
@endsection
