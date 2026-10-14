@extends('layouts.app')

@section('titulo', 'Nuevo usuario')

@section('contenido')
    <div class="cabecera">
        <h1>Nuevo usuario</h1>
        <a href="{{ route('usuarios.index') }}" class="btn btn--gris"><x-heroicon-o-arrow-left />Volver</a>
    </div>

    <div class="card card--padded">
        <form action="{{ route('usuarios.store') }}" method="POST">
            @csrf
            @include('usuarios._form')

            <div class="form-acciones">
                <button type="submit" class="btn"><x-heroicon-o-check />Crear usuario</button>
                <a href="{{ route('usuarios.index') }}" class="btn btn--gris"><x-heroicon-o-x-mark />Cancelar</a>
            </div>
        </form>
    </div>
@endsection
