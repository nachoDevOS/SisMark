@extends('layouts.app')

@section('titulo', 'Editar usuario')

@section('contenido')
    <div class="cabecera">
        <h1>Editar «{{ $usuario->name }}»</h1>
        <a href="{{ route('usuarios.index') }}" class="btn btn--gris"><x-heroicon-o-arrow-left />Volver</a>
    </div>

    <div class="card card--padded">
        <form action="{{ route('usuarios.update', $usuario) }}" method="POST">
            @csrf
            @method('PUT')
            @include('usuarios._form')

            <div class="form-acciones">
                <button type="submit" class="btn"><x-heroicon-o-check />Guardar cambios</button>
                <a href="{{ route('usuarios.index') }}" class="btn btn--gris"><x-heroicon-o-x-mark />Cancelar</a>
            </div>
        </form>
    </div>
@endsection
