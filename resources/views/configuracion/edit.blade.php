@extends('layouts.app')

@section('titulo', 'Configuración')

@php
    $Configuracion = \App\Models\Configuracion::class;
    $mesActual = today()->startOfMonth();
    $mesTexto = fn ($fecha) => ucfirst($fecha->translatedFormat('F \d\e Y'));
@endphp

@section('contenido')
    <div class="cabecera">
        <div class="cabecera__titulo">
            <span class="cabecera__icono"><x-heroicon-o-cog-6-tooth /></span>
            <h1>Configuración</h1>
        </div>
    </div>

    <p class="ayuda" style="margin: -.4rem 0 1rem;">
        Parámetros que ajustan cómo trabaja el sistema. Cada cambio rige <strong>desde un mes</strong> y hasta que
        se cargue otro: lo anterior no se pisa, queda en el historial con quién lo cargó y por qué.
    </p>

    {{-- Índice de secciones: aparece cuando hay más de una, para saltar sin
         bajar por toda la página. --}}
    @if (count($grupos) > 1)
        <div class="rangos-rapidos" style="margin-bottom: 1rem;">
            @foreach ($grupos as $grupo => $parametros)
                <a href="#grupo-{{ $grupo }}" class="rango-chip">{{ $Configuracion::GRUPOS[$grupo]['titulo'] }}</a>
            @endforeach
        </div>
    @endif

    @foreach ($grupos as $grupo => $parametros)
        @php
            $seccion = $Configuracion::GRUPOS[$grupo];
            $versiones = $historial[$grupo] ?? collect();
            // La que rige hoy: la más nueva que ya empezó.
            $mesVigente = $versiones->keys()->first(fn (string $desde) => $desde <= $mesActual->toDateString());
            $programadas = $versiones->keys()->filter(fn (string $desde) => $desde > $mesActual->toDateString());
        @endphp

        <section id="grupo-{{ $grupo }}" class="card card--padded" style="margin-bottom: 1rem;">
            <div style="display: flex; gap: .6rem; align-items: flex-start; margin-bottom: 1rem;">
                <span class="cabecera__icono"><x-dynamic-component :component="$seccion['icono']" /></span>
                <div>
                    <h2 style="margin: 0;">{{ $seccion['titulo'] }}</h2>
                    <p class="ayuda" style="margin: .2rem 0 0;">{{ $seccion['descripcion'] }}</p>
                </div>
            </div>

            @if ($programadas->isNotEmpty())
                <div class="aviso" style="margin-bottom: 1rem;">
                    Hay un cambio programado desde
                    <strong>{{ $mesTexto(\Illuminate\Support\Carbon::parse($programadas->last())) }}</strong>.
                    Los valores de abajo son los que rigen hoy; mirá el historial para ver lo programado.
                </div>
            @endif

            <form action="{{ route('configuracion.update', $grupo) }}" method="POST">
                @csrf
                @method('PUT')

                @foreach ($parametros as $clave => $parametro)
                    <fieldset style="border: 0; padding: 0; margin: 0 0 1.25rem;">
                        <legend style="font-weight: 600; margin-bottom: .3rem;">{{ $parametro['etiqueta'] }}</legend>
                        <p class="ayuda" style="margin: 0 0 .6rem;">{{ $parametro['ayuda'] }}</p>

                        @include('configuracion.campos.'.$parametro['tipo'], [
                            'campo' => $Configuracion::campo($clave),
                            'valor' => $vigentes->get($clave)?->valor ?? ($parametro['defecto'] ?? null),
                            'parametro' => $parametro,
                        ])

                        @if (! $vigentes->has($clave))
                            <div class="ayuda" style="margin-top: .3rem;">
                                {{ isset($parametro['defecto']) ? 'Nunca se configuró: rige el valor por defecto.' : 'Nunca se configuró.' }}
                            </div>
                        @endif
                    </fieldset>
                @endforeach

                {{-- Desde cuándo rige y por qué: los dos obligatorios. Es lo que deja
                     el registro de qué regía cada mes. --}}
                <div class="tarjeta" style="margin-bottom: 1rem;">
                    <div class="grid-2">
                        <div class="campo">
                            <label for="vigente_desde-{{ $grupo }}">Rige desde el mes <span class="req">*</span></label>
                            <input type="month" id="vigente_desde-{{ $grupo }}" name="vigente_desde" required
                                   min="{{ $mesActual->format('Y-m') }}"
                                   value="{{ old('vigente_desde', $mesActual->format('Y-m')) }}">
                            <div class="ayuda">
                                Desde el día 1 de ese mes y hasta que se cargue otro cambio. Solo el mes en curso o
                                uno futuro: lo que ya rigió no se cambia.
                            </div>
                            @error('vigente_desde') <div class="error">{{ $message }}</div> @enderror
                        </div>
                        <div class="campo">
                            <label for="motivo-{{ $grupo }}">Motivo del cambio <span class="req">*</span></label>
                            <textarea id="motivo-{{ $grupo }}" name="motivo" rows="3" maxlength="500" required
                                      placeholder="Ej.: Resolución administrativa N.º 045/2026">{{ old('motivo') }}</textarea>
                            @error('motivo') <div class="error">{{ $message }}</div> @enderror
                        </div>
                    </div>
                </div>

                <div class="form-acciones">
                    <button type="submit" class="btn"><x-heroicon-o-check />Guardar {{ mb_strtolower($seccion['titulo']) }}</button>
                </div>
            </form>

            {{-- Historial: una fila por vigencia, de la más nueva a la más vieja,
                 con sus fechas de inicio y de fin tal como están guardadas. --}}
            <h3 style="margin: 1.5rem 0 .5rem;">Historial</h3>

            @if ($versiones->isEmpty())
                <p class="vacio" style="margin: 0;">Todavía no se cargó ningún cambio.</p>
            @else
                <div style="overflow-x: auto;">
                    <table class="tabla--compacta">
                        <thead>
                            <tr>
                                <th>Vigencia</th>
                                @foreach ($parametros as $parametro)
                                    <th>{{ $parametro['corta'] }}</th>
                                @endforeach
                                <th>Motivo</th>
                                <th>Cargado por</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($versiones as $desde => $delMes)
                                @php
                                    $muestra = $delMes->first();
                                    $estado = $desde > $mesActual->toDateString()
                                        ? 'programado'
                                        : ($desde === $mesVigente ? 'vigente' : 'anterior');
                                @endphp
                                <tr @class(['fila--inactiva' => $estado === 'anterior'])>
                                    <td style="white-space: nowrap;">
                                        <strong>{{ $muestra->vigente_desde->format('d/m/Y') }}</strong>
                                        @if ($muestra->vigente_hasta)
                                            al <strong>{{ $muestra->vigente_hasta->format('d/m/Y') }}</strong>
                                        @else
                                            <span class="ayuda">en adelante</span>
                                        @endif
                                        <div class="ayuda" style="margin: 0;">
                                            {{ $mesTexto($muestra->vigente_desde) }}@if ($muestra->vigente_hasta && ! $muestra->vigente_hasta->isSameMonth($muestra->vigente_desde)) a {{ mb_strtolower($mesTexto($muestra->vigente_hasta)) }}@endif
                                        </div>
                                        <div>
                                            @if ($estado === 'programado')
                                                <span class="pill pill--mini pill--info">Programado</span>
                                            @elseif ($estado === 'vigente')
                                                <span class="pill pill--mini pill--ok">Vigente</span>
                                            @else
                                                <span class="pill pill--mini pill--no">Anterior</span>
                                            @endif
                                        </div>
                                    </td>
                                    @foreach ($parametros as $clave => $parametro)
                                        <td>
                                            {{ $delMes->has($clave) ? $Configuracion::legible($clave, $delMes[$clave]->valor) : '—' }}
                                        </td>
                                    @endforeach
                                    <td style="max-width: 22rem;">{{ $muestra->motivo }}</td>
                                    <td style="white-space: nowrap;">
                                        {{ $muestra->registrador?->name ?? '—' }}
                                        <div class="ayuda">{{ $muestra->updated_at?->format('d/m/Y H:i') }}</div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endforeach
@endsection
