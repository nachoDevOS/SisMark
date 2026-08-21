{{--
    Pie de la ficha del funcionario: marcaciones, licencias y turnos asignados
    en solapas, para no apilar tres tablas una debajo de la otra.

    Cada solapa tiene sus filtros y su propia paginación, y carga su tabla por
    AJAX la primera vez que se la abre (así entrar a la ficha pide una sola
    tabla, no tres).

    Espera: $ci, $desde, $hasta, $tipo, $reporteUrl (o null), $hayPersonaLocal
    y $origen (`local` / `mamore`, para volver acá tras asignar un turno).

    `$hayPersonaLocal` decide si se ofrece el alta manual de marcaciones: el CI
    se valida contra `personas`, así que en una ficha que solo está en Mamoré el
    botón no aparece.
--}}
@php
    $verLicencias = auth()->user()?->can('viewAny', \App\Models\Licencia::class) ?? false;
    $verTurnos = auth()->user()?->can('viewAny', \App\Models\AsignacionTurno::class) ?? false;
    // La solapa de asistencia procesada es el reporte de «Reportes → Procesado»
    // servido acá dentro, así que pide el mismo permiso que esa pantalla.
    $verReportes = auth()->user()?->can('ViewAny:Reporte') ?? false;
@endphp

<div class="tarjeta" style="margin-top: 1.5rem;">
    <div class="tabs" role="tablist">
        <button type="button" class="tabs__boton activo" role="tab" aria-selected="true" data-tab="marcaciones">
            <x-heroicon-o-finger-print />Marcaciones
        </button>
        @if ($verLicencias)
            <button type="button" class="tabs__boton" role="tab" aria-selected="false" data-tab="licencias">
                <x-heroicon-o-clipboard-document-check />Licencias
            </button>
        @endif
        @if ($verTurnos)
            <button type="button" class="tabs__boton" role="tab" aria-selected="false" data-tab="turnos">
                <x-heroicon-o-clock />Turnos
            </button>
        @endif
        @if ($verReportes)
            <button type="button" class="tabs__boton" role="tab" aria-selected="false" data-tab="procesado">
                <x-heroicon-o-document-chart-bar />Asistencia procesada
            </button>
        @endif
    </div>

    {{-- Solapa: marcaciones --}}
    <div class="tabs__panel" data-panel="marcaciones">
        <div class="tabla-filtros">
            <label class="tabla-filtros__mostrar">
                Mostrar
                <select id="m-paginate" aria-label="Cantidad de marcaciones a mostrar">
                    @foreach ([10, 25, 50, 100] as $n)
                        <option value="{{ $n }}">{{ $n }}</option>
                    @endforeach
                </select>
                registros
            </label>

            <div class="tabla-filtros__extra">
                <input type="date" id="m-desde" value="{{ $desde }}" aria-label="Desde">
                <input type="date" id="m-hasta" value="{{ $hasta }}" aria-label="Hasta">
                <select id="m-tipo" aria-label="Tipo de marcación">
                    <option value="">Todos</option>
                    <option value="{{ \App\Models\Asistencia::TIPO_RELOJ }}" @selected($tipo === \App\Models\Asistencia::TIPO_RELOJ)>R</option>
                    <option value="{{ \App\Models\Asistencia::TIPO_A }}" @selected($tipo === \App\Models\Asistencia::TIPO_A)>A</option>
                    <option value="{{ \App\Models\Asistencia::TIPO_MANUAL }}" @selected($tipo === \App\Models\Asistencia::TIPO_MANUAL)>M</option>
                </select>
                {{-- Alta manual para este funcionario. Solo si está en la base
                     local: el CI se valida contra `personas`, así que desde una
                     ficha que solo existe en Mamoré la carga rebotaría. --}}
                @if ($hayPersonaLocal ?? false)
                    <x-modal-marcacion :ci="$ci" :origen="$origen ?? ''" />
                @endif

                @if ($reporteUrl)
                    <a id="m-reporte" class="btn btn--gris" target="_blank" rel="noopener" href="{{ $reporteUrl }}">
                        <x-heroicon-o-printer />Imprimir reporte
                    </a>
                @endif
            </div>
        </div>

        <div id="m-results" style="min-height: 8rem;">
            <div class="vacio">Cargando…</div>
        </div>
    </div>

    {{-- Solapa: licencias --}}
    @if ($verLicencias)
        <div class="tabs__panel" data-panel="licencias" hidden>
            <div class="tabla-filtros">
                <label class="tabla-filtros__mostrar">
                    Mostrar
                    <select id="l-paginate" aria-label="Cantidad de licencias a mostrar">
                        @foreach ([10, 25, 50, 100] as $n)
                            <option value="{{ $n }}">{{ $n }}</option>
                        @endforeach
                    </select>
                    registros
                </label>

                <div class="tabla-filtros__extra">
                    {{-- Alta acotada a este funcionario; la pantalla «Licenciar»
                         queda para los feriados y las altas en lote. --}}
                    <x-modal-licencia :ci="$ci" :origen="$origen ?? ''" />
                </div>
            </div>

            <div id="l-results" style="min-height: 8rem;">
                <div class="vacio">Cargando…</div>
            </div>
        </div>
    @endif

    {{-- Solapa: turnos asignados --}}
    @if ($verTurnos)
        <div class="tabs__panel" data-panel="turnos" hidden>
            <div class="tabla-filtros">
                <label class="tabla-filtros__mostrar">
                    Mostrar
                    <select id="t-paginate" aria-label="Cantidad de turnos a mostrar">
                        @foreach ([10, 25, 50, 100] as $n)
                            <option value="{{ $n }}">{{ $n }}</option>
                        @endforeach
                    </select>
                    registros
                </label>

                <div class="tabla-filtros__extra">
                    <select id="t-situacion" aria-label="Situación de la asignación">
                        <option value="todas">Todas</option>
                        <option value="vigentes">Solo vigentes</option>
                        <option value="vencidas">Solo vencidas</option>
                    </select>

                    <x-modal-turno-asignado :ci="$ci" :origen="$origen ?? ''" />
                </div>
            </div>

            <div id="t-results" style="min-height: 8rem;">
                <div class="vacio">Cargando…</div>
            </div>
        </div>
    @endif

    {{-- Solapa: asistencia procesada.

         Es el mismo reporte de «Reportes → Marcaciones → Procesado», servido
         acá dentro con el funcionario ya elegido. No se duplica nada: la tabla
         la arma `ReporteMarcacionController::procesadoList()` y el parcial que
         devuelve —`reportes/marcaciones/procesado/lista`— ya viene sin layout,
         con sus propios botones de Imprimir y Excel. --}}
    @if ($verReportes)
        <div class="tabs__panel" data-panel="procesado" hidden>
            <div class="tabla-filtros">
                <div class="tabla-filtros__extra">
                    <input type="date" id="p-desde" value="{{ $desde }}" aria-label="Desde">
                    <input type="date" id="p-hasta" value="{{ $hasta }}" aria-label="Hasta">
                </div>
            </div>

            <div id="p-results" style="min-height: 8rem;">
                <div class="vacio">Cargando…</div>
            </div>
        </div>
    @endif

