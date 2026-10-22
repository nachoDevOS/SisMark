@props([
    'ci',
    'origen' => '',
    'etiqueta' => 'Asignar turno',
])

@php
    $ciFijo = trim((string) $ci);
    $sufijo = 'ci'.preg_replace('/\W/', '', $ciFijo);
    // Varios formularios de la ficha comparten nombres de campo (`desde`,
    // `hasta`…): el marcador dice cuál rebotó, para reabrir solo ese modal.
    $rebote = old('_form') === 'turno-asignado';
    $turnos = \App\Models\Turno::query()->with('horarios')->orderByDesc('sugerido')->orderBy('nombre')->get();
@endphp

{{-- Asignarle un turno a un funcionario ya conocido, sin salir de su ficha. El
     formulario de `turnos-asignados/create` sigue existiendo para cuando se
     entra por el listado y hay que elegir a la persona.

     Quién es no se repite: la ficha está detrás del modal. Cabecera y pie quedan
     fijos y solo el medio se desplaza, así el botón y el resumen se ven siempre. --}}
@can('create', \App\Models\AsignacionTurno::class)
    @include('turnos-asignados._ficha-asignacion-js')

    @once
        <style>
            .asignar { max-width: 46rem; padding: 0; display: flex; flex-direction: column; max-height: calc(100vh - 2rem); overflow: hidden; }
            .asignar__form { display: flex; flex-direction: column; min-height: 0; flex: 1; margin: 0; }
            .asignar__cabecera { padding: 1rem 1.25rem .85rem; border-bottom: 1px solid var(--border); }
            .asignar__titulo { display: flex; align-items: flex-start; justify-content: space-between; gap: .75rem; }
            .asignar__titulo h2 { margin: 0; font-size: 1.1rem; }
            .asignar__persona { color: var(--muted); font-size: .8rem; margin-top: .1rem; }
            .asignar__cerrar { background: none; border: 0; cursor: pointer; color: var(--muted); padding: .2rem; border-radius: .35rem; }
            .asignar__cerrar:hover { background: var(--bg); color: var(--fg); }
            .asignar__cerrar svg { width: 1.25rem; height: 1.25rem; }
            .asignar__estado { margin-top: .65rem; display: flex; flex-wrap: wrap; gap: .35rem; align-items: center; font-size: .78rem; }
            .asignar__cuerpo { overflow-y: auto; flex: 1; padding: .25rem 1.25rem 1rem; }
            .asignar__pie { border-top: 1px solid var(--border); padding: .85rem 1.25rem; background: #fafafa;
                display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
            .asignar__resumen { font-size: .8rem; color: var(--fg); flex: 1; min-width: 14rem; }
            .asignar__resumen--pendiente { color: var(--muted); }
            .asignar__botones { display: flex; gap: .5rem; margin-left: auto; }

            .asignar-paso { display: grid; grid-template-columns: 1.9rem 1fr; gap: .75rem; padding: 1rem 0 .25rem; }
            .asignar-paso + .asignar-paso { border-top: 1px solid var(--border); }
            .asignar-paso__numero { width: 1.9rem; height: 1.9rem; border-radius: 9999px; display: inline-flex; align-items: center;
                justify-content: center; font-weight: 700; font-size: .85rem; background: var(--bg); color: var(--muted);
                border: 1.5px solid var(--border); transition: all .15s; }
            .asignar-paso--listo .asignar-paso__numero { background: var(--verde); border-color: var(--verde); color: #fff; }
            .asignar-paso h3 { margin: .3rem 0 .15rem; font-size: .92rem; }
            .asignar-paso__ayuda { color: var(--muted); font-size: .75rem; margin: 0 0 .6rem; line-height: 1.4; }

            .asignar-contrato { display: flex; align-items: center; gap: .6rem; border: 1.5px solid var(--border); border-radius: .55rem;
                padding: .55rem .75rem; margin-bottom: .4rem; font-size: .8rem; }
            .asignar-contrato--elegible { cursor: pointer; }
            .asignar-contrato--elegible:hover { border-color: #86efac; }
            .asignar-contrato--elegido { border-color: var(--verde); background: #f0fdf4; }
            .asignar-contrato--ocupado { opacity: .6; }
            .asignar-contrato input { accent-color: var(--verde); }
            .asignar-contrato__datos { color: var(--muted); display: block; font-size: .75rem; margin-top: .1rem; }
            .asignar-anteriores { margin-top: .5rem; border: 1px dashed var(--border); border-radius: .55rem; }
            .asignar-anteriores__toggle { display: flex; align-items: center; gap: .45rem; width: 100%; background: none; border: 0;
                padding: .55rem .75rem; cursor: pointer; font: inherit; font-size: .8rem; font-weight: 600; color: var(--fg); text-align: left; }
            .asignar-anteriores__toggle:hover { background: var(--bg); }
            .asignar-anteriores__toggle svg { width: 1rem; height: 1rem; color: var(--muted); transition: transform .15s; flex-shrink: 0; }
            .asignar-anteriores__toggle--abierto svg { transform: rotate(90deg); }
            .asignar-anteriores__lista { border-top: 1px dashed var(--border); max-height: 13rem; overflow-y: auto; }
            .asignar-anterior { display: flex; align-items: center; gap: .55rem; padding: .4rem .75rem; font-size: .78rem; }
            .asignar-anterior + .asignar-anterior { border-top: 1px solid var(--border); }
            .asignar-anterior { cursor: pointer; }
            .asignar-anterior:hover { background: var(--bg); }
            .asignar-anterior--elegido { background: #f0fdf4; }
            .asignar-anterior input { accent-color: var(--verde); }
            .asignar-anterior__fechas { font-variant-numeric: tabular-nums; white-space: nowrap; }
            .asignar-anterior__tramo { color: #92400e; font-size: .72rem; font-weight: 600; white-space: nowrap; }
            .asignar-paso--apagado { opacity: .4; pointer-events: none; filter: grayscale(1); }
            .asignar-nada { display: flex; gap: .65rem; align-items: flex-start; margin: 0 0 .6rem; }
            .asignar-nada svg { width: 1.25rem; height: 1.25rem; flex-shrink: 0; margin-top: .05rem; }
            .asignar-fechas { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; }
            .asignar-fechas .campo { margin: 0; }
            .asignar-avisos { display: grid; gap: .4rem; margin-top: .6rem; }
            .asignar-avisos .aviso { margin: 0; font-size: .78rem; }
            @media (max-width: 560px) { .asignar-fechas { grid-template-columns: 1fr; } }
        </style>
    @endonce

    <div x-data="{
             ...fichaAsignacion({
                 contratoId: @js($rebote ? old('contrato_id') : null),
                 turnoNombre: @js($rebote ? $turnos->firstWhere('id', (int) old('turno_id'))?->nombre : null),
                 desde: @js($rebote ? old('desde') : now()->toDateString()),
                 hasta: @js($rebote ? old('hasta') : ''),
             }),
             abierto: @js($rebote),
             ci: @js($ciFijo),
             verAnteriores: false,
             contratosVigentes() {
                 return this.ficha ? this.ficha.contratos.filter(c => ! c.regularizar) : [];
             },
             // Los que ya terminaron y les queda algo sin control: van
             // plegados para no tapar al vigente.
             contratosAnteriores() {
                 return this.contratosVisibles().filter(c => c.regularizar);
             },
             anterioresCubiertos() {
                 return this.ficha ? this.ficha.contratos.filter(c => this.cubierto(c)).length : 0;
             },
             contratoListo() {
                 return this.ficha !== null && (! this.ficha.mamore || this.contratoId !== null);
             },
             // Los horarios del sistema anterior: los que siguen al inicio se
             // cortan el día antes (AsignadorTurnos); los que empiezan dentro
             // del rango no se pueden cortar y el servidor no deja asignar.
             heredadosQueSeCortan() {
                 return (this.ficha?.heredados || []).filter(h => this.desde && h.desde < this.desde && h.hasta >= this.desde);
             },
             heredadosQueChocan() {
                 return (this.ficha?.heredados || []).filter(h => this.desde && this.hasta && h.desde >= this.desde && h.desde <= this.hasta);
             },
             diaAntes(iso) {
                 const fecha = new Date(`${iso}T00:00:00`);
                 fecha.setDate(fecha.getDate() - 1);
                 return this.fechaTexto(`${fecha.getFullYear()}-${String(fecha.getMonth() + 1).padStart(2, '0')}-${String(fecha.getDate()).padStart(2, '0')}`);
             },
         }"
         x-init="$watch('abierto', valor => { if (valor && ! ficha) { cargarFicha(ci); } }); if (abierto) { cargarFicha(ci); }
                 $watch('ficha', valor => { if (valor && regularizando()) { verAnteriores = true; } })"
         {{ $attributes }}>
        <button type="button" class="btn" x-on:click="abierto = true">
            <x-heroicon-o-plus />{{ $etiqueta }}
        </button>

        <div class="modal-fondo" x-show="abierto" x-cloak
             x-on:click.self="abierto = false" x-on:keydown.escape.window="abierto = false">
            <div class="modal-caja asignar" role="dialog" aria-modal="true" aria-labelledby="asignar-titulo-{{ $sufijo }}">
                <form method="POST" action="{{ route('turnos-asignados.store') }}" class="asignar__form" x-on:change="notarTurno($event)">
                    @csrf
                    <input type="hidden" name="_form" value="turno-asignado">
                    <input type="hidden" name="ci" value="{{ $ciFijo }}">
                    <input type="hidden" name="origen" value="{{ $origen }}">
                    <input type="hidden" name="contrato_id" :value="contratoId || ''">

                    {{-- Cabecera: a quién y qué tiene hoy --}}
                    <div class="asignar__cabecera">
                        <div class="asignar__titulo">
                            <div>
                                <h2 id="asignar-titulo-{{ $sufijo }}">Asignar turno</h2>
                                <div class="asignar__persona">
                                    <strong x-text="ficha?.persona?.nombre || 'Funcionario'"></strong> · CI {{ $ciFijo }}
                                    <span x-show="ficha?.persona?.cargo" x-text="` · ${ficha?.persona?.cargo}`"></span>
                                </div>
                            </div>
                            <button type="button" class="asignar__cerrar" x-on:click="abierto = false" title="Cerrar" aria-label="Cerrar">
                                <x-heroicon-o-x-mark />
                            </button>
                        </div>

                        <div class="asignar__estado" x-show="ficha" x-cloak>
                            <template x-if="ficha && ficha.turnos.length === 0 && ficha.heredados.length === 0">
                                <span class="pill pill--advertencia">Hoy no tiene turno ni horarios: no se le controla la asistencia</span>
                            </template>
                            {{-- Sin turno pero con horarios del SIA: igual se le controla. --}}
                            <template x-for="(heredado, i) in (ficha?.heredados || [])" :key="`h${i}`">
                                <span class="pill pill--info" title="Horarios cargados en el sistema anterior: con ellos se le controla la asistencia mientras no tenga turno"
                                      x-text="`Horarios del sistema anterior · ${heredado.desdeTexto} al ${heredado.hastaTexto}`"></span>
                            </template>
                            <template x-for="(turno, i) in (ficha?.turnos || [])" :key="i">
                                <span class="pill" :class="turno.vigente ? 'pill--ok' : 'pill--info'"
                                      :title="turno.contrato ? '' : 'Asignado a mano, sin contrato'"
                                      x-text="`${turno.vigente ? 'Tiene' : 'Empieza'} «${turno.nombre}» · ${turno.desdeTexto} al ${turno.hastaTexto}`"></span>
                            </template>
                        </div>
                        @error('ci') <div class="error">{{ $message }}</div> @enderror
                    </div>

                    <div class="asignar__cuerpo">
                        <p class="vacio" style="margin: 1rem 0;" x-show="cargandoFicha" x-cloak>
                            <span class="spinner-anillo spinner-anillo--verde"></span> Consultando sus contratos en Mamoré…
                        </p>
                        <div class="aviso aviso--error" style="margin-top: 1rem;" x-show="errorFicha" x-cloak x-text="errorFicha"></div>

                        <div x-show="ficha" x-cloak>
                            {{-- 1. Contrato --}}
                            <section class="asignar-paso" :class="contratoListo() && 'asignar-paso--listo'">
                                <span class="asignar-paso__numero">1</span>
                                <div>
                                    <h3>Contrato</h3>
                                    <p class="asignar-paso__ayuda" x-show="ficha && ! ficha.mamore">
                                        Mamoré no está configurado: el turno se asigna sin contrato.
                                    </p>
                                    <div class="aviso aviso--error" x-show="ficha?.errorContratos" x-text="ficha?.errorContratos"></div>
                                    {{-- Tiene contratos pero ninguno admite turno: se dice por qué
                                         y se apagan los pasos que siguen. --}}
                                    <div class="aviso aviso--advertencia asignar-nada" x-show="nadaQueAsignar()" x-cloak>
                                        <x-heroicon-o-information-circle />
                                        <span>
                                            <strong>No hay contrato al que asignarle turno.</strong>
                                            <span x-show="contratosVigentes().length === 0">
                                                No tiene contrato vigente, y sus contratos anteriores ya tienen turno o los cubrían
                                                horarios del sistema anterior: no hay nada que asignar ni que regularizar.
                                            </span>
                                            <span x-show="contratosVigentes().length > 0">
                                                Sus contratos ya tienen turno o los cubrían horarios del sistema anterior. Para
                                                cambiarle el turno, concluí el que tiene en la solapa «Turnos/Horarios».
                                            </span>
                                        </span>
                                    </div>
                                    <div class="aviso aviso--error" x-show="ficha?.mamore && ! ficha?.errorContratos && ficha?.contratos.length === 0">
                                        No tiene contrato firmado ni concluido en Mamoré, así que <strong>no se le puede asignar turno</strong>.
                                        Primero tiene que cargarse y firmarse en Mamoré.
                                    </div>

                                    <template x-for="contrato in contratosVigentes()" :key="contrato.id">
                                        <div class="asignar-contrato"
                                             :class="{
                                                 'asignar-contrato--elegido': String(contratoId) === String(contrato.id),
                                                 'asignar-contrato--ocupado': ! elegible(contrato),
                                                 'asignar-contrato--elegible': elegible(contrato) && contratosLibres().length > 1,
                                             }"
                                             x-on:click="if (elegible(contrato)) { marcarContrato(contrato, true); }">
                                            <input type="radio" :checked="String(contratoId) === String(contrato.id)"
                                                   x-show="elegible(contrato) && contratosLibres().length > 1"
                                                   :aria-label="`Elegir el contrato ${contrato.codigo || contrato.id}`">
                                            <x-heroicon-s-check-circle style="width: 1.15rem; height: 1.15rem; color: var(--verde); flex-shrink: 0;"
                                                                       x-show="String(contratoId) === String(contrato.id) && contratosLibres().length === 1" />
                                            <span style="flex: 1;">
                                                <strong x-text="contrato.codigo || `Contrato #${contrato.id}`"></strong>
                                                <span class="pill pill--mini" :class="contrato.empezo ? 'pill--ok' : 'pill--info'"
                                                      x-text="contrato.empezo ? 'Vigente' : 'Empieza el ' + contrato.desdeTexto"></span>
                                                <span class="asignar-contrato__datos"
                                                      x-text="[contrato.cargo, contrato.direccion, `${contrato.desdeTexto} al ${contrato.hastaTexto || 'sin fecha de fin'}`].filter(Boolean).join(' · ')"></span>
                                                <span class="asignar-contrato__datos" style="color: #92400e;" x-show="contrato.turno && ! elegible(contrato)"
                                                      x-text="contrato.turno ? `Ya tiene el turno «${contrato.turno.nombre}» (${contrato.turno.desdeTexto} al ${contrato.turno.hastaTexto}) hasta el fin del contrato.` : ''"></span>
                                                {{-- Le queda un tramo sin turno (cambio de jornada o un
                                                     hueco al concluir uno): el nuevo cubre justo eso. --}}
                                                <span class="asignar-contrato__datos" style="color: var(--verde); font-weight: 600;" x-show="contrato.turno && elegible(contrato)"
                                                      x-text="contrato.turno && contrato.cobertura ? `Sin turno del ${contrato.cobertura.libreDesdeTexto} al ${contrato.cobertura.libreHastaTexto || 'fin del contrato'}: el turno nuevo cubre ese tramo.` : ''"></span>
                                            </span>
                                        </div>
                                    </template>
                                    {{-- Contratos que ya terminaron: plegados (se abren solos solo si
                                         el elegido es uno de ellos) y en una línea cada uno. --}}
                                    <div class="asignar-anteriores" x-show="contratosAnteriores().length > 0">
                                        <button type="button" class="asignar-anteriores__toggle"
                                                :class="verAnteriores && 'asignar-anteriores__toggle--abierto'"
                                                :aria-expanded="verAnteriores" x-on:click="verAnteriores = ! verAnteriores">
                                            <x-heroicon-o-chevron-right />
                                            <span style="flex: 1;">Contratos anteriores
                                                <span style="color: var(--muted); font-weight: 400;" x-text="`(${contratosAnteriores().length})`"></span>
                                                <span style="color: var(--muted); font-weight: 400; font-size: .72rem;" x-show="anterioresCubiertos() > 0"
                                                      title="Contratos que ya terminaron con su turno o cubiertos por horarios del sistema anterior: no hay nada que hacer con ellos"
                                                      x-text="`· ${anterioresCubiertos()} ya ${anterioresCubiertos() === 1 ? 'cubierto' : 'cubiertos'}`"></span>
                                            </span>
                                            <span class="pill pill--mini pill--advertencia" x-show="contratosAnteriores().length > 0"
                                                  x-text="`${contratosAnteriores().length} por regularizar`"></span>
                                        </button>
                                        <div class="asignar-anteriores__lista" x-show="verAnteriores" x-cloak>
                                            <template x-for="contrato in contratosAnteriores()" :key="contrato.id">
                                                <div class="asignar-anterior"
                                                     :class="String(contratoId) === String(contrato.id) && 'asignar-anterior--elegido'"
                                                     :title="contrato.cobertura?.motivo || 'Pasó todo el contrato sin control: se regulariza entero.'"
                                                     x-on:click="marcarContrato(contrato, true)">
                                                    <input type="radio" :checked="String(contratoId) === String(contrato.id)"
                                                           :aria-label="`Elegir el contrato ${contrato.codigo || contrato.id}`">
                                                    <strong x-text="contrato.codigo || `#${contrato.id}`" style="min-width: 6.5rem;"></strong>
                                                    <span class="asignar-anterior__fechas" x-text="`${contrato.desdeTexto} al ${contrato.hastaTexto || '—'}`"></span>
                                                    <span style="flex: 1; min-width: 0; color: var(--muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"
                                                          x-text="[contrato.cargo, contrato.direccion].filter(Boolean).join(' · ')"></span>
                                                    {{-- Lo que se va a regularizar: el contrato entero o el
                                                         primer tramo sin control (y cuántos quedan después). --}}
                                                    <span class="asignar-anterior__tramo"
                                                          x-text="contrato.cobertura?.estado === 'parcial'
                                                              ? `Sin control del ${contrato.cobertura.libreDesdeTexto} al ${contrato.cobertura.libreHastaTexto || '—'}`
                                                                  + (contrato.cobertura.tramosPendientes > 0 ? ` (+${contrato.cobertura.tramosPendientes})` : '')
                                                              : 'Todo sin control'"></span>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                    <p class="asignar-paso__ayuda" style="margin-top: .3rem;" x-show="contratosLibres().length > 1 && ! contratoId">
                                        Tiene más de un contrato: tocá el que corresponde. Los anteriores ya terminaron sin turno y se regularizan.
                                    </p>
                                    @error('contrato_id') <div class="error">{{ $message }}</div> @enderror
                                </div>
                            </section>

                            {{-- 2. Turno --}}
                            <section class="asignar-paso" :class="{ 'asignar-paso--listo': turnoNombre, 'asignar-paso--apagado': nadaQueAsignar() }">
                                <span class="asignar-paso__numero">2</span>
                                <div>
                                    <h3>Turno</h3>
                                    <p class="asignar-paso__ayuda">La jornada de la semana que va a tener que cumplir.</p>
                                    @include('turnos-asignados._selector-turno', ['turnos' => $turnos, 'sufijo' => $sufijo])
                                </div>
                            </section>

                            {{-- 3. Vigencia --}}
                            <section class="asignar-paso" :class="{ 'asignar-paso--listo': desde && hasta && ! nadaQueAsignar(), 'asignar-paso--apagado': nadaQueAsignar() }">
                                <span class="asignar-paso__numero">3</span>
                                <div>
                                    <h3>Desde cuándo</h3>
                                    <p class="asignar-paso__ayuda">
                                        Desde el inicio se le controla la asistencia con este turno.
                                    </p>
                                    <div class="asignar-fechas">
                                        <div class="campo">
                                            <label for="turno-desde-{{ $sufijo }}">Inicio <span class="req">*</span></label>
                                            <input type="date" id="turno-desde-{{ $sufijo }}" name="desde"
                                                   x-model="desde" :min="minDesde" :max="maxHasta" required>
                                            @error('desde') <div class="error">{{ $message }}</div> @enderror
                                        </div>
                                        <div class="campo">
                                            <label for="turno-hasta-{{ $sufijo }}">Fin <span class="req">*</span></label>
                                            <input type="date" id="turno-hasta-{{ $sufijo }}" name="hasta"
                                                   x-model="hasta" :min="desde || minDesde" :max="maxHasta" :readonly="finFijo()"
                                                   :style="finFijo() && 'background: var(--bg); cursor: not-allowed;'" required>
                                            <p class="asignar-paso__ayuda" style="margin: .2rem 0 0;" x-show="finFijo()"
                                               x-text="contratoElegido()?.cobertura?.estado === 'parcial' ? 'Fin del tramo sin control.' : 'Sale del contrato.'"></p>
                                            @error('hasta') <div class="error">{{ $message }}</div> @enderror
                                        </div>
                                    </div>
                                    <div class="asignar-avisos">
                                        {{-- Cubierto en parte: el turno arranca donde terminan sus horarios. --}}
                                        <p class="aviso" x-show="regularizando() && contratoElegido()?.cobertura?.estado === 'parcial'"
                                           x-text="contratoElegido()?.cobertura?.motivo || ''"></p>
                                        <p class="aviso aviso--advertencia" x-show="regularizando()">
                                            <strong>Regularización:</strong> el contrato ya terminó y pasó sin turno. Los días del
                                            <strong x-text="fechaTexto(desde)"></strong> al <strong x-text="fechaTexto(hasta)"></strong> se van a
                                            controlar con este turno: los que no tengan marcación saldrán como falta en los reportes.
                                        </p>
                                        <p class="aviso aviso--advertencia" x-show="desde && desde < hoy() && ! regularizando()">
                                            El inicio ya pasó: los días del <strong x-text="fechaTexto(desde)"></strong> a hoy se van a
                                            controlar con este turno y pueden aparecer faltas o atrasos en los reportes.
                                        </p>
                                        <template x-for="(heredado, i) in heredadosQueSeCortan()" :key="`c${i}`">
                                            <p class="aviso">
                                                Sus horarios del sistema anterior (<span x-text="`${heredado.desdeTexto} al ${heredado.hastaTexto}`"></span>)
                                                terminan el <strong x-text="diaAntes(desde)"></strong> y desde el
                                                <strong x-text="fechaTexto(desde)"></strong> rige este turno.
                                            </p>
                                        </template>
                                        <template x-for="(heredado, i) in heredadosQueChocan()" :key="`x${i}`">
                                            <p class="aviso aviso--error">
                                                Tiene horarios del sistema anterior que empiezan el <strong x-text="heredado.desdeTexto"></strong>,
                                                dentro de estas fechas: concluilos o eliminalos en la solapa «Turnos/Horarios» antes de asignar.
                                            </p>
                                        </template>
                                        <p class="aviso aviso--advertencia" x-show="contratoElegido() && ! contratoElegido().hasta">
                                            El contrato no tiene fecha de fin: indicá hasta cuándo rige el turno.
                                        </p>
                                    </div>
                                </div>
                            </section>

                            {{-- 4. Observación --}}
                            <section class="asignar-paso" :class="nadaQueAsignar() && 'asignar-paso--apagado'">
                                <span class="asignar-paso__numero">4</span>
                                <div>
                                    <h3>Observación <span style="color: var(--muted); font-weight: 400;">(opcional)</span></h3>
                                    <div class="campo" style="margin: .4rem 0 0;">
                                        <input type="text" id="turno-obs-{{ $sufijo }}" name="observacion" maxlength="1000"
                                               value="{{ old('observacion') }}" placeholder="Ej.: memorándum, pedido de su jefe…"
                                               aria-label="Observación">
                                        @error('observacion') <div class="error">{{ $message }}</div> @enderror
                                    </div>
                                </div>
                            </section>
                        </div>
                    </div>

                    {{-- Pie fijo: lo que se va a guardar, al lado del botón --}}
                    <div class="asignar__pie">
                        <div class="asignar__resumen" x-show="puedeAsignar() && turnoNombre && desde && hasta">
                            <span x-text="regularizando() ? 'Se regulariza' : 'Se asigna'"></span> <strong x-text="`«${turnoNombre}»`"></strong>
                            del <strong x-text="fechaTexto(desde)"></strong> al <strong x-text="fechaTexto(hasta)"></strong><span
                                x-show="contratoElegido()">, contrato <strong x-text="contratoElegido()?.codigo || ''"></strong></span>.
                        </div>
                        <div class="asignar__resumen asignar__resumen--pendiente" x-show="! (puedeAsignar() && turnoNombre && desde && hasta)">
                            <span x-show="! ficha">Cargando…</span>
                            <span x-show="ficha && ! contratoListo() && ! nadaQueAsignar()">Falta el contrato.</span>
                            <span x-show="nadaQueAsignar()">No hay contrato al que asignarle turno.</span>
                            <span x-show="contratoListo() && ! turnoNombre">Elegí el turno.</span>
                            <span x-show="contratoListo() && turnoNombre && ! (desde && hasta)">Completá las fechas.</span>
                        </div>
                        <div class="asignar__botones">
                            <button type="button" class="btn btn--gris" x-on:click="abierto = false">Cancelar</button>
                            <button type="submit" class="btn" :disabled="! (puedeAsignar() && turnoNombre && desde && hasta)">
                                <x-heroicon-o-check /><span x-text="regularizando() ? 'Regularizar turno' : 'Asignar turno'">Asignar turno</span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endcan
