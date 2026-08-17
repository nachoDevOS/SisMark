@extends('layouts.app')

@section('titulo', 'Marcaciones')

@section('contenido')
    <div class="cabecera">
        <div class="cabecera__titulo">
            <span class="cabecera__icono"><x-heroicon-o-finger-print /></span>
            <h1>Marcaciones</h1>
        </div>
        @can('create', \App\Models\Asistencia::class)
            {{-- Dos vías de carga: el CSV que exporta «Biométricos >
                 Marcaciones > Exportar» —lo habitual, por lote— y el alta
                 manual de a una, para lo que el reloj no registró. El import va
                 primero porque es el camino normal. --}}
            <div class="acciones">
                <x-modal-importar-marcaciones />
                <x-modal-marcacion />
            </div>
        @endcan
    </div>

    <p class="ayuda" style="margin: -.4rem 0 1rem;">
        Todas las marcaciones registradas. El listado arranca en el mes actual porque
        la tabla tiene millones de filas.
    </p>

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

        {{-- Etiquetas a la vista: dos campos de fecha sueltos no dicen que son
             un rango, y las letras del tipo no significan nada por sí solas. --}}
        <div class="tabla-filtros__extra">
            <label class="filtro">Desde <input type="date" id="f-desde" value="{{ $desde }}"></label>
            <label class="filtro">Hasta <input type="date" id="f-hasta" value="{{ $hasta }}"></label>
            <label class="filtro">
                Origen
                <select id="f-tipo">
                    <option value="">Todos</option>
                    @foreach (\App\Models\Asistencia::TIPOS as $letra => $etiqueta)
                        <option value="{{ $letra }}" @selected($tipo === $letra)>{{ $letra }} · {{ $etiqueta }}</option>
                    @endforeach
                </select>
            </label>
        </div>

        <div class="buscador">
            <x-heroicon-o-magnifying-glass />
            <input type="text" id="f-buscar" placeholder="Buscar por CI o nombre…">
        </div>
    </div>

    {{-- Referencia de la columna «Origen»: la letra es lo que guarda la base. --}}
    <p class="ayuda" style="margin: -.5rem 0 .9rem;">
        <strong>R</strong> = marcó en el reloj biométrico ·
        <strong>M</strong> = cargada a mano en el sistema ·
        <strong>A</strong> = viene del SIA y su origen no está documentado.
    </p>

    {{-- Aquí se inyecta el parcial marcaciones.list (tabla + paginación). --}}
    <div id="div-results" style="min-height: 8rem;">
        <div class="vacio">Cargando…</div>
    </div>

    <script>
        (function () {
            const url = @json(route('marcaciones.list'));
            const resultados = document.getElementById('div-results');
            const inputBuscar = document.getElementById('f-buscar');
            const selPaginate = document.getElementById('f-paginate');
            const dateDesde = document.getElementById('f-desde');
            const dateHasta = document.getElementById('f-hasta');
            const selTipo = document.getElementById('f-tipo');

            async function cargar(page = 1) {
                const params = new URLSearchParams({
                    desde: dateDesde.value,
                    hasta: dateHasta.value,
                    tipo: selTipo.value,
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
            dateDesde.addEventListener('change', () => cargar(1));
            dateHasta.addEventListener('change', () => cargar(1));
            selTipo.addEventListener('change', () => cargar(1));
            // La búsqueda se dispara solo con Enter: escribir no recarga la tabla.
            inputBuscar.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') { e.preventDefault(); cargar(1); }
            });

            cargar(1);
        })();
    </script>
@endsection
