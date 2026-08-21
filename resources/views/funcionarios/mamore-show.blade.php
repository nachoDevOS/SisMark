@extends('layouts.app')

@section('titulo', 'Funcionario ' . ($persona['ci'] ?? ''))

@php
    // La API entrega el contrato firmado dentro de la persona: null si no tiene.
    $contrato = is_array($persona['contrato'] ?? null) ? $persona['contrato'] : null;
    $enFunciones = (bool) ($persona['has_contract'] ?? ($contrato !== null));
    $ci = trim((string) ($persona['ci'] ?? ''));

    // Una fecha del contrato como se escribe acá. En Mamoré `start` y `finish`
    // son columnas `date` sin cast, así que llegan como «2024-01-15».
    //
    // Si el valor viniera con otra forma se muestra crudo en vez de ocultarlo:
    // es una fecha de vigencia, y que se vea rara invita a corregirla en el
    // origen, mientras que un «—» la haría pasar por inexistente.
    $fechaContrato = static function (mixed $valor): ?string {
        if (blank($valor)) {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse((string) $valor)->format('d/m/Y');
        } catch (\Throwable) {
            return (string) $valor;
        }
    };
@endphp

@section('contenido')
    <div class="cabecera">
        <h1>
            {{ $persona['full_name'] ?? 'Funcionario' }} · CI {{ $persona['full_ci'] ?? ($persona['ci'] ?? '—') }}
            <span class="pill {{ $enFunciones ? 'pill--ok' : 'pill--no' }}">
                {{ $enFunciones ? 'Con contrato' : 'Sin contrato' }}
            </span>
            {{-- De dónde salen estos datos. Antes era una franja de aviso
                 debajo del título que ocupaba un renglón entero de la pantalla
                 para algo que no cambia nunca; acá dice lo mismo al lado del
                 estado, que es donde se mira. El «solo lectura» queda en el
                 `title`: la ficha no tiene un solo botón que escriba, así que
                 no hace falta gritarlo. --}}
            <span class="pill pill--neutro" title="Datos de solo lectura desde el sistema Mamoré">Mamoré</span>
        </h1>
        <div class="acciones">
            <a href="{{ route('funcionarios.index', ['fuente' => 'mamore']) }}" class="btn btn--gris"><x-heroicon-o-arrow-left />Volver</a>
        </div>
    </div>

    {{-- Datos personales y contacto van en **una sola tarjeta**: son la misma
         cosa —quién es la persona— y separarlos en dos dejaba a la de contacto,
         que tiene cinco campos contra siete, flotando al lado de un hueco.

         Juntos y a lo ancho, cada bloque usa toda la fila y la tarjeta deja de
         tener aire muerto. --}}
    <div class="tarjeta">
        <h2>Datos personales</h2>

        <div class="ficha-persona">
            {{-- Acá va la foto original (no la miniatura): es una sola imagen en la página. --}}
            <div class="ficha-foto">
                @if (!empty($persona['image']))
                    <img src="{{ $persona['image'] }}" alt="Foto de {{ $persona['full_name'] ?? 'la persona' }}">
                @else
                    <x-heroicon-o-user />
                @endif
            </div>

            <dl class="datos grid-3 ficha-persona__datos">
                <div><dt>Nombre completo</dt><dd>{{ $persona['full_name'] ?? '—' }}</dd></div>
                {{-- La extensión no va como campo aparte: Mamoré ya la manda
                     concatenada acá dentro, en `full_ci` («7633685 BE»), y
                     repetirla sola gastaba una fila entera para decir lo mismo
                     dos veces. --}}
                <div><dt>Cédula</dt><dd>{{ $persona['full_ci'] ?? ($persona['ci'] ?? '—') }}</dd></div>
                <div><dt>Género</dt><dd>{{ $persona['gender'] ?? '—' }}</dd></div>
                <div><dt>Fecha de nacimiento</dt><dd>{{ $persona['birthday'] ?? '—' }}</dd></div>
                {{-- <div><dt>Estado civil</dt><dd>{{ $persona['civil_status'] ?? '—' }}</dd></div> --}}
                {{-- <div><dt>Nº de hijos</dt><dd>{{ $persona['number_children'] ?? '—' }}</dd></div> --}}
                <div><dt>Profesión</dt><dd>{{ $persona['profession'] ?? '—' }}</dd></div>
            </dl>
        </div>

        <div class="ficha-bloque">
            <h3>Contacto</h3>
            <dl class="datos grid-3">
                <div><dt>Teléfono</dt><dd>{{ $persona['phone'] ?? '—' }}</dd></div>
                <div><dt>E-mail</dt><dd>{{ $persona['email'] ?? '—' }}</dd></div>
                <div><dt>Dirección</dt><dd>{{ $persona['address'] ?? '—' }}</dd></div>
                <div><dt>Ciudad</dt><dd>{{ $persona['city'] ?? '—' }}</dd></div>
                <div><dt>Departamento</dt><dd>{{ $persona['state'] ?? '—' }}</dd></div>
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
                {{-- Vigencia del contrato: desde cuándo rige y hasta cuándo.

                     Es lo que decide si esta persona sigue siendo funcionario
                     hoy, y también qué días de asistencia se le controlan: un
                     día que ningún contrato cubre no se procesa, aunque tenga
                     turno asignado y haya marcado. Ver
                     {@see App\Services\ContratosFuncionario}. --}}
                <div><dt>Vigencia desde</dt><dd>{{ $fechaContrato($contrato['start'] ?? null) ?? '—' }}</dd></div>
                <div>
                    <dt>Vigencia hasta</dt>
                    <dd>
                        {{-- Sin fecha de término no es un dato que falte: es un
                             contrato abierto, y así lo trata el resto del
                             sistema. Decirlo con todas las letras evita que un
                             «—» lo haga parecer un dato sin cargar. --}}
                        {{ $fechaContrato($contrato['finish'] ?? null) ?? 'Sin fecha de término' }}
                    </dd>
                </div>
                {{-- <div><dt>Código</dt><dd>{{ ($contrato['code'] ?? null) ?: '—' }}</dd></div>
                <div><dt>Tipo de proceso</dt><dd>{{ ($contrato['procedure_type'] ?? null) ?: '—' }}</dd></div> --}}
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
