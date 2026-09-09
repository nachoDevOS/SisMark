@extends('layouts.app')

@section('titulo', 'Nuevo sistema')

@section('contenido')
    <div class="cabecera">
        <h1>Nuevo sistema</h1>
        <a href="{{ route('tokens-api.index') }}" class="btn btn--gris"><x-heroicon-o-arrow-left />Volver</a>
    </div>

    <form action="{{ route('tokens-api.store') }}" method="POST">
        @csrf
        @include('tokens-api._form')

        <div class="form-acciones">
            <button type="submit" class="btn"><x-heroicon-o-check />Registrar sistema</button>
            <a href="{{ route('tokens-api.index') }}" class="btn btn--gris"><x-heroicon-o-x-mark />Cancelar</a>
        </div>
    </form>
@endsection
