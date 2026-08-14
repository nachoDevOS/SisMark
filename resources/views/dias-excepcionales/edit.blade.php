@extends('layouts.app')

@section('titulo', 'Editar día excepcional')

@section('contenido')
    <div class="cabecera">
        <h1>Editar día del {{ $diaExcepcional->fecha?->format('d/m/Y') }}</h1>
        <a href="{{ route('dias-excepcionales.index') }}" class="btn btn--gris"><x-heroicon-o-arrow-left />Volver</a>
    </div>

    <div class="card card--padded">
        {{-- `multipart/form-data`: el formulario sube el respaldo del día. --}}
        <form action="{{ route('dias-excepcionales.update', $diaExcepcional) }}" method="POST" enctype="multipart/form-data">
            @csrf
            @method('PUT')
            @include('dias-excepcionales._form')

            <div class="form-acciones">
                <button type="submit" class="btn"><x-heroicon-o-check />Guardar cambios</button>
                <a href="{{ route('dias-excepcionales.index') }}" class="btn btn--gris"><x-heroicon-o-x-mark />Cancelar</a>
            </div>
        </form>
    </div>
@endsection
