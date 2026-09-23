@extends('layouts.app')

@section('titulo', 'Licencias de personal')

@section('contenido')
    <div class="cabecera">
        <div class="cabecera__titulo">
            <span class="cabecera__icono"><x-heroicon-o-clipboard-document-check /></span>
            <h1>Licencias de personal</h1>
        </div>
        @can('create', \App\Models\Licencia::class)
            <a href="{{ route('licencias.create') }}" class="btn"><x-heroicon-o-plus />Nueva licencia</a>
        @endcan
    </div>

    <p class="ayuda" style="margin: -.4rem 0 1rem;">Permisos y ausencias justificadas de los funcionarios, por día y turno.</p>

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

        {{-- Filtro de estado: es cómo Recursos Humanos encuentra las solicitudes
             que llegaron de Mamoré y todavía esperan decisión, sin tener que
             barrer un listado dominado por el histórico ya aprobado. --}}
        <label class="tabla-filtros__mostrar">
            Estado
            <select id="f-estado" aria-label="Filtrar por estado de aprobación">
                <option value="">Todos</option>
                @foreach (\App\Models\Licencia::ESTADOS as $e)
                    <option value="{{ $e }}" @selected($estado === $e)>{{ $e }}</option>
                @endforeach
            </select>
        </label>

        <div class="buscador">
            <x-heroicon-o-magnifying-glass />
            <input type="text" id="f-buscar" value="{{ $busqueda }}" placeholder="Buscar por CI, funcionario o motivo…">
        </div>
    </div>

    {{-- Aquí se inyecta el parcial licencias.list (tabla + paginación). --}}
    <div id="div-results" style="min-height: 8rem;">
        <div class="vacio">Cargando…</div>
    </div>

    <script>
        (function () {
            const url = @json(route('licencias.list'));
            const resultados = document.getElementById('div-results');
            const inputBuscar = document.getElementById('f-buscar');
            const selPaginate = document.getElementById('f-paginate');
            const selEstado = document.getElementById('f-estado');

            async function cargar(page = 1) {
                const params = new URLSearchParams({
                    q: inputBuscar.value,
                    estado: selEstado.value,
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
            selEstado.addEventListener('change', () => cargar(1));
            // La búsqueda se dispara solo con Enter: escribir no recarga la tabla.
            inputBuscar.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') { e.preventDefault(); cargar(1); }
            });

            cargar(1);
        })();
    </script>
@endsection
