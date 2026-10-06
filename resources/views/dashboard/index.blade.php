@extends('layouts.app')

@section('titulo', 'Escritorio')

@php
    $numero = fn (?int $n): string => $n === null ? '—' : number_format($n, 0, ',', '.');

    // Sin horarios cargados no hay a quién esperar: se muestra cuántas personas
    // marcaron, a secas, en lugar de un «0 de 0».
    $hayHorarios = $hoy['con_horario'] !== null;
    $porcentaje = $hayHorarios && $hoy['con_horario'] > 0
        ? (int) round($hoy['marcaron'] / $hoy['con_horario'] * 100)
        : null;
@endphp

@section('contenido')
    <div class="cabecera">
        <div class="cabecera__titulo">
            <span class="cabecera__icono"><x-heroicon-o-home /></span>
            <h1>Escritorio</h1>
        </div>
        <span class="escritorio__fecha">{{ now()->translatedFormat('l j \d\e F, H:i') }}</span>
    </div>

    {{-- Avisos: solo aparecen cuando hay algo que hacer. --}}
    @if (($licenciasPendientes ?? 0) > 0)
        <div class="aviso aviso--advertencia escritorio__aviso">
            <x-heroicon-o-clipboard-document-check />
            <div>
                <strong>{{ $licenciasPendientes === 1
                    ? '1 solicitud de licencia espera tu decisión.'
                    : $licenciasPendientes.' solicitudes de licencia esperan tu decisión.' }}</strong>
                Mientras sigan pendientes no justifican la ausencia.
                <a href="{{ route('licencias.index', ['estado' => \App\Models\Licencia::PENDIENTE]) }}">Revisarlas</a>.
            </div>
        </div>
    @endif

    @if ($equiposFueraDeLinea->isNotEmpty())
        <div class="aviso aviso--error escritorio__aviso">
            <x-heroicon-o-signal-slash />
            <div>
                <strong>{{ $equiposFueraDeLinea->count() === 1
                    ? '1 equipo fuera de línea:'
                    : $equiposFueraDeLinea->count().' equipos fuera de línea:' }}</strong>
                {{ $equiposFueraDeLinea->pluck('nombre')->implode(', ') }}.
                Sus marcaciones no llegan hasta que vuelva.
                @can('ViewAny:Equipo')
                    <a href="{{ route('equipos.index') }}">Ver equipos</a>.
                @endcan
            </div>
        </div>
    @endif

    @if ($proximoExcepcional)
        <div class="aviso escritorio__aviso">
            <x-heroicon-o-calendar-days />
            <div>
                <strong>{{ $proximoExcepcional->fecha->isToday() ? 'Hoy' : $proximoExcepcional->fecha->translatedFormat('l j \d\e F') }}
                    no se controla asistencia:</strong>
                {{ $proximoExcepcional->motivoInasistencia }}.
            </div>
        </div>
    @endif

    {{-- Los cuatro números del día. --}}
    <h2 class="escritorio__seccion">Hoy</h2>
    <div class="stats-grid">
        @if ($hayHorarios)
            <div class="stat-card">
                <div class="stat-card__valor">{{ $numero($hoy['con_horario']) }}</div>
                <div class="stat-card__label">Con horario hoy</div>
            </div>

            <div class="stat-card stat-card--success">
                <div class="stat-card__valor">{{ $numero($hoy['marcaron']) }}</div>
                <div class="stat-card__label">Marcaron</div>
                @if ($porcentaje !== null)
                    <div class="stat-card__sub">{{ $porcentaje }} % de los que tienen horario</div>
                @endif
            </div>

            <div class="stat-card {{ $hoy['sin_marcar'] > 0 ? 'stat-card--danger' : 'stat-card--success' }}">
                <div class="stat-card__valor">{{ $numero($hoy['sin_marcar']) }}</div>
                <div class="stat-card__label">Sin marcar</div>
                <div class="stat-card__sub">sin contar a los de licencia</div>
            </div>
        @else
            <div class="stat-card">
                <div class="stat-card__valor">{{ $numero($hoy['personas']) }}</div>
                <div class="stat-card__label">Personas que marcaron</div>
                <div class="stat-card__sub">Sin horarios asignados no se sabe quién falta</div>
            </div>
        @endif

        <div class="stat-card">
            <div class="stat-card__valor">{{ $numero($hoy['licenciados']) }}</div>
            <div class="stat-card__label">De licencia</div>
        </div>
    </div>

    {{-- Las pantallas que más se usan, según lo que el rol puede ver. --}}
    <h2 class="escritorio__seccion">Accesos rápidos</h2>
    <div class="escritorio__accesos">
        @can('ViewAny:Persona')
            <a href="{{ route('funcionarios.index') }}" class="btn"><x-heroicon-o-magnifying-glass />Buscar funcionario</a>
        @endcan
        @can('ViewAny:Reporte')
            <a href="{{ route('reportes.marcaciones.procesado') }}" class="btn"><x-heroicon-o-document-chart-bar />Reporte procesado</a>
            <a href="{{ route('reportes.marcaciones.direccion') }}" class="btn"><x-heroicon-o-building-office />Por dirección</a>
        @endcan
        @can('ViewAny:Licencia')
            <a href="{{ route('licencias.index') }}" class="btn"><x-heroicon-o-clipboard-document-list />Licencias</a>
        @endcan
    </div>

    {{-- Si los relojes están mandando: lo único que queda de la captura. --}}
    <p class="escritorio__pie">
        Última marcación recibida:
        {{ $ultimaMarcacion ? $ultimaMarcacion->diffForHumans().' ('.$ultimaMarcacion->format('d/m/Y H:i').')' : 'nunca' }}
    </p>
@endsection
