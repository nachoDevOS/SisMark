@extends('layouts.app')

@section('titulo', 'Funcionarios')

@section('contenido')
    <div class="cabecera">
        <div class="cabecera__titulo">
            <span class="cabecera__icono"><x-heroicon-o-user-group /></span>
            <h1>Funcionarios</h1>
        </div>
    </div>

    {{-- Filtros del listado (browse): disparan la carga AJAX de la tabla. --}}
    <div class="tabla-filtros">
        <label class="tabla-filtros__mostrar">
            Mostrar
            <select id="f-paginate">
                @foreach ([10, 25, 50, 100] as $n)
                    <option value="{{ $n }}">{{ $n }}</option>
                @endforeach
            </select>
            registros
        </label>

        <div class="tabla-filtros__extra">
            <select id="f-fuente" aria-label="Fuente de datos">
                <option value="mamore">Mamoré</option>
                <option value="siat">SIAT</option>
            </select>

            {{-- Solo Mamoré conoce los contratos: con SIAT el select se oculta.
                 Dentro de «Con contrato» van los tipos (permanente, eventual…),
                 que llegan con cada carga junto con su total. --}}
            <select id="f-contrato" aria-label="Situación de contrato">
                <option value="todos">Todos</option>
                <optgroup label="Con contrato">
                    <option value="con">Todos con contrato</option>
                </optgroup>
                <option value="sin">Sin contrato</option>
            </select>
        </div>

        <div class="buscador">
            <x-heroicon-o-magnifying-glass />
            <input type="text" id="f-buscar" placeholder="Buscar por CI o nombre…">
        </div>
    </div>

    {{-- Aquí se inyecta el parcial funcionarios.list (tabla + paginación). --}}
    <div id="div-results" style="min-height: 8rem;">
        <div class="vacio">Cargando…</div>
    </div>

    <script>
        (function () {
            const url = @json(route('funcionarios.list'));
            const resultados = document.getElementById('div-results');
            const inputBuscar = document.getElementById('f-buscar');
            const selPaginate = document.getElementById('f-paginate');
            const selFuente = document.getElementById('f-fuente');
            const selContrato = document.getElementById('f-contrato');
            const grupoCon = selContrato.querySelector('optgroup');

            // El filtro por contrato solo aplica a Mamoré (SIAT no tiene contratos).
            function sincronizarContrato() {
                const esMamore = selFuente.value === 'mamore';
                selContrato.hidden = !esMamore;
                if (!esMamore) { selContrato.value = 'todos'; }
            }

            // Un tipo viaja como «tipo:5»: es «con contrato» de ese tipo.
            function filtroElegido() {
                const valor = selContrato.value;
                return valor.startsWith('tipo:')
                    ? { contrato: 'con', tipo: valor.slice(5) }
                    : { contrato: valor, tipo: '' };
            }

            // La API devuelve cuántos hay en cada situación (ya con la búsqueda
            // aplicada): se muestran en la etiqueta de cada opción.
            const etiquetas = { todos: 'Todos', con: 'Todos con contrato', sin: 'Sin contrato' };
            const conTotal = (texto, total) => total === '' || total === undefined
                ? texto
                : `${texto} (${Number(total).toLocaleString('es-BO')})`;

            function actualizarTotales() {
                const datos = resultados.querySelector('#totales-contrato');
                for (const valor of Object.keys(etiquetas)) {
                    const opcion = selContrato.querySelector(`option[value="${valor}"]`);
                    opcion.textContent = conTotal(etiquetas[valor], datos ? datos.dataset[valor] : '');
                }
                actualizarTipos(datos ? JSON.parse(datos.dataset.tipos || '[]') : []);
            }

            // Los tipos se rearman con cada carga, conservando la elección. La API
            // omite los tipos sin nadie: si la búsqueda vació el elegido, se
            // mantiene igual, con (0), para que el select diga lo que se ve.
            function actualizarTipos(tipos) {
                const elegido = selContrato.value;
                const nombreElegido = selContrato.selectedOptions[0]?.dataset.nombre;
                grupoCon.querySelectorAll('option[value^="tipo:"]').forEach((opcion) => opcion.remove());
                for (const tipo of tipos) {
                    const opcion = new Option(conTotal(tipo.nombre, tipo.total), `tipo:${tipo.id}`);
                    opcion.dataset.nombre = tipo.nombre;
                    grupoCon.append(opcion);
                }
                if (elegido.startsWith('tipo:') && !grupoCon.querySelector(`option[value="${elegido}"]`)) {
                    const opcion = new Option(conTotal(nombreElegido, 0), elegido);
                    opcion.dataset.nombre = nombreElegido;
                    grupoCon.append(opcion);
                }
                selContrato.value = elegido;
            }

            async function cargar(page = 1) {
                const { contrato, tipo } = filtroElegido();
                const params = new URLSearchParams({
                    fuente: selFuente.value,
                    contrato: contrato,
                    tipo: tipo,
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
                    actualizarTotales();
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
            selContrato.addEventListener('change', () => cargar(1));
            selFuente.addEventListener('change', () => { sincronizarContrato(); cargar(1); });
            // La búsqueda se dispara solo con Enter: escribir no recarga la tabla.
            inputBuscar.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') { e.preventDefault(); cargar(1); }
            });

            sincronizarContrato();
            cargar(1);
        })();
    </script>
@endsection
