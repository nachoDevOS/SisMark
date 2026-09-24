@extends('layouts.app')

@section('titulo', 'Configuración')

@section('contenido')
    <div class="cabecera">
        <div class="cabecera__titulo">
            <span class="cabecera__icono"><x-heroicon-o-cog-6-tooth /></span>
            <h1>Configuración</h1>
        </div>
    </div>

    <p class="ayuda" style="margin: -.4rem 0 1rem;">
        Parámetros que ajustan cómo trabaja el sistema. Cada sección se guarda por separado.
    </p>

    {{-- Índice de secciones: aparece cuando hay más de una, para saltar sin
         bajar por toda la página. --}}
    @if (count($grupos) > 1)
        <div class="rangos-rapidos" style="margin-bottom: 1rem;">
            @foreach ($grupos as $grupo => $parametros)
                <a href="#grupo-{{ $grupo }}" class="rango-chip">{{ \App\Models\Configuracion::GRUPOS[$grupo]['titulo'] }}</a>
            @endforeach
        </div>
    @endif

    @foreach ($grupos as $grupo => $parametros)
        @php
            $seccion = \App\Models\Configuracion::GRUPOS[$grupo];
        @endphp

        <section id="grupo-{{ $grupo }}" class="card card--padded" style="margin-bottom: 1rem;">
            <div style="display: flex; gap: .6rem; align-items: flex-start; margin-bottom: 1rem;">
                <span class="cabecera__icono"><x-dynamic-component :component="$seccion['icono']" /></span>
                <div>
                    <h2 style="margin: 0;">{{ $seccion['titulo'] }}</h2>
                    <p class="ayuda" style="margin: .2rem 0 0;">{{ $seccion['descripcion'] }}</p>
                </div>
            </div>

            <form action="{{ route('configuracion.update', $grupo) }}" method="POST">
                @csrf
                @method('PUT')

                @foreach ($parametros as $clave => $parametro)
                    @php
                        $guardado = $guardados->get($clave);
                    @endphp

                    <fieldset style="border: 0; padding: 0; margin: 0 0 1.25rem;">
                        <legend style="font-weight: 600; margin-bottom: .3rem;">{{ $parametro['etiqueta'] }}</legend>
                        <p class="ayuda" style="margin: 0 0 .6rem;">{{ $parametro['ayuda'] }}</p>

                        @include('configuracion.campos.'.$parametro['tipo'], [
                            'campo' => \App\Models\Configuracion::campo($clave),
                            'valor' => $guardado?->valor ?? ($parametro['defecto'] ?? null),
                            'parametro' => $parametro,
                        ])

                        <div class="ayuda" style="margin-top: .3rem;">
                            @if ($guardado)
                                Último cambio: {{ $guardado->updated_at?->format('d/m/Y H:i') }}
                                @if ($guardado->editor) por {{ $guardado->editor->name }}@endif.
                            @elseif (isset($parametro['defecto']))
                                Nunca se configuró: rige el valor por defecto.
                            @else
                                Nunca se configuró.
                            @endif
                        </div>
                    </fieldset>
                @endforeach

                <div class="form-acciones">
                    <button type="submit" class="btn"><x-heroicon-o-check />Guardar {{ mb_strtolower($seccion['titulo']) }}</button>
                </div>
            </form>
        </section>
    @endforeach
@endsection
