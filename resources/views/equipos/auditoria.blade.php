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
        Quién exportó, registró en el sistema, importó un CSV, limpió o dio de baja cada
        equipo biométrico. Las importaciones y las acciones que borran información llevan
        el motivo escrito por quien las hizo.
        Cada sincronización se mide en dos tramos:
        <strong>la transferencia</strong> compara lo que el reloj dice tener contra lo que
        llegó —si no coinciden, la lectura se cortó por el medio y falta una marcación—, y
        <strong>el destino</strong> reparte lo que llegó. <strong>Repetidas</strong> es lo
        normal: el equipo devuelve todo su historial en cada lectura y el sistema no lo
        duplica. <strong>Sin funcionario</strong> son marcaciones que <em>sí se guardaron</em>,
        de alguien que marca con un ID que todavía no está en el padrón; aparecen solas en sus
        reportes en cuanto se lo dé de alta.
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
            {{-- Dos grupos de columnas porque son dos cuentas distintas y encadenadas:
                     en el reloj = llegaron + se perdieron
                     llegaron    = nuevas + repetidas + sin funcionario + con error
                 Juntas en una sola fila de encabezados, los diez números se leían como
                 una lista suelta y no se veía cuál tenía que cerrar contra cuál. --}}
            <thead>
                <tr>
                    <th rowspan="2">Fecha y hora</th>
                    <th rowspan="2">Usuario</th>
                    <th rowspan="2">Acción</th>
                    <th rowspan="2">Equipo</th>
                    <th rowspan="2">Motivo / detalle</th>
                    <th colspan="3" style="text-align: center; border-left: 1px solid var(--borde);"
                        title="Del reloj a SisMark: ¿llegó todo lo que el equipo tenía guardado?">Transferencia</th>
                    <th colspan="4" style="text-align: center; border-left: 1px solid var(--borde);"
                        title="De lo que llegó: qué pasó con cada marcación">Destino en la base</th>
                </tr>
                <tr>
                    <th style="text-align: right; border-left: 1px solid var(--borde);"
                        title="Cuántas dice el reloj que tiene guardadas, según su propio contador">En el reloj</th>
                    <th style="text-align: right;" title="Cuántas llegaron a SisMark">Llegaron</th>
                    <th style="text-align: center;" title="Si las dos cifras coinciden, no se perdió ninguna en el camino">¿Completa?</th>
                    <th style="text-align: right; border-left: 1px solid var(--borde);"
                        title="Se guardaron en el sistema y cruzan con un funcionario del padrón">Nuevas</th>
                    <th style="text-align: right;" title="Ya estaban registradas: el reloj las vuelve a entregar en cada lectura">Repetidas</th>
                    <th style="text-align: right;" title="Se guardaron igual, pero el ID del reloj todavía no está en el padrón">Sin funcionario</th>
                    <th style="text-align: right;" title="Fecha inválida del reloj o error al guardar: son las únicas que no se guardan">Con error</th>
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
                                {{-- Dos fallos muy distintos: el reloj que no contestó no dejó
                                     nada, y el que contestó a medias sí guardó lo que llegó.
                                     Decir «Falló» en los dos casos hacía pensar que se había
                                     perdido todo. --}}
                                <div style="color: var(--danger); font-size: .75rem; margin-top: .2rem;">
                                    {{ $registro->transferenciaCompleta() === false ? 'Incompleta' : 'Falló' }}
                                </div>
                            @endunless
                            {{-- Cada marcación que guardó la sincronización o la
                                 importación lleva su id (`asistencias.equipo_auditoria_id`):
                                 el enlace abre el listado filtrado por ella. --}}
                            @if (in_array($registro->accion, \App\Models\EquipoAuditoria::ACCIONES_QUE_GUARDAN, true) && $registro->marcacionesGuardadas() > 0)
                                @can('viewAny', \App\Models\Asistencia::class)
                                    <div style="font-size: .75rem; margin-top: .2rem;">
                                        <a href="{{ route('marcaciones.index', ['carga' => $registro->id]) }}">Ver marcaciones</a>
                                    </div>
                                @endcan
                            @endif
                        </td>
                        <td>
                            {{-- Se muestran los datos guardados al momento de la acción: siguen
                                 siendo correctos aunque después le cambien la IP o lo den de baja. --}}
                            <strong>{{ $registro->nombreEquipo() }}</strong>
                            {{-- Una importación de CSV no tiene equipo: no hay IP que mostrar. --}}
                            @if (! empty($registro->datos_equipo))
                                <div style="color: var(--muted); font-size: .75rem;">
                                    {{ $registro->datos_equipo['ip'] ?? '—' }}:{{ $registro->datos_equipo['puerto'] ?? '—' }}
                                    @if (! empty($registro->datos_equipo['ubicacion']))
                                        · {{ $registro->datos_equipo['ubicacion'] }}
                                    @endif
                                </div>
                            @endif
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
                        {{-- Tramo 1: la transferencia. `en el reloj` es la única cifra que no
                             sale de contar lo que llegó, y por eso es la que delata una
                             lectura cortada por el medio. --}}
                        <td style="text-align: right; border-left: 1px solid var(--borde);">{{ $registro->en_equipo ?? '—' }}</td>
                        <td style="text-align: right;">{{ $registro->total_marcaciones ?? '—' }}</td>
                        <td style="text-align: center;">
                            @php($completa = $registro->transferenciaCompleta())
                            @if ($completa === null)
                                {{-- Sin contador del reloj no se afirma ni se desmiente: la
                                     corrida no se marca incompleta por no haber podido
                                     comprobarla. --}}
                                <span style="color: var(--muted);" title="No se pudo comprobar: el reloj no informó su contador">—</span>
                            @elseif ($completa)
                                <span style="color: var(--verde);" title="Llegó todo lo que el reloj tenía">✓</span>
                            @else
                                <strong style="color: var(--danger);"
                                        title="Faltan {{ $registro->marcacionesPerdidas() }} marcación(es): la lectura quedó incompleta y se reintenta en la próxima corrida">
                                    faltan {{ $registro->marcacionesPerdidas() }}
                                </strong>
                            @endif
                        </td>
                        {{-- Tramo 2: el destino. Solo existe en las sincronizaciones:
                             exportar, limpiar y eliminar no reparten nada. --}}
                        <td style="text-align: right; border-left: 1px solid var(--borde);">
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
                                {{-- No es un fallo ni una pérdida: las marcaciones se
                                     guardaron. Se resalta porque significa que alguien marca
                                     con un ID que no está en el padrón, y hasta que se lo dé
                                     de alta sus marcas no salen en ningún reporte. --}}
                                <strong style="color: var(--ambar, #b45309);"
                                        title="Guardadas, pero su ID no está en el padrón: aparecerán en los reportes en cuanto se dé de alta al funcionario">{{ $registro->sin_funcionario }}</strong>
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
                    </tr>
                @empty
                    <tr>
                        <td colspan="12" class="vacio">Todavía no hay movimientos registrados.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="paginacion">
        {{ $registros->links() }}
    </div>
@endsection
