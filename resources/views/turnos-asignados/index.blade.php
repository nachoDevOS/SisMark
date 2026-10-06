@extends('layouts.app')

@section('titulo', 'Turnos asignados')

@section('contenido')
    <div class="cabecera">
        <div class="cabecera__titulo">
            <span class="cabecera__icono"><x-heroicon-o-user-group /></span>
            <h1>Turnos asignados</h1>
        </div>
        @can('create', \App\Models\AsignacionTurno::class)
            <a href="{{ route('turnos-asignados.create') }}" class="btn"><x-heroicon-o-plus />Asignar turno</a>
        @endcan
    </div>

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
            <select id="f-situacion" aria-label="Situación de la asignación">
                <option value="todas">Todas</option>
                @foreach (\App\Http\Controllers\AsignacionTurnoController::SITUACIONES as $valor => $etiqueta)
                    <option value="{{ $valor }}" @selected($situacion === $valor)>{{ $etiqueta }}</option>
                @endforeach
            </select>
        </div>

        <div class="buscador">
            <x-heroicon-o-magnifying-glass />
            <input type="text" id="f-buscar" value="{{ $buscar }}" placeholder="Buscar por CI, funcionario o turno…">
        </div>
    </div>

    {{-- Aquí se inyecta el parcial turnos-asignados.list (tabla + paginación). --}}
    <div id="div-results" style="min-height: 8rem;">
        <div class="vacio">Cargando…</div>
    </div>

    <script>
        (function () {
            const url = @json(route('turnos-asignados.list'));
            const resultados = document.getElementById('div-results');
            const inputBuscar = document.getElementById('f-buscar');
            const selPaginate = document.getElementById('f-paginate');
            const selSituacion = document.getElementById('f-situacion');

            async function cargar(page = 1) {
                const params = new URLSearchParams({
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

            resultados.addEventListener('click', function (e) {
                const enlace = e.target.closest('a.pag__link');
                if (!enlace) { return; }
                e.preventDefault();
                const page = new URL(enlace.href).searchParams.get('page') || 1;
                cargar(page);
            });

            selPaginate.addEventListener('change', () => cargar(1));
            selSituacion.addEventListener('change', () => cargar(1));
            inputBuscar.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') { e.preventDefault(); cargar(1); }
            });

            cargar(1);
        })();
    </script>
@endsection
