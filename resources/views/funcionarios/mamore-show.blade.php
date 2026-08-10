@extends('layouts.app')

@section('titulo', 'Funcionario ' . ($persona['ci'] ?? ''))

@php
    // La API entrega el contrato firmado dentro de la persona: null si no tiene.
    $contrato = is_array($persona['contrato'] ?? null) ? $persona['contrato'] : null;
    $enFunciones = (bool) ($persona['has_contract'] ?? ($contrato !== null));
    $ci = trim((string) ($persona['ci'] ?? ''));
@endphp

@section('contenido')
    <div class="cabecera">
        <h1>
            {{ $persona['full_name'] ?? 'Funcionario' }} · CI {{ $persona['full_ci'] ?? ($persona['ci'] ?? '—') }}
            <span class="pill {{ $enFunciones ? 'pill--ok' : 'pill--no' }}">
                {{ $enFunciones ? 'Con contrato' : 'Sin contrato' }}
            </span>
        </h1>
        <div class="acciones">
            <a href="{{ route('funcionarios.index', ['fuente' => 'mamore']) }}" class="btn btn--gris"><x-heroicon-o-arrow-left />Volver</a>
        </div>
    </div>

    <div class="aviso">Datos de solo lectura desde el sistema Mamoré.</div>

    <div class="form-grid">
        <div class="tarjeta">
            <h2>Datos personales</h2>
            {{-- Acá va la foto original (no la miniatura): es una sola imagen en la página. --}}
            <div class="ficha-foto">
                @if (!empty($persona['image']))
                    <img src="{{ $persona['image'] }}" alt="Foto de {{ $persona['full_name'] ?? 'la persona' }}">
                @else
                    <x-heroicon-o-user />
                @endif
            </div>
            <dl class="datos grid-2">
                <div><dt>Nombre completo</dt><dd>{{ $persona['full_name'] ?? '—' }}</dd></div>
                <div><dt>Cédula</dt><dd>{{ $persona['full_ci'] ?? ($persona['ci'] ?? '—') }}</dd></div>
                <div><dt>Emisión</dt><dd>{{ $persona['issued'] ?? '—' }}</dd></div>
                <div><dt>Género</dt><dd>{{ $persona['gender'] ?? '—' }}</dd></div>
                <div><dt>Fecha de nacimiento</dt><dd>{{ $persona['birthday'] ?? '—' }}</dd></div>
                {{-- <div><dt>Estado civil</dt><dd>{{ $persona['civil_status'] ?? '—' }}</dd></div> --}}
                {{-- <div><dt>Nº de hijos</dt><dd>{{ $persona['number_children'] ?? '—' }}</dd></div> --}}
                <div><dt>Profesión</dt><dd>{{ $persona['profession'] ?? '—' }}</dd></div>
            </dl>
        </div>

        <div class="tarjeta">
            <h2>Contacto</h2>
            <dl class="datos">
                <dt>Teléfono</dt><dd>{{ $persona['phone'] ?? '—' }}</dd>
                <dt>E-mail</dt><dd>{{ $persona['email'] ?? '—' }}</dd>
                <dt>Dirección</dt><dd>{{ $persona['address'] ?? '—' }}</dd>
                <dt>Ciudad</dt><dd>{{ $persona['city'] ?? '—' }}</dd>
                <dt>Departamento</dt><dd>{{ $persona['state'] ?? '—' }}</dd>
            </dl>
        </div>
    </div>

    {{-- El contrato va debajo de los datos personales: primero de quién se
         trata, después su situación laboral. --}}
    @if ($contrato)
        <div class="tarjeta">
            <h2>Contrato vigente</h2>
            <dl class="datos grid-2">
                <div><dt>Cargo</dt><dd>{{ ($contrato['cargo_completo'] ?? null) ?: (($contrato['cargo'] ?? null) ?: '—') }}</dd></div>
                <div><dt>Denominación</dt><dd>{{ ($contrato['denominacion'] ?? null) ?: '—' }}</dd></div>
                <div>
                    <dt>Dirección administrativa</dt>
                    <dd>
                        {{ $contrato['direccion_administrativa']['nombre'] ?? '—' }}
                        @if (!empty($contrato['direccion_administrativa']['sigla']))
                            <span class="persona-meta">({{ $contrato['direccion_administrativa']['sigla'] }})</span>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt>Unidad administrativa</dt>
                    <dd>
                        {{ $contrato['unidad_administrativa']['nombre'] ?? '—' }}
                        @if (!empty($contrato['unidad_administrativa']['sigla']))
                            <span class="persona-meta">({{ $contrato['unidad_administrativa']['sigla'] }})</span>
                        @endif
                    </dd>
                </div>
                {{-- <div><dt>Código</dt><dd>{{ ($contrato['code'] ?? null) ?: '—' }}</dd></div>
                <div><dt>Tipo de proceso</dt><dd>{{ ($contrato['procedure_type'] ?? null) ?: '—' }}</dd></div>
                <div><dt>Vigencia</dt><dd>{{ ($contrato['start'] ?? null) ?: '—' }} → {{ ($contrato['finish'] ?? null) ?: '—' }}</dd></div> --}}
                {{-- <div><dt>Lugar de trabajo</dt><dd>{{ ($contrato['work_location'] ?? null) ?: (($contrato['job_location'] ?? null) ?: '—') }}</dd></div> --}}
            </dl>
        </div>
    @else
        <div class="aviso aviso--advertencia">
            Esta persona no tiene un contrato firmado en Mamoré, así que no figura como funcionario en funciones.
        </div>
    @endif

    @include('funcionarios.paneles', [
        'ci' => $ci,
        'reporteUrl' => $personaLocal ? route('funcionarios.reporte', ['persona' => $personaLocal]) : null,
        'hayPersonaLocal' => (bool) $personaLocal,
        'origen' => 'mamore',
    ])
@endsection
