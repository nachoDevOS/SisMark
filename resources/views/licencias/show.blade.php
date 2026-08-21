@extends('layouts.app')

@section('titulo', 'Licencia de ' . trim($licencia->ci))

@php
    // Colores del estado, los mismos que usa el perfil del funcionario en Mamoré
    // para que la misma licencia no se vea de dos colores según dónde se mire.
    $colorEstado = [
        \App\Models\Licencia::APROBADO => 'pill--ok',
        \App\Models\Licencia::PENDIENTE => 'pill--advertencia',
        \App\Models\Licencia::RECHAZADO => 'pill--no',
    ];

    // El rango real de la solicitud sale de los días anotados, no de lo que se
    // pidió: si el funcionario pidió de lunes a domingo pero solo tiene turno de
    // lunes a viernes, la licencia abarca cinco días y eso es lo que hay que
    // mostrar.
    $primero = $dias->first()?->fecha;
    $ultimo = $dias->last()?->fecha;
    $resuelta = $dias->firstWhere(fn ($d) => $d->revisadoEn !== null);
@endphp

@section('contenido')
    <div class="cabecera">
        <div class="cabecera__titulo">
            <span class="cabecera__icono"><x-heroicon-o-clipboard-document-check /></span>
            <h1>
                {{ $ficha['nombre'] ?? 'Funcionario sin ficha' }} · CI {{ trim((string) $licencia->ci) }}
            </h1>
        </div>
        <div class="acciones">
            {{-- La baja es de la solicitud entera, como todo en esta pantalla:
                 el mensaje lo dice para que no se confunda con borrar un día. --}}
            {{-- Lo pedido desde Mamoré y lo rechazado no se eliminan: el
                 criterio lo da `motivoParaNoEliminar()` del modelo. --}}
            @can('delete', $licencia)
                @if ($licencia->esEliminable)
                <x-boton-eliminar :accion="route('licencias.destroy', $licencia)"
                                  :mensaje="$dias->count() === 1
                                      ? 'Se elimina la licencia del '.$licencia->fecha?->format('d/m/Y').'.'
                                      : 'Se eliminan los '.$dias->count().' días de esta solicitud.'" />
                @endif
            @endcan
            <a href="{{ route('licencias.index', ['q' => trim((string) $licencia->ci)]) }}" class="btn btn--gris">
                <x-heroicon-o-arrow-left />Volver
            </a>
        </div>
    </div>

    @if ($pendientes->isNotEmpty())
        <div class="aviso aviso--advertencia">
            Esta solicitud la hizo el funcionario desde su perfil y espera tu decisión.
            Mientras siga «Pendiente» <strong>no justifica la ausencia</strong>: el cálculo de
            asistencia solo descuenta las licencias aprobadas.
        </div>
    @endif

    <div class="form-grid form-grid--apilado">
        <div class="tarjeta">
            <h2>Solicitud</h2>
            <dl class="datos grid-2">
                <div>
                    <dt>Motivo</dt>
                    <dd>{{ $licencia->motivo ?: '—' }}</dd>
                </div>
                <div>
                    <dt>Alcance</dt>
                    <dd>
                        {{-- Mismo criterio que la columna «Alcance» de la tabla,
                             para que la ficha y el detalle no digan cosas
                             distintas de la misma licencia. --}}
                        @if ($licencia->alcance_del_dia === null)
                            <span class="pill pill--ok">Turno completo</span>
                        @else
                            <span class="pill pill--info">{{ $licencia->alcance_del_dia }}</span>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt>Periodo</dt>
                    <dd>
                        {{ $primero?->format('d/m/Y') ?? '—' }} – {{ $ultimo?->format('d/m/Y') ?? '—' }}
                        <span class="ayuda">({{ $dias->count() }} día(s) con turno)</span>
                    </dd>
                </div>
                <div>
                    <dt>Haberes</dt>
                    <dd>
                        <span class="pill {{ $licencia->goceHaberes ? 'pill--ok' : 'pill--no' }}">
                            {{ $licencia->goceHaberes ? 'Con goce' : 'Sin goce' }}
                        </span>
                    </dd>
                </div>
                <div>
                    <dt>Pedida el</dt>
                    <dd>{{ $licencia->fechaPedido?->format('d/m/Y H:i') ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Origen</dt>
                    <dd>
                        <span class="pill {{ $licencia->origen === \App\Models\Licencia::ORIGEN_MAMORE ? 'pill--info' : 'pill--neutro' }}">
                            {{ $licencia->origen_etiqueta }}
                        </span>
                    </dd>
                </div>
                <div>
                    <dt>Pedida por</dt>
                    <dd>
                        {{ trim((string) $licencia->usuario) ?: '—' }}
                    </dd>
                </div>
                <div>
                    <dt>Respaldo</dt>
                    <dd>
                        @if ($licencia->adjunto)
                            <a href="{{ route('licencias.respaldo', $licencia) }}" target="_blank" rel="noopener"
                               class="respaldo" style="margin-left: 0;">
                                <x-heroicon-o-paper-clip />{{ $licencia->adjuntoNombre ?: 'Ver respaldo' }}
                            </a>
                        @else
                            <span class="ayuda">Sin respaldo adjunto</span>
                        @endif
                    </dd>
                </div>
                @if ($ficha['cargo'] ?? null)
                    <div>
                        <dt>Cargo</dt>
                        <dd>{{ $ficha['cargo'] }}</dd>
                    </div>
                @endif
            </dl>

            @if ($resuelta)
                <dl class="datos">
                    <dt>Resuelta</dt>
                    <dd>
                        <span class="pill {{ $colorEstado[$resuelta->estado] ?? 'pill--info' }}">{{ $resuelta->estado }}</span>
                        <span class="ayuda">
                            por {{ $resuelta->revisor?->name ?? 'un usuario dado de baja' }}
                            · {{ $resuelta->revisadoEn?->format('d/m/Y H:i') }}
                        </span>
                    </dd>

                    @if ($resuelta->observacion)
                        <dt>Nota</dt>
                        <dd>{{ $resuelta->observacion }}</dd>
                    @endif
                </dl>
            @endif
        </div>

        <div class="tarjeta">
            <h2>Días alcanzados</h2>
            <p class="ayuda" style="margin: -.5rem 0 .75rem;">
                Una licencia se anota por día y turno. Estos son los turnos que el funcionario
                tenía asignados dentro del rango que pidió.
            </p>

            <table>
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Turno</th>
                        <th>Alcance</th>
                        <th>Estado</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($dias as $dia)
                        <tr>
                            <td><strong>{{ $dia->fecha?->format('d/m/Y') }}</strong></td>
                            <td>{{ $dia->resumen_turno }}</td>
                            {{-- El alcance se lee de cada día y no de la solicitud:
                                 el alta expande el rango, pero nada obliga a que
                                 todos los días se hayan pedido iguales. --}}
                            <td>
                                @if ($dia->alcance_del_dia === null)
                                    <span class="pill pill--ok">Turno completo</span>
                                @else
                                    <span class="pill pill--info">{{ $dia->alcance_del_dia }}</span>
                                @endif
                            </td>
                            <td>
                                <span class="pill {{ $colorEstado[$dia->estado] ?? 'pill--info' }}">{{ $dia->estado }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="vacio">Sin días anotados.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- El panel solo aparece sobre lo que sigue «Pendiente», la misma condición
         que exige `RevisarLicenciaRequest`. Va en un `@if` aparte del `@can` y no
         dentro de la policy porque el `Gate::before` de super_admin se saltea la
         policy entera. --}}
    @if ($licencia->esPendiente)
        @can('approve', $licencia)
        {{-- `accion` arranca en «rechazar» si el envío volvió con errores: el
             único que puede fallar la validación es el rechazo sin motivo, y
             volver con el panel cerrado escondería el mensaje de error. --}}
        <div class="tarjeta" x-data="{ accion: @js($errors->any() ? 'rechazar' : null) }">
            <h2>Resolver la solicitud</h2>
            <p class="ayuda" style="margin: -.5rem 0 .9rem;">
                La decisión alcanza a los <strong>{{ $pendientes->count() }} día(s) pendientes</strong> de
                esta solicitud. Aprobar hace que la ausencia quede justificada en el cálculo de
                asistencia; no se revierte desde acá.
            </p>

            <div class="form-acciones" x-show="accion === null" style="margin-top: 0;">
                <button type="button" class="btn" x-on:click="accion = 'aprobar'">
                    <x-heroicon-o-check />Aprobar {{ $pendientes->count() }} día(s)
                </button>
                <button type="button" class="btn btn--peligro" x-on:click="accion = 'rechazar'">
                    <x-heroicon-o-x-mark />Rechazar
                </button>
            </div>

            <form method="POST" x-show="accion !== null" x-cloak
                  x-bind:action="accion === 'rechazar'
                      ? @js(route('licencias.rechazar', $licencia))
                      : @js(route('licencias.aprobar', $licencia))">
                @csrf
                @method('PATCH')

                <div class="campo">
                    <label for="observacion">
                        <span x-show="accion === 'rechazar'">Motivo del rechazo <span class="req">*</span></span>
                        <span x-show="accion === 'aprobar'" x-cloak>Observación</span>
                    </label>
                    <textarea id="observacion" name="observacion" rows="3" maxlength="500"
                              x-bind:required="accion === 'rechazar'"
                              placeholder="Por ejemplo: falta el certificado médico.">{{ old('observacion') }}</textarea>
                    <p class="ayuda">
                        <span x-show="accion === 'rechazar'">
                            Obligatorio. El funcionario lo ve en su perfil: es cómo se entera de qué le faltó.
                        </span>
                        <span x-show="accion === 'aprobar'" x-cloak>
                            Opcional. Queda registrada junto con la aprobación.
                        </span>
                    </p>
                    @error('observacion') <div class="error">{{ $message }}</div> @enderror
                </div>

                <div class="form-acciones" style="margin-top: .5rem;">
                    <button type="submit" class="btn" x-bind:class="accion === 'rechazar' ? 'btn--peligro' : ''">
                        <x-heroicon-o-check />
                        <span x-show="accion === 'aprobar'" x-cloak>Confirmar aprobación</span>
                        <span x-show="accion === 'rechazar'">Confirmar rechazo</span>
                    </button>
                    <button type="button" class="btn btn--gris" x-on:click="accion = null">
                        <x-heroicon-o-x-mark />Cancelar
                    </button>
                </div>
            </form>
        </div>
        @endcan
    @endif
@endsection
