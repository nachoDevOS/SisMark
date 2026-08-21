@extends('layouts.app')

@section('titulo', 'Funcionario ' . trim($persona->ci))

@php
    $sexos = ['F' => 'Femenino', 'M' => 'Masculino'];
    $estadosCiviles = ['S' => 'Soltero(a)', 'C' => 'Casado(a)', 'D' => 'Divorciado(a)', 'V' => 'Viudo(a)'];
@endphp

@section('contenido')
    <div class="cabecera">
        <h1>{{ $persona->nombre_completo ?: 'Funcionario' }} · CI {{ trim($persona->ci) }}</h1>
        <div class="acciones">
            @can('create', \App\Models\Licencia::class)
                <a href="{{ route('licencias.create', ['ci' => trim($persona->ci)]) }}" class="btn">
                    <x-heroicon-o-clipboard-document-check />Registrar licencia
                </a>
            @endcan
            <a href="{{ route('funcionarios.index') }}" class="btn btn--gris"><x-heroicon-o-arrow-left />Volver</a>
        </div>
    </div>

    <div class="form-grid">
        <div class="tarjeta">
            <h2>Datos personales</h2>
            <dl class="datos grid-2">
                <div>
                    <dt>Nro. carnet de identidad</dt>
                    <dd>
                        {{ trim($persona->ci) }}
                        @if ($extension)
                            <span class="persona-meta">{{ $extension }}</span>
                        @endif
                    </dd>
                </div>
                <div>
                    {{-- La extensión es el departamento que emitió el carnet y
                         solo la tiene Mamoré: `personas` no guarda la columna.
                         Si la API no responde queda en «—» y la ficha se muestra
                         igual. --}}
                    <dt>Extensión</dt>
                    <dd>{{ $extension ?: '—' }}</dd>
                </div>
                <div>
                    <dt>Expedido en</dt>
                    <dd>{{ trim((string) $persona->origenId) ?: '—' }}</dd>
                </div>
                <div>
                    <dt>Apellido paterno</dt>
                    <dd>{{ trim((string) $persona->paterno) ?: '—' }}</dd>
                </div>
                <div>
                    <dt>Apellido materno</dt>
                    <dd>{{ trim((string) $persona->materno) ?: '—' }}</dd>
                </div>
                <div>
                    <dt>Nombres</dt>
                    <dd>{{ trim((string) $persona->nombres) ?: '—' }}</dd>
                </div>
                <div>
                    <dt>Fecha de nacimiento</dt>
                    <dd>{{ $persona->fechaNacimiento?->format('d/m/Y') ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Lugar de nacimiento</dt>
                    <dd>{{ trim((string) $persona->lugarNacimiento) ?: '—' }}</dd>
                </div>
                <div>
                    <dt>Sexo</dt>
                    <dd>{{ $sexos[$persona->sexo] ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Estado civil</dt>
                    <dd>{{ $estadosCiviles[$persona->estadoCivil] ?? '—' }}</dd>
                </div>
            </dl>
        </div>

        <div class="tarjeta">
            <h2>Estudios</h2>
            <dl class="datos">
                <dt>Profesión</dt>
                <dd>{{ trim((string) $persona->profesion?->nombreProfesion) ?: '—' }}</dd>

                <dt>Nivel</dt>
                <dd>{{ trim((string) $persona->nivelEstudio) ?: '—' }}</dd>
            </dl>
        </div>

        <div class="tarjeta">
            <h2>Contactos</h2>
            <dl class="datos">
                <dt>Teléfonos</dt>
                <dd>{{ trim((string) $persona->telefono) ?: '—' }}</dd>

                <dt>Dirección</dt>
                <dd>{{ trim((string) $persona->direccion) ?: '—' }}</dd>

                <dt>E-mail</dt>
                <dd>{{ trim((string) $persona->correo) ?: '—' }}</dd>
            </dl>
        </div>

        <div class="tarjeta">
            <h2>Control de asistencia</h2>
            <dl class="datos">
                <dt>PIN reloj lector de huellas</dt>
                <dd>{{ trim((string) $persona->pinReloj) ?: 'Sin PIN' }}</dd>

                <dt>Puede marcar con contraseña</dt>
                <dd>
                    <span class="pill {{ $persona->marcaDirecta ? 'pill--ok' : 'pill--no' }}">
                        {{ $persona->marcaDirecta ? 'Sí' : 'No' }}
                    </span>
                </dd>
            </dl>
        </div>
    </div>

    @include('funcionarios.paneles', [
        'ci' => trim((string) $persona->ci),
        'reporteUrl' => route('funcionarios.reporte', ['persona' => $persona]),
        'hayPersonaLocal' => true,
        'origen' => 'local',
    ])

@endsection
