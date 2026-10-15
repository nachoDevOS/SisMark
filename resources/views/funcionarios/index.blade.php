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
                <option value="sia">SIA</option>
            </select>

            {{-- Solo Mamoré conoce los contratos: con SIA el select se oculta.
                 Dentro de «Con contrato» van los tipos (permanente, eventual…),
                 que llegan con cada carga junto con su total. --}}
            <select id="f-contrato" aria-label="Situación de contrato">
                <option value="todos">Todos</option>
                <optgroup label="Con contrato">
                    <option value="con">Todos con contrato</option>
                </optgroup>
                <option value="sin">Sin contrato</option>
            </select>

            {{-- Aparece solo con contrato (cualquier tipo): «Sin turnos»
                 es la lista de a quién falta asignarle un turno. Horario vigente
                 hoy, de un turno asignado o heredado del SIA. --}}
            <select id="f-horario" aria-label="Turnos y horarios" hidden>
                <option value="todos">Todos</option>
                <option value="con">Con turnos</option>
                <option value="sin">Sin turnos</option>
            </select>

            {{-- Fuente, contrato y turnos recién se aplican con este botón. --}}
            <button type="button" id="btn-buscar" class="btn"><x-heroicon-o-magnifying-glass />Buscar</button>
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
            const selHorario = document.getElementById('f-horario');
            const grupoCon = selContrato.querySelector('optgroup');

            // El filtro por contrato solo aplica a Mamoré (SIA no tiene contratos).
            function sincronizarContrato() {
                const esMamore = selFuente.value === 'mamore';
                selContrato.hidden = !esMamore;
                if (!esMamore) { selContrato.value = 'todos'; }
                sincronizarHorario();
            }

            // «Turnos/Horarios» solo con contrato: «Todos con contrato» o un tipo.
            function sincronizarHorario() {
                const conContrato = !selContrato.hidden
                    && (selContrato.value === 'con' || selContrato.value.startsWith('tipo:'));
                selHorario.hidden = !conContrato;
                if (!conContrato) { selHorario.value = 'todos'; }
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
            const etiquetas = {
                todos: 'Todos',
                con: 'Todos con contrato',
                sin: 'Sin contrato',
            };
            // Textos cortos: el combo de contrato, al lado, ya dice de quiénes se trata.
            const etiquetasHorario = { todos: 'Todos', con: 'Con turnos', sin: 'Sin turnos' };
            const conTotal = (texto, total) => total === '' || total === undefined
                ? texto
                : `${texto} (${Number(total).toLocaleString('es-BO')})`;

            function actualizarTotales(datos) {
                for (const valor of Object.keys(etiquetas)) {
                    const opcion = selContrato.querySelector(`option[value="${valor}"]`);
                    opcion.textContent = conTotal(etiquetas[valor], datos ? datos.dataset[valor] : '');
                }
                // Antes que el combo de horario: sus etiquetas llevan el nombre del tipo.
                actualizarTipos(datos ? JSON.parse(datos.dataset.tipos || '[]') : []);

                // data-con-horario llega al dataset como conHorario. «Todos» es la
                // suma, solo si vinieron las dos.
                const conHorario = datos ? datos.dataset.conHorario : '';
                const sinHorario = datos ? datos.dataset.sinHorario : '';
                const totalesHorario = {
                    todos: conHorario !== '' && sinHorario !== '' ? Number(conHorario) + Number(sinHorario) : '',
                    con: conHorario,
                    sin: sinHorario,
                };
                const textos = etiquetasHorario;
                for (const valor of Object.keys(textos)) {
                    selHorario.querySelector(`option[value="${valor}"]`).textContent = conTotal(textos[valor], totalesHorario[valor]);
                }
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

            // Los que se ven en la tabla: los combos solo cuentan al apretar Buscar.
            function leerFiltros() {
                return { fuente: selFuente.value, ...filtroElegido(), horario: selHorario.value };
            }
            let aplicados;

            async function cargar(page = 1) {
                const params = new URLSearchParams({
                    fuente: aplicados.fuente,
                    contrato: aplicados.contrato,
                    tipo: aplicados.tipo,
                    horario: aplicados.horario,
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
                    actualizarTotales(resultados.querySelector('#totales-contrato'));
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
            // Al elegir en un combo, el que depende de él aparece con sus
            // cantidades al día: se piden solo los totales, sin tocar la tabla,
            // que recién cambia con «Buscar». Si se eligió otra cosa mientras
            // tanto, la respuesta vieja se descarta.
            let pedidoDeConteo = 0;
            async function actualizarConteos() {
                if (selFuente.value !== 'mamore') { return; }
                const pedido = ++pedidoDeConteo;
                const filtros = leerFiltros();
                const params = new URLSearchParams({
                    fuente: filtros.fuente,
                    contrato: filtros.contrato,
                    tipo: filtros.tipo,
                    q: inputBuscar.value,
                    por_pagina: 10,
                    page: 1,
                });
                try {
                    const resp = await fetch(`${url}?${params.toString()}`, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    });
                    const html = await resp.text();
                    if (pedido !== pedidoDeConteo) { return; }
                    const plantilla = document.createElement('template');
                    plantilla.innerHTML = html;
                    actualizarTotales(plantilla.content.querySelector('#totales-contrato'));
                } catch (e) {
                    // Sin conteo se quedan las cantidades anteriores; «Buscar» las vuelve a pedir.
                }
            }

            selContrato.addEventListener('change', () => { sincronizarHorario(); actualizarConteos(); });
            selFuente.addEventListener('change', () => { sincronizarContrato(); actualizarConteos(); });
            document.getElementById('btn-buscar').addEventListener('click', () => {
                aplicados = leerFiltros();
                cargar(1);
            });
            // La búsqueda se dispara solo con Enter: escribir no recarga la tabla.
            inputBuscar.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') { e.preventDefault(); cargar(1); }
            });

            sincronizarContrato();
            aplicados = leerFiltros();
            cargar(1);
        })();
    </script>
@endsection
