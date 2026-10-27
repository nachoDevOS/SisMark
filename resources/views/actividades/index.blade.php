@extends('layouts.app')

@section('titulo', 'Actividad de usuarios')

@php
    // El filtro se arma de nuevo en cada enlace: el interruptor cambia solo `todo`.
    $conFiltros = fn (array $cambios): string => route('actividades.index', array_merge(request()->except('page'), $cambios));
    $colorTipo = ['acceso' => 'pill--info', 'consulta' => 'pill--neutro', 'busqueda' => 'pill--neutro', 'reporte' => 'pill--ok', 'ayuda' => 'pill--neutro', 'cambio' => 'pill--advertencia', 'aviso' => 'pill--neutro', 'detalle' => 'pill--neutro'];
@endphp

@section('contenido')
    <div class="cabecera">
        <div class="cabecera__titulo">
            <span class="cabecera__icono"><x-heroicon-o-eye /></span>
            <h1>Actividad de usuarios</h1>
        </div>
    </div>

    <p style="margin: -.4rem 0 1.1rem; color: var(--muted); font-size: .85rem;">
        Quién entró al sistema, qué miró, qué buscó y qué reportes sacó. Se registra <strong>cada pedido</strong>,
        menos contraseñas y tokens. <strong>Solo lo importante</strong> se lee como una historia;
        <strong>Ver todo</strong> muestra además cada pedido técnico. Nadie puede editar ni borrar este registro.
        No es la Bitácora de biométricos.
    </p>

    <form method="GET" action="{{ route('actividades.index') }}" class="card card--padded actividad-filtros">
        <input type="hidden" name="todo" value="{{ $filtros['todo'] ? 1 : 0 }}">
        <div class="actividad-filtros__fila">
            <div class="campo">
                <label for="desde">Desde</label>
                <input type="date" id="desde" name="desde" value="{{ $filtros['desde'] }}" class="input">
            </div>
            <div class="campo">
                <label for="hasta">Hasta</label>
                <input type="date" id="hasta" name="hasta" value="{{ $filtros['hasta'] }}" class="input">
            </div>
            {{-- Quién: un lado entero (personas de SisMark o sistemas externos)
                 o un usuario puntual. --}}
            <div class="campo">
                <label for="quien">Quién</label>
                <select id="quien" name="quien" class="input">
                    <option value="">Todos</option>
                    <option value="personas" @selected($filtros['quien'] === 'personas')>Personas de SisMark</option>
                    <option value="sistemas" @selected($filtros['quien'] === 'sistemas')>Sistemas externos (API)</option>
                    <optgroup label="Usuarios">
                        @foreach ($usuarios as $usuario)
                            <option value="usuario:{{ $usuario->id }}" @selected($filtros['quien'] === "usuario:{$usuario->id}")>{{ $usuario->name }}</option>
                        @endforeach
                    </optgroup>
                </select>
            </div>
            <div class="campo">
                <label for="ci">CI del funcionario</label>
                <input type="text" id="ci" name="ci" value="{{ $filtros['ci'] }}" class="input" placeholder="Ej.: 7633685">
            </div>
            <div class="campo">
                <label for="metodo">Método</label>
                <select id="metodo" name="metodo" class="input">
                    <option value="">Todos</option>
                    @foreach ($metodos as $metodo)
                        <option value="{{ $metodo }}" @selected($filtros['metodo'] === $metodo)>{{ $metodo }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="actividad-filtros__fila actividad-filtros__fila--pie">
            <label class="campo check" style="margin: 0;">
                <input type="checkbox" name="fallidos" value="1" @checked($filtros['fallidos'])>
                <span>Solo intentos de ingreso fallidos</span>
            </label>
            <label class="campo check" style="margin: 0;">
                <input type="checkbox" name="errores" value="1" @checked($filtros['errores'])>
                <span>Solo pedidos con error (4xx/5xx)</span>
            </label>
            <span style="flex: 1;"></span>
            <a href="{{ route('actividades.index') }}" class="btn btn--gris"><x-heroicon-o-arrow-path />Limpiar</a>
            <button type="submit" class="btn"><x-heroicon-o-magnifying-glass />Buscar</button>
        </div>
    </form>

    <div class="actividad-vista" role="group" aria-label="Qué se muestra">
        <a href="{{ $conFiltros(['todo' => 0]) }}" @class(['activo' => ! $filtros['todo']])>Solo lo importante</a>
        <a href="{{ $conFiltros(['todo' => 1]) }}" @class(['activo' => $filtros['todo']])>Ver todo</a>
        <span class="persona-meta">{{ number_format($actividades->total(), 0, ',', '.') }} registro(s)</span>
    </div>

    <div class="card">
        <table>
            <thead>
                <tr>
                    <th>Fecha y hora</th>
                    <th>Quién</th>
                    <th>Acción</th>
                    <th>Qué hizo</th>
                    <th>Módulo</th>
                    <th>IP</th>
                    <th></th>
                </tr>
            </thead>
            @forelse ($actividades as $actividad)
                @php
                    $tipo = \App\Models\Actividad::ACCIONES[$actividad->accion]['tipo'] ?? 'detalle';
                    $ciFuncionario = $actividad->ciDelFuncionario();
                    $nombreFuncionario = $ciFuncionario === null ? null : ($nombres[$ciFuncionario] ?? null);
                @endphp
                <tbody x-data="{ abierto: false }">
                    <tr @class(['actividad-fila--fallida' => $actividad->esFallida()])>
                        <td style="white-space: nowrap;">
                            {{ $actividad->created_at->format('d/m/Y') }}
                            <div class="persona-meta">{{ $actividad->created_at->format('H:i:s') }}</div>
                        </td>
                        <td>
                            <strong>{{ $actividad->quien() }}</strong>
                            @if ($actividad->sistemaExterno)
                                <div class="persona-meta">Sistema externo (API)</div>
                            @endif
                        </td>
                        <td>
                            <span class="pill {{ $actividad->esFallida() ? 'pill--no' : ($colorTipo[$tipo] ?? 'pill--neutro') }}">
                                {{ $actividad->etiquetaAccion() }}
                            </span>
                        </td>
                        <td style="max-width: 32rem;">
                            @if ($actividad->descripcion)
                                {{ $actividad->descripcion }}
                            @else
                                <span class="actividad-url">{{ $actividad->metodo }} {{ $actividad->url }}</span>
                            @endif
                            {{-- El funcionario con nombre. Sin nombre, la CI solo hace
                                 falta si la frase no la dice ya. --}}
                            @if ($nombreFuncionario)
                                <div class="persona-meta actividad-funcionario">
                                    <x-heroicon-o-user />{{ $nombreFuncionario }} · CI {{ $ciFuncionario }}
                                </div>
                            @elseif ($ciFuncionario && ! $actividad->descripcion)
                                <div class="persona-meta actividad-funcionario">
                                    <x-heroicon-o-user />CI {{ $ciFuncionario }}
                                </div>
                            @endif
                            @if ($actividad->estado >= 400)
                                <div style="color: var(--danger); font-size: .75rem;">Respondió {{ $actividad->estado }}</div>
                            @endif
                        </td>
                        <td>{{ $actividad->modulo }}</td>
                        <td style="white-space: nowrap;">{{ $actividad->ip ?: '—' }}</td>
                        <td class="acciones">
                            <button type="button" class="btn-icon btn-icon--gris" x-on:click="abierto = ! abierto"
                                    :aria-expanded="abierto" title="Ver el detalle" aria-label="Ver el detalle">
                                <span :style="abierto ? 'transform: rotate(180deg)' : ''" style="display: inline-flex; transition: transform .15s;">
                                    <x-heroicon-o-chevron-down />
                                </span>
                            </button>
                        </td>
                    </tr>
                    <tr x-show="abierto" x-cloak class="actividad-detalle">
                        <td colspan="7">
                            <dl class="datos grid-2" style="margin: 0;">
                                <div><dt>Pedido</dt><dd class="actividad-url">{{ $actividad->metodo }} {{ $actividad->url }}</dd></div>
                                <div><dt>Ruta</dt><dd>{{ $actividad->ruta ?: '—' }}</dd></div>
                                @if ($actividad->estado)
                                    <div><dt>Respuesta</dt><dd>{{ $actividad->estado }} · {{ $actividad->duracion_ms }} ms</dd></div>
                                @endif
                                @if ($ciFuncionario)
                                    <div><dt>Funcionario</dt><dd>{{ $nombreFuncionario ? $nombreFuncionario.' · ' : '' }}CI {{ $ciFuncionario }}</dd></div>
                                @endif
                                @if ($actividad->email)
                                    <div><dt>Correo escrito</dt><dd>{{ $actividad->email }}</dd></div>
                                @endif
                                <div><dt>Navegador</dt><dd class="persona-meta">{{ $actividad->navegador ?: '—' }}</dd></div>
                            </dl>
                            @if (! empty($actividad->datos))
                                <div style="margin-top: .6rem;">
                                    <div class="persona-meta" style="font-weight: 600; margin-bottom: .2rem;">Datos del pedido</div>
                                    <pre class="actividad-datos">{{ json_encode($actividad->datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                </div>
                            @endif
                        </td>
                    </tr>
                </tbody>
            @empty
                <tbody>
                    <tr><td colspan="7" class="vacio">No hay actividad con estos filtros.</td></tr>
                </tbody>
            @endforelse
        </table>
    </div>

    <div class="paginacion">{{ $actividades->links() }}</div>
@endsection