</div>

<script>
    (function () {
        const ci = @json($ci);
        const reporteBase = @json($reporteUrl);
        const enlaceReporte = document.getElementById('m-reporte');

        // Cada solapa: su endpoint, su contenedor y qué filtros manda.
        const paneles = {
            marcaciones: {
                url: @json(route('funcionarios.marcaciones.list')),
                contenedor: document.getElementById('m-results'),
                filtros: () => ({
                    desde: document.getElementById('m-desde').value,
                    hasta: document.getElementById('m-hasta').value,
                    tipo: document.getElementById('m-tipo').value,
                    por_pagina: document.getElementById('m-paginate').value,
                }),
                controles: ['m-desde', 'm-hasta', 'm-tipo', 'm-paginate'],
            },
            @if ($verLicencias)
            licencias: {
                url: @json(route('funcionarios.licencias.list')),
                contenedor: document.getElementById('l-results'),
                filtros: () => ({ por_pagina: document.getElementById('l-paginate').value }),
                controles: ['l-paginate'],
            },
            @endif
            @if ($verTurnos)
            turnos: {
                url: @json(route('funcionarios.turnos.list')),
                contenedor: document.getElementById('t-results'),
                filtros: () => ({
                    situacion: document.getElementById('t-situacion').value,
                    por_pagina: document.getElementById('t-paginate').value,
                    origen: @json($origen ?? ''),
                }),
                controles: ['t-situacion', 't-paginate'],
            },
            @endif
            @if ($verReportes)
            procesado: {
                url: @json(route('reportes.marcaciones.procesado.generar')),
                contenedor: document.getElementById('p-results'),
                // `persona` y no `ci`: es el nombre del parámetro que espera el
                // reporte, que resuelve la ficha por cédula contra Mamoré con la
                // base local como respaldo.
                filtros: () => ({
                    persona: ci,
                    desde: document.getElementById('p-desde').value,
                    hasta: document.getElementById('p-hasta').value,
                    // Sin la línea de nombre, CI, PIN y cargo: la cabecera de la
                    // ficha ya la muestra completa, unos centímetros más arriba.
                    encabezado: 0,
                }),
                controles: ['p-desde', 'p-hasta'],
            },
            @endif
        };

        // El reporte imprimible se abre con los mismos filtros que la tabla.
        function sincronizarReporte() {
            if (!enlaceReporte) { return; }
            const { por_pagina, ...filtros } = paneles.marcaciones.filtros();
            enlaceReporte.href = `${reporteBase}?${new URLSearchParams(filtros).toString()}`;
        }

        async function cargar(nombre, page = 1) {
            const panel = paneles[nombre];
            const params = new URLSearchParams({ ci: ci, ...panel.filtros(), page: page });
            panel.contenedor.innerHTML = '<div class="vacio">Cargando…</div>';
            try {
                const resp = await fetch(`${panel.url}?${params.toString()}`, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });
                panel.contenedor.innerHTML = await resp.text();
            } catch (e) {
                panel.contenedor.innerHTML = '<div class="aviso aviso--error">No se pudo cargar la tabla. Reintentá.</div>';
            }
        }

        for (const [nombre, panel] of Object.entries(paneles)) {
            // Paginación: los enlaces del parcial se inyectan dinámicamente, se
            // delega el click sobre el contenedor.
            panel.contenedor.addEventListener('click', function (e) {
                const enlace = e.target.closest('a.pag__link');
                if (!enlace) { return; }
                e.preventDefault();
                cargar(nombre, new URL(enlace.href).searchParams.get('page') || 1);
            });

            for (const id of panel.controles) {
                document.getElementById(id).addEventListener('change', () => {
                    if (nombre === 'marcaciones') { sincronizarReporte(); }
                    cargar(nombre, 1);
                });
            }
        }

        // Solapas: la tabla se pide la primera vez que se abre la suya.
        const cargadas = new Set();
        const botones = document.querySelectorAll('.tabs__boton');
        const contenedores = document.querySelectorAll('.tabs__panel');

        function mostrar(nombre) {
            for (const boton of botones) {
                const activo = boton.dataset.tab === nombre;
                boton.classList.toggle('activo', activo);
                boton.setAttribute('aria-selected', activo ? 'true' : 'false');
            }
            for (const contenedor of contenedores) {
                contenedor.hidden = contenedor.dataset.panel !== nombre;
            }
            // La solapa abierta queda en la URL: así una acción que recarga la
            // ficha (anotar o eliminar) vuelve a la solapa donde se estaba.
            history.replaceState(null, '', '#' + nombre);
            if (!cargadas.has(nombre)) {
                cargadas.add(nombre);
                cargar(nombre, 1);
            }
        }

        for (const boton of botones) {
            boton.addEventListener('click', () => mostrar(boton.dataset.tab));
        }

        const inicial = location.hash.replace('#', '');

        sincronizarReporte();
        mostrar(paneles[inicial] ? inicial : 'marcaciones');
    })();
</script>
