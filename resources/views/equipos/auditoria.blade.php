@extends('layouts.app')

@section('titulo', 'Bitácora de equipos')

@section('contenido')
    <div class="cabecera">
        <div class="cabecera__titulo">
            <span class="cabecera__icono"><x-heroicon-o-clipboard-document-list /></span>
            <h1>Bitácora de equipos</h1>
        </div>
        <a href="{{ route('equipos.index') }}" class="btn btn--gris"><x-heroicon-o-arrow-left />Volver a equipos</a>
    </div>

    <p style="margin: -.4rem 0 1.1rem; color: var(--muted); font-size: .85rem;">
        Quién exportó, envió a la base del SIA, limpió o dio de baja cada equipo biométrico.
        Las acciones que borran información llevan el motivo escrito por quien las hizo.
        En las sincronizaciones se detalla qué pasó con cada marcación que entregó el reloj:
        <strong>repetidas</strong> es lo normal —el equipo devuelve todo su historial en cada
        lectura y el sistema no lo duplica—, mientras que <strong>sin funcionario</strong>
        señala a alguien que marca con un ID que no está en el padrón, y sus marcas no se
        están registrando.
    </p>

    <x-tabla-filtros :action="route('equipos.auditoria')" :busqueda="$busqueda"
                     :por-pagina="$porPagina" placeholder="Buscar por equipo, IP, motivo o usuario…">
        <x-slot:filtros>
            <select name="accion" onchange="this.form.submit()">
                <option value="">Todas las acciones</option>
                @foreach ($etiquetas as $valor => $texto)
                    <option value="{{ $valor }}" @selected($accion === $valor)>{{ $texto }}</option>
                @endforeach
            </select>
        </x-slot:filtros>
    </x-tabla-filtros>

    <div class="card">
        <table>
            <thead>
                <tr>
                    <th>Fecha y hora</th>
                    <th>Usuario</th>
                    <th>Acción</th>
                    <th>Equipo</th>
                    <th>Motivo / detalle</th>
                    <th style="text-align: right;" title="Marcaciones que entregó el reloj para el rango pedido">En el equipo</th>
                    <th style="text-align: right;" title="Se guardaron en el sistema">Nuevas</th>
                    <th style="text-align: right;" title="Ya estaban registradas: el reloj las vuelve a entregar en cada lectura">Repetidas</th>
                    <th style="text-align: right;" title="El ID del reloj no cruza con ningún funcionario del padrón">Sin funcionario</th>
                    <th style="text-align: right;" title="Fecha inválida del reloj o error al guardar">Con error</th>
                    <th style="text-align: right;" title="El reloj las mandó aunque quedaban fuera del rango pedido">Fuera de rango</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($registros as $registro)
                    <tr>
                        <td style="white-space: nowrap;">
                            {{ $registro->created_at->format('d/m/Y H:i') }}
                            @if ($registro->ip_usuario)
                                <div style="color: var(--muted); font-size: .75rem;">desde {{ $registro->ip_usuario }}</div>
                            @endif
                        </td>
                        <td><strong>{{ $registro->nombreUsuario() }}</strong></td>
                        <td>
                            <span class="pill {{ in_array($registro->accion, \App\Models\EquipoAuditoria::ACCIONES_DESTRUCTIVAS, true) ? 'pill--no' : 'pill--ok' }}">
                                {{ $registro->etiquetaAccion() }}
                            </span>
                            @unless ($registro->exito)
                                <div style="color: var(--danger); font-size: .75rem; margin-top: .2rem;">Falló</div>
                            @endunless
                        </td>
                        <td>
                            {{-- Se muestran los datos guardados al momento de la acción: siguen
                                 siendo correctos aunque después le cambien la IP o lo den de baja. --}}
                            <strong>{{ $registro->nombreEquipo() }}</strong>
                            <div style="color: var(--muted); font-size: .75rem;">
                                {{ $registro->datos_equipo['ip'] ?? '—' }}:{{ $registro->datos_equipo['puerto'] ?? '—' }}
                                @if (! empty($registro->datos_equipo['ubicacion']))
                                    · {{ $registro->datos_equipo['ubicacion'] }}
                                @endif
                            </div>
                            @if (! empty($registro->datos_equipo['algoritmo']))
                                <div style="color: var(--muted); font-size: .75rem;">{{ $registro->datos_equipo['algoritmo'] }}</div>
                            @endif
                        </td>
                        <td style="max-width: 22rem;">
                            @if ($registro->motivo)
                                <div>{{ $registro->motivo }}</div>
                            @endif
                            @if ($registro->detalle)
                                <div style="color: var(--muted); font-size: .75rem;">{{ $registro->detalle }}</div>
                            @endif
                            @if ($registro->desde || $registro->hasta)
                                <div style="color: var(--muted); font-size: .75rem;">
                                    Rango: {{ $registro->desde ?? 'inicio' }} → {{ $registro->hasta ?? 'hoy' }}
                                </div>
                            @endif
                            @if (! $registro->motivo && ! $registro->detalle && ! $registro->desde && ! $registro->hasta)
                                —
                            @endif
                        </td>
                        {{-- El desglose solo existe en las sincronizaciones: exportar,
                             limpiar y eliminar no reparten las marcaciones en categorías. --}}
                        <td style="text-align: right;">{{ $registro->total_marcaciones ?? '—' }}</td>
                        <td style="text-align: right;">
                            @if ($registro->nuevas === null)
                                —
                            @elseif ($registro->nuevas > 0)
                                <strong style="color: var(--verde);">{{ $registro->nuevas }}</strong>
                            @else
                                0
                            @endif
                        </td>
                        <td style="text-align: right; color: var(--muted);">{{ $registro->repetidas ?? '—' }}</td>
                        <td style="text-align: right;">
                            @if ($registro->sin_funcionario === null)
                                —
                            @elseif ($registro->sin_funcionario > 0)
                                {{-- No es un fallo del sistema: es un ID de reloj que no está
                                     en el padrón. Se marca porque significa que alguien está
                                     marcando y sus marcas no le llegan a nadie. --}}
                                <strong style="color: var(--danger);">{{ $registro->sin_funcionario }}</strong>
                            @else
                                0
                            @endif
                        </td>
                        <td style="text-align: right;">
                            @if ($registro->fallidas === null)
                                —
                            @elseif ($registro->fallidas > 0)
                                <strong style="color: var(--danger);">{{ $registro->fallidas }}</strong>
                            @else
                                0
                            @endif
                        </td>
                        <td style="text-align: right; color: var(--muted);">{{ $registro->fuera_de_rango ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="11" class="vacio">Todavía no hay movimientos registrados.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="paginacion">
        {{ $registros->links() }}
    </div>
@endsection
