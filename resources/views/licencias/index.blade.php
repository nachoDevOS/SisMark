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

    <p class="ayuda" style="margin: -.4rem 0 1rem;">Permisos y ausencias justificadas de los funcionarios, por día y horario.</p>

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
            <label class="filtro">Desde <input type="date" id="f-desde" value="{{ $desde }}"></label>
            <label class="filtro">Hasta <input type="date" id="f-hasta" value="{{ $hasta }}"></label>

            {{-- Filtro de estado: es cómo Recursos Humanos encuentra las solicitudes
                 que llegaron de Mamoré y todavía esperan decisión, sin tener que
                 barrer un listado dominado por el histórico ya aprobado. --}}
            <label class="filtro">
                Estado
                <select id="f-estado" aria-label="Filtrar por estado de aprobación">
                    <option value="">Todos</option>
                    @foreach (\App\Models\Licencia::ESTADOS as $e)
                        <option value="{{ $e }}" @selected($estado === $e)>{{ $e }}</option>
                    @endforeach
                </select>
            </label>

            {{-- Filtro de tipo: separa los permisos personales —los que cuentan
                 contra el tope— de las licencias institucionales. --}}
            <label class="filtro">
                Tipo
                <select id="f-tipo" aria-label="Filtrar por tipo de licencia">
                    <option value="">Todos</option>
                    @foreach (\App\Models\Licencia::TIPOS as $valor => $etiqueta)
                        <option value="{{ $valor }}" @selected($tipo === $valor)>{{ $etiqueta }}</option>
                    @endforeach
                </select>
            </label>

            {{-- Fechas, estado y tipo recién se aplican con este botón. --}}
            <button type="button" id="btn-buscar" class="btn"><x-heroicon-o-magnifying-glass />Buscar</button>
        </div>

        <div class="buscador">
            <x-heroicon-o-magnifying-glass />
            <input type="text" id="f-buscar" value="{{ $busqueda }}" placeholder="Buscar por CI, funcionario o motivo…">
        </div>
    </div>

    {{-- Resolución en lote. Las casillas vienen en el parcial de la tabla; acá
         vive lo que sobrevive a cada recarga: lo marcado, la barra de abajo y el
         modal de confirmación. El `change` de las casillas sube hasta este
         contenedor, así no hace falta inicializar nada en el HTML inyectado. --}}
    {{-- Con el modal abierto la página de atrás no se mueve: la rueda queda
         para la lista del modal. --}}
    <div x-data="loteLicencias" x-on:licencias-cargadas.window="reiniciar($event.detail.conservar)" x-on:change="cambio($event)"
         x-effect="document.documentElement.style.overflow = accion ? 'hidden' : ''">
        @can('Approve:Licencia')
            <div class="lote-aviso" x-show="paginaCompleta && ! todas && datos.solicitudes > enPagina" x-cloak x-transition.opacity>
                <x-heroicon-o-information-circle />
                <span>
                    Marcaste las <strong x-text="enPagina"></strong> pendientes de esta página.
                    <button type="button" class="lote-aviso__enlace" x-on:click="marcarTodas()">
                        Marcar las <span x-text="datos.solicitudes"></span> que coinciden con el filtro
                    </button>
                </span>
            </div>
            <div class="lote-aviso lote-aviso--todas" x-show="todas" x-cloak x-transition.opacity>
                <x-heroicon-o-check-badge />
                <span>
                    Están marcadas <strong>todas</strong> las <strong x-text="datos.solicitudes"></strong> solicitudes
                    pendientes que coinciden con el filtro (<span x-text="datos.dias"></span> días), también las de
                    otras páginas.
                    <button type="button" class="lote-aviso__enlace" x-on:click="limpiar()">Desmarcar</button>
                </span>
            </div>
        @endcan

        {{-- Aquí se inyecta el parcial licencias.list (tabla + paginación). --}}
        <div id="div-results" style="min-height: 8rem;">
            <div class="vacio">Cargando…</div>
        </div>

        @can('Approve:Licencia')
            {{-- Barra fija: aparece al marcar la primera y dice cuánto se va a
                 resolver antes de tocar nada. --}}
            <div class="lote-barra" x-show="cantidad > 0" x-cloak
                 x-transition:enter="lote-barra--entra" x-transition:enter-start="lote-barra--oculta"
                 x-transition:leave="lote-barra--entra" x-transition:leave-end="lote-barra--oculta">
                <div class="lote-barra__cuenta">
                    <span class="lote-barra__numero" x-text="cantidad"></span>
                    <span>
                        <strong x-text="cantidad === 1 ? 'solicitud marcada' : 'solicitudes marcadas'"></strong>
                        <small x-text="`${dias} día(s) en total`"></small>
                    </span>
                </div>
                <div class="lote-barra__acciones">
                    <button type="button" class="lote-barra__limpiar" x-on:click="limpiar()">Desmarcar</button>
                    <button type="button" class="btn btn--peligro" x-on:click="abrir('rechazar')"><x-heroicon-o-no-symbol />Rechazar</button>
                    <button type="button" class="btn" x-on:click="abrir('aprobar')"><x-heroicon-o-check />Aprobar</button>
                </div>
            </div>

            {{-- Confirmación: qué se resuelve, a quiénes, y la nota que va a
                 todas. Rechazar exige el motivo, que cada funcionario lee en su
                 perfil. --}}
            <div class="modal-fondo" x-show="accion" x-cloak
                 x-on:click.self="if (! enviando) accion = null"
                 x-on:keydown.escape.window="if (! enviando) accion = null">
                <div class="modal-caja lote-modal">
                    <div class="lote-modal__cabeza">
                        <div class="lote-modal__icono" :class="accion === 'rechazar' ? 'lote-modal__icono--rechazo' : ''">
                            <span x-show="accion === 'aprobar'"><x-heroicon-o-check-badge /></span>
                            <span x-show="accion === 'rechazar'"><x-heroicon-o-no-symbol /></span>
                        </div>
                        <div>
                            <h2 x-text="`${accion === 'aprobar' ? 'Aprobar' : 'Rechazar'} ${cantidad} ${cantidad === 1 ? 'solicitud' : 'solicitudes'}`"></h2>
                            <p class="lote-modal__sub">
                                <span x-text="`${dias} día(s) en total`"></span>
                                <span x-show="! todas" x-text="`· ${resumen.personas} ${resumen.personas === 1 ? 'funcionario' : 'funcionarios'}`"></span>
                            </p>
                        </div>
                    </div>

                    {{-- De un vistazo: cómo se reparte lo marcado. --}}
                    <div class="lote-modal__resumen" x-show="! todas">
                        <span class="pill pill--info" x-show="resumen.completo" x-text="`${resumen.completo} de horario completo`"></span>
                        <span class="pill pill--neutro" x-show="resumen.porHoras" x-text="`${resumen.porHoras} por horas`"></span>
                        <span class="pill pill--ok" x-show="resumen.conGoce" x-text="`${resumen.conGoce} con goce`"></span>
                        <span class="pill pill--no" x-show="resumen.sinGoce" x-text="`${resumen.sinGoce} sin goce`"></span>
                        <span class="pill pill--advertencia" x-show="resumen.personalesPorHoras"
                              x-text="`${resumen.personalesPorHoras} contra el tope de permisos`"></span>
                    </div>

                    {{-- Qué pasa exactamente al confirmar. --}}
                    <ul class="lote-modal__efectos" x-show="accion === 'aprobar'">
                        <li>
                            <x-heroicon-o-calendar-days />
                            <span>Cada día queda <strong>justificado</strong> en la asistencia procesada —reporte,
                            Excel y perfil del funcionario en Mamoré— con su alcance: el <strong>horario completo</strong>
                            cubre la jornada entera; el permiso <strong>por horas</strong>, solo ese tramo.</span>
                        </li>
                        <li>
                            <x-heroicon-o-scale />
                            <span>Los <strong>permisos personales por horas</strong> se controlan uno por uno contra el
                            tope mensual: el que lo pase queda <strong>pendiente</strong> y se avisa su CI; el resto se aprueba igual.</span>
                        </li>
                        <li>
                            <x-heroicon-o-user-circle />
                            <span>Queda registrado quién aprobó y cuándo, en cada solicitud.</span>
                        </li>
                    </ul>
                    <ul class="lote-modal__efectos lote-modal__efectos--rechazo" x-show="accion === 'rechazar'">
                        <li>
                            <x-heroicon-o-calendar-days />
                            <span>Los días <strong>dejan de estar justificados</strong> y no cuentan para el tope de permisos.</span>
                        </li>
                        <li>
                            <x-heroicon-o-chat-bubble-left-ellipsis />
                            <span>El motivo es el mismo para todas, y cada funcionario lo lee en su perfil de Mamoré.</span>
                        </li>
                        <li>
                            <x-heroicon-o-lock-closed />
                            <span><strong>No se puede revertir</strong>: lo rechazado no se aprueba ni se elimina.</span>
                        </li>
                    </ul>

                    <div class="lote-aviso lote-aviso--todas" x-show="todas">
                        <x-heroicon-o-information-circle />
                        <span>
                            Van <strong>todas</strong> las <strong x-text="datos.solicitudes"></strong> pendientes del filtro,
                            también las de otras páginas. Abajo están las que se ven; si quitás una, se envían
                            solo las de esta lista.
                        </span>
                    </div>

                    {{-- El detalle de lo marcado, con la ✕ para sacar una sin
                         cerrar el modal. --}}
                    <div class="lote-modal__tabla">
                        <table>
                            <thead>
                                <tr>
                                    <th>Funcionario</th>
                                    <th>Fecha</th>
                                    <th>Alcance</th>
                                    <th>Haberes</th>
                                    <th>Tipo</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="item in lista" :key="item.id">
                                    <tr>
                                        <td>
                                            <strong x-text="item.nombre"></strong>
                                            <div class="ayuda" x-text="`CI ${item.ci}`"></div>
                                        </td>
                                        <td>
                                            <span x-text="item.periodo"></span>
                                            <div class="ayuda" x-text="`${item.dias} día(s)`"></div>
                                        </td>
                                        <td><span class="pill" :class="item.completo ? 'pill--info' : 'pill--neutro'" x-text="item.alcance"></span></td>
                                        <td><span class="pill" :class="item.goce ? 'pill--ok' : 'pill--no'" x-text="item.goce ? 'Con goce' : 'Sin goce'"></span></td>
                                        <td>
                                            <span class="pill" :class="item.tipoPill" x-text="item.tipo"></span>
                                            <div class="ayuda lote-modal__motivo" x-text="item.motivo" :title="item.motivo"></div>
                                        </td>
                                        <td>
                                            <button type="button" class="lote-modal__quitar" x-on:click="quitar(item.id)" :disabled="enviando"
                                                    title="Quitar de la selección" aria-label="Quitar de la selección">
                                                <x-heroicon-o-x-mark />
                                            </button>
                                        </td>
                                    </tr>
                                </template>
                                <tr x-show="todas && datos.solicitudes > lista.length">
                                    <td colspan="6" class="lote-modal__mas" x-text="`y ${datos.solicitudes - lista.length} más de otras páginas`"></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <form method="POST" x-ref="formulario" x-on:submit="enviando = true"
                          :action="accion === 'rechazar' ? @js(route('licencias.lote.rechazar')) : @js(route('licencias.lote.aprobar'))">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="todas" :value="todas ? 1 : 0">
                        <template x-for="id in (todas ? [] : Object.keys(marcadas))" :key="id">
                            <input type="hidden" name="solicitudes[]" :value="id">
                        </template>
                        <template x-for="(valor, campo) in datos.filtros" :key="campo">
                            <input type="hidden" :name="campo" :value="valor">
                        </template>

                        <div class="campo">
                            <label for="lote-observacion">
                                <span x-show="accion === 'aprobar'">Observación</span>
                                <span x-show="accion === 'rechazar'">Motivo del rechazo <span class="req">*</span></span>
                            </label>
                            <textarea id="lote-observacion" name="observacion" rows="2" maxlength="500"
                                      :required="accion === 'rechazar'"
                                      :placeholder="accion === 'rechazar' ? 'Va a todas las marcadas. Por ejemplo: falta el memorándum.' : 'Opcional. Queda registrada en todas.'"></textarea>
                        </div>

                        <div class="modal-acciones">
                            <button type="button" class="btn btn--gris" x-on:click="accion = null" :disabled="enviando">
                                <x-heroicon-o-x-mark />Cancelar
                            </button>
                            <button type="submit" class="btn" :class="accion === 'rechazar' ? 'btn--peligro' : ''" :disabled="enviando">
                                <span class="btn__contenido" x-show="! enviando">
                                    <span x-show="accion === 'aprobar'" style="display: contents;"><x-heroicon-o-check /></span>
                                    <span x-show="accion === 'rechazar'" style="display: contents;"><x-heroicon-o-no-symbol /></span>
                                    <span x-text="`${accion === 'aprobar' ? 'Aprobar' : 'Rechazar'} ${cantidad}`"></span>
                                </span>
                                <span class="btn__contenido" x-show="enviando" x-cloak>
                                    <span class="spinner-anillo"></span>
                                    <span x-text="accion === 'aprobar' ? 'Aprobando…' : 'Rechazando…'"></span>
                                </span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        @endcan
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('loteLicencias', () => ({
                {{-- id de la fila que abre la solicitud → lo que se muestra de ella. --}}
                marcadas: {},
                todas: false,
                datos: { solicitudes: 0, dias: 0, filtros: {} },
                enPagina: 0,
                paginaCompleta: false,
                accion: null,
                enviando: false,

                get cantidad() {
                    return this.todas ? this.datos.solicitudes : Object.keys(this.marcadas).length;
                },
                get dias() {
                    return this.todas
                        ? this.datos.dias
                        : Object.values(this.marcadas).reduce((total, m) => total + m.dias, 0);
                },
                get lista() {
                    return Object.values(this.marcadas);
                },
                get resumen() {
                    const lista = this.lista;
                    const cuenta = (condicion) => lista.filter(condicion).length;
                    return {
                        personas: new Set(lista.map((m) => m.ci)).size,
                        completo: cuenta((m) => m.completo),
                        porHoras: cuenta((m) => ! m.completo),
                        conGoce: cuenta((m) => m.goce),
                        sinGoce: cuenta((m) => ! m.goce),
                        personalesPorHoras: cuenta((m) => m.personal && ! m.completo),
                    };
                },

                casillas() {
                    return [...document.querySelectorAll('#div-results .lote-check')];
                },

                {{-- Al pasar de página lo marcado se conserva y la página nueva
                     muestra tildado lo que ya estaba. Con otro filtro arranca de
                     cero: lo marcado era de otra búsqueda. --}}
                reiniciar(conservar = false) {
                    const datos = document.getElementById('lote-datos');
                    this.datos = datos
                        ? { solicitudes: +datos.dataset.solicitudes, dias: +datos.dataset.dias, filtros: JSON.parse(datos.dataset.filtros) }
                        : { solicitudes: 0, dias: 0, filtros: {} };

                    if (! conservar) {
                        this.marcadas = {};
                        this.todas = false;
                    }

                    for (const c of this.casillas()) {
                        c.checked = this.todas || c.value in this.marcadas;
                    }

                    this.sincronizar();
                },

                cambio(evento) {
                    const casilla = evento.target;

                    if (casilla.classList.contains('lote-pagina')) {
                        this.salirDeTodas();
                        for (const c of this.casillas()) { c.checked = casilla.checked; this.anotar(c); }
                    } else if (casilla.classList.contains('lote-check')) {
                        this.salirDeTodas();
                        this.anotar(casilla);
                    } else {
                        return;
                    }

                    this.sincronizar();
                },

                {{-- Tocar una casilla con «todas» activo vuelve a una selección
                     explícita: lo que ya estaba marcado más lo tildado en esta
                     página. Las de otras páginas que solo entraban por «todas» no
                     se pueden nombrar, así que quedan afuera. --}}
                salirDeTodas() {
                    if (! this.todas) { return; }
                    this.todas = false;
                    for (const c of this.casillas()) { this.anotar(c); }
                },

                {{-- Sacar una desde el modal: se destilda también en la tabla si
                     está a la vista, y sin nada marcado el modal se cierra. --}}
                quitar(id) {
                    this.salirDeTodas();
                    delete this.marcadas[id];
                    const casilla = this.casillas().find((c) => c.value === String(id));
                    if (casilla) { casilla.checked = false; }
                    this.sincronizar();
                    if (this.cantidad === 0) { this.accion = null; }
                },

                anotar(casilla) {
                    const d = casilla.dataset;
                    if (casilla.checked) {
                        this.marcadas[casilla.value] = {
                            id: casilla.value,
                            dias: +d.dias,
                            nombre: d.nombre,
                            ci: d.ci,
                            periodo: d.periodo,
                            alcance: d.alcance,
                            completo: d.completo === '1',
                            goce: d.goce === '1',
                            tipo: d.tipo,
                            tipoPill: d.tipoPill,
                            personal: d.personal === '1',
                            motivo: d.motivo,
                        };
                    } else {
                        delete this.marcadas[casilla.value];
                    }
                },

                sincronizar() {
                    const casillas = this.casillas();
                    const marcadasEnPagina = casillas.filter((c) => c.checked).length;
                    this.enPagina = casillas.length;
                    this.paginaCompleta = casillas.length > 0 && marcadasEnPagina === casillas.length;

                    const cabecera = document.querySelector('#div-results .lote-pagina');
                    if (cabecera) {
                        cabecera.checked = this.paginaCompleta;
                        cabecera.indeterminate = marcadasEnPagina > 0 && ! this.paginaCompleta;
                        cabecera.disabled = casillas.length === 0;
                    }
                },

                marcarTodas() {
                    this.todas = true;
                },

                limpiar() {
                    this.marcadas = {};
                    this.todas = false;
                    for (const c of this.casillas()) { c.checked = false; }
                    this.sincronizar();
                },

                abrir(accion) {
                    this.accion = accion;
                    this.$nextTick(() => document.getElementById('lote-observacion')?.focus());
                },
            }));
        });
    </script>

    <script>
        (function () {
            const url = @json(route('licencias.list'));
            const resultados = document.getElementById('div-results');
            const inputBuscar = document.getElementById('f-buscar');
            const selPaginate = document.getElementById('f-paginate');
            const selEstado = document.getElementById('f-estado');
            const selTipo = document.getElementById('f-tipo');
            // Los que se ven en la tabla: los combos solo cuentan al apretar Buscar.
            const dateDesde = document.getElementById('f-desde');
            const dateHasta = document.getElementById('f-hasta');
            const aplicados = { desde: dateDesde.value, hasta: dateHasta.value, estado: selEstado.value, tipo: selTipo.value };

            async function cargar(page = 1, conservarSeleccion = false) {
                const params = new URLSearchParams({
                    q: inputBuscar.value,
                    desde: aplicados.desde,
                    hasta: aplicados.hasta,
                    estado: aplicados.estado,
                    tipo: aplicados.tipo,
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
                // La selección en lote se vuelve a pintar sobre la tabla recién llegada.
                window.dispatchEvent(new CustomEvent('licencias-cargadas', { detail: { conservar: conservarSeleccion } }));
            }

            // Paginación: los enlaces del parcial se inyectan dinámicamente, se
            // delega el click sobre el contenedor.
            resultados.addEventListener('click', function (e) {
                const enlace = e.target.closest('a.pag__link');
                if (!enlace) { return; }
                e.preventDefault();
                const page = new URL(enlace.href).searchParams.get('page') || 1;
                // Cambiar de página no es cambiar de búsqueda: lo marcado sigue.
                cargar(page, true);
            });

            selPaginate.addEventListener('change', () => cargar(1));
            document.getElementById('btn-buscar').addEventListener('click', () => {
                aplicados.desde = dateDesde.value;
                aplicados.hasta = dateHasta.value;
                aplicados.estado = selEstado.value;
                aplicados.tipo = selTipo.value;
                cargar(1);
            });
            // La búsqueda se dispara solo con Enter: escribir no recarga la tabla.
            inputBuscar.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') { e.preventDefault(); cargar(1); }
            });

            cargar(1);
        })();
    </script>
@endsection
