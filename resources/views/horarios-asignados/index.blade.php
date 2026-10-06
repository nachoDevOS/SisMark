@extends('layouts.app')

@section('titulo', 'Horarios asignados')

@section('contenido')
    <div class="cabecera">
        <div class="cabecera__titulo">
            <span class="cabecera__icono"><x-heroicon-o-user-group /></span>
            <h1>Horarios asignados</h1>
        </div>
        @can('viewAny', \App\Models\AsignacionTurno::class)
            <a href="{{ route('turnos-asignados.index') }}" class="btn"><x-heroicon-o-rectangle-stack />Ir a turnos asignados</a>
        @endcan
    </div>

    <div class="aviso" style="margin-bottom: 1rem;">
        Historial día por día. Ya no se asignan horarios sueltos: se asigna un <strong>turno</strong>,
        y sus horarios aparecen acá con el nombre del turno. Lo de un turno se concluye o se elimina
        desde «Turnos asignados».
    </div>

    {{-- Filtros del listado (browse): disparan la carga AJAX de la tabla. --}}
    <div class="tabla-filtros">
        <label class="tabla-filtros__mostrar">
            Mostrar
            <select id="f-paginate" aria-label="Cantidad de registros a mostrar">
                @foreach ([10, 25, 50, 100] as $n)
                    <option value="{{ $n }}" @selected($porPagina == $n)>{{ $n }}</option>
                @endforeach
            </select>
            registros
        </label>

        <div class="tabla-filtros__extra">
            <select id="f-dia" aria-label="Día de la semana del horario">
                <option value="">Todos los días</option>
                @foreach (\App\Models\Horario::DIAS as $numero => $nombre)
                    <option value="{{ $numero }}" @selected($dia === (string) $numero)>{{ $nombre }}</option>
                @endforeach
            </select>

            <select id="f-situacion" aria-label="Situación de la asignación">
                <option value="todas">Todas</option>
                @foreach (\App\Http\Controllers\AsignacionHorarioController::SITUACIONES as $valor => $etiqueta)
                    <option value="{{ $valor }}" @selected($situacion === $valor)>{{ $etiqueta }}</option>
                @endforeach
            </select>
        </div>

        <div class="buscador">
            <x-heroicon-o-magnifying-glass />
            <input type="text" id="f-buscar" value="{{ $buscar }}" placeholder="Buscar por CI, funcionario o horario…">
        </div>
    </div>

    {{-- Aquí se inyecta el parcial horarios-asignados.list (tabla + paginación). --}}
    <div id="div-results" style="min-height: 8rem;">
        <div class="vacio">Cargando…</div>
    </div>

    <script>
        (function () {
            const url = @json(route('horarios-asignados.list'));
            const resultados = document.getElementById('div-results');
            const inputBuscar = document.getElementById('f-buscar');
            const selPaginate = document.getElementById('f-paginate');
            const selDia = document.getElementById('f-dia');
            const selSituacion = document.getElementById('f-situacion');

            async function cargar(page = 1) {
                const params = new URLSearchParams({
                    dia: selDia.value,
                    situacion: selSituacion.value,
                    q: inputBuscar.value,
                    por_pagina: selPaginate.value,
                    page: page,
                });
                resultados.innerHTML = '<div class="vacio">Cargando…</div>';
                try {
                    const resp = await fetch(`${url}?${params.toString()}`, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    });
                    resultados.innerHTML = await resp.text();
                } catch (e) {
                    resultados.innerHTML = '<div class="aviso aviso--error">No se pudo cargar el listado. Reintentá.</div>';
                }
            }

            // Paginación: los enlaces del parcial se inyectan dinámicamente, se
            // delega el click sobre el contenedor.
            resultados.addEventListener('click', function (e) {
                const enlace = e.target.closest('a.pag__link');
                if (!enlace) { return; }
                e.preventDefault();
                const page = new URL(enlace.href).searchParams.get('page') || 1;
                cargar(page);
            });

            selPaginate.addEventListener('change', () => cargar(1));
            selDia.addEventListener('change', () => cargar(1));
            selSituacion.addEventListener('change', () => cargar(1));
            // La búsqueda se dispara solo con Enter: escribir no recarga la tabla.
            inputBuscar.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') { e.preventDefault(); cargar(1); }
            });

            cargar(1);
        })();
    </script>
@endsection
