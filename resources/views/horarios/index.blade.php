@extends('layouts.app')

@section('titulo', 'Turnos')

@section('contenido')
    <div class="cabecera">
        <div class="cabecera__titulo">
            <span class="cabecera__icono"><x-heroicon-o-clock /></span>
            <h1>Administrador de turnos</h1>
        </div>
        @can('create', \App\Models\Turno::class)
            <a href="{{ route('horarios.create') }}" class="btn"><x-heroicon-o-plus />Nuevo turno</a>
        @endcan
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
            <select id="f-dia" aria-label="Día de la semana">
                <option value="">Todos los días</option>
                @foreach (\App\Models\Turno::DIAS as $numero => $nombre)
                    <option value="{{ $numero }}" @selected($dia === (string) $numero)>{{ $nombre }}</option>
                @endforeach
            </select>

            {{-- Con 757 turnos en la tabla, encontrar los pocos marcados como
                 sugeridos a ojo no es viable: este filtro los aísla. --}}
            <select id="f-sugerido" aria-label="Horario sugerido">
                <option value="">Sugeridos y no sugeridos</option>
                <option value="1" @selected($sugerido === '1')>Solo los sugeridos</option>
                <option value="0" @selected($sugerido === '0')>Solo los no sugeridos</option>
            </select>
        </div>

        <div class="buscador">
            <x-heroicon-o-magnifying-glass />
            <input type="text" id="f-buscar" value="{{ $buscar }}" placeholder="Buscar por nombre del turno…">
        </div>
    </div>

    {{-- Aquí se inyecta el parcial horarios.list (tabla + paginación). --}}
    <div id="div-results" style="min-height: 8rem;">
        <div class="vacio">Cargando…</div>
    </div>

    <script>
        (function () {
            const url = @json(route('horarios.list'));
            const resultados = document.getElementById('div-results');
            const inputBuscar = document.getElementById('f-buscar');
            const selPaginate = document.getElementById('f-paginate');
            const selDia = document.getElementById('f-dia');
            const selSugerido = document.getElementById('f-sugerido');

            async function cargar(page = 1) {
                const params = new URLSearchParams({
                    dia: selDia.value,
                    sugerido: selSugerido.value,
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
            selSugerido.addEventListener('change', () => cargar(1));
            // La búsqueda se dispara solo con Enter: escribir no recarga la tabla.
            inputBuscar.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') { e.preventDefault(); cargar(1); }
            });

            cargar(1);
        })();
    </script>
@endsection
