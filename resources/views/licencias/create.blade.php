@extends('layouts.app')

@section('titulo', 'Licenciar')

@php
    /** Abreviatura del día de la semana, como la grilla del sistema de escritorio. */
    $abreviar = fn (?int $dia): string => mb_strtoupper(mb_substr(\App\Models\Turno::DIAS[$dia] ?? '—', 0, 3));
    $nombre = $persona['nombre'] ?? '';
    $modoInicial = old('modo', 'uno');

    /**
     * Quiénes estaban marcados cuando el envío volvió por un error de validación.
     *
     * Sin esto la lista se remarca entera al recargarse, y quien había desmarcado
     * a medio plantel terminaría licenciando a todos sin enterarse. `null` es «no
     * venía de un envío por dirección», que es cuando corresponde marcar a todos.
     *
     * Solo del alcance por dirección: en «varios» los carnets de `cis` son otra
     * lista y no tienen nada que ver con el personal de una dirección.
     *
     * @var list<string>|null
     */
    $seleccionPrevia = $modoInicial === 'direccion'
        ? array_values(array_filter(array_map(
            fn ($ci): string => trim((string) $ci),
            (array) old('cis', []),
        )))
        : null;
@endphp

@section('contenido')
    <div class="cabecera">
        <div class="cabecera__titulo">
            <span class="cabecera__icono"><x-heroicon-o-clipboard-document-check /></span>
            <h1>Licenciar</h1>
        </div>
        <a href="{{ route('licencias.index') }}" class="btn btn--gris"><x-heroicon-o-arrow-left />Volver</a>
    </div>

    @if ($errorMamore)
        <div class="aviso aviso--error">{{ $errorMamore }}</div>
    @elseif ($ciDesconocido)
        <div class="aviso aviso--error">El CI {{ $ci }} no figura en la API de Mamoré.</div>
    @endif

    {{-- `multipart/form-data`: el formulario sube el respaldo de la licencia.
         Sin esto el archivo no viaja y la validación lo rechaza. --}}
    <form action="{{ route('licencias.store') }}" method="POST" enctype="multipart/form-data"
          x-data="{
              modo: @js($modoInicial),
              completo: {{ old('tCompleto', 1) ? 'true' : 'false' }},
              porTurno: {{ old('asignaciones') !== null ? 'true' : 'false' }},
              elegidos: @js($elegidos),
              {{-- El rango vive en Alpine además de en los inputs: el catálogo de
                   direcciones y el personal se piden para esas fechas. --}}
              desde: @js(old('desde', now()->toDateString())),
              hasta: @js(old('hasta', now()->toDateString())),
              {{-- Alcance «por dirección». --}}
              direccion: @js((string) old('direccion', '')),
              unidad: @js((string) old('unidad', '')),
              direcciones: [],
              unidades: [],
              cargandoDirecciones: false,
              errorDirecciones: '',
              funcionarios: [],
              cargandoFuncionarios: false,
              errorFuncionarios: '',
              {{-- Carnets marcados de la dirección. Arrancan todos: el alcance es
                   la dirección entera y desmarcar es la excepción. --}}
              seleccionados: [],
              seleccionPrevia: @js($seleccionPrevia),
              {{-- Permiso personal o licencia institucional. Sin elegir a mano,
                   sigue al alcance: para una persona suele ser un permiso que
                   pidió; para varios o una dirección, un feriado o una actividad. --}}
              tipo: @js(old('tipo', $modoInicial === 'uno' ? \App\Models\Licencia::TIPO_PERSONAL : \App\Models\Licencia::TIPO_INSTITUCIONAL)),
              tipoElegido: {{ old('tipo') !== null ? 'true' : 'false' }},
              {{-- Saldo de permisos por horas contra el tope mensual. --}}
              ci: @js($persona['ci'] ?? null),
              lEntra: @js((string) old('lEntra', '')),
              lSale: @js((string) old('lSale', '')),
              saldo: null,
              cargandoSaldo: false,
              errorSaldo: '',
              timerSaldo: null,
              pedidoSaldo: 0,
              init() {
                  {{-- Si el envío volvió por un error de validación con la dirección
                       ya elegida, se rearma sola: si no, el combo aparece vacío y
                       parece que se perdió la selección. --}}
                  if (this.modo === 'direccion') { this.cargarDirecciones(); }
                  {{-- Todo lo que cambia los días o las horas del permiso recalcula
                       el saldo. Los turnos elegidos a mano avisan desde su checkbox. --}}
                  {{-- El permiso personal es solo para un funcionario: varios o por
                       dirección son siempre licencia institucional. --}}
                  this.$watch('modo', (modo) => {
                      if (modo !== 'uno') { this.tipo = 'institucional'; }
                      else if (! this.tipoElegido) { this.tipo = 'personal'; }
                  });
                  {{-- El personal es siempre por horas; el institucional arranca en
                       turno completo y puede pasar a por horas. --}}
                  this.$watch('tipo', (tipo) => { this.completo = tipo !== 'personal'; });
                  if (this.modo !== 'uno') { this.tipo = 'institucional'; }
                  if (this.tipo === 'personal') { this.completo = false; }
                  ['modo', 'tipo', 'completo', 'desde', 'hasta', 'lEntra', 'lSale', 'porTurno']
                      .forEach((campo) => this.$watch(campo, () => this.consultarSaldo()));
                  this.consultarSaldo();
              },
              {{-- Pide el saldo al servidor, que resuelve los días igual que el alta
                   y calcula con el mismo servicio que bloquea al guardar. Con un
                   pequeño respiro para no pedir por cada tecla, y descartando las
                   respuestas viejas si llegan después de una nueva. --}}
              consultarSaldo() {
                  clearTimeout(this.timerSaldo);
                  if (this.modo !== 'uno' || this.tipo !== 'personal' || this.completo || ! this.ci || ! this.desde || ! this.hasta || this.hasta < this.desde) {
                      this.pedidoSaldo++;
                      this.saldo = null;
                      this.errorSaldo = '';
                      this.cargandoSaldo = false;
                      return;
                  }
                  this.timerSaldo = setTimeout(async () => {
                      const pedido = ++this.pedidoSaldo;
                      this.cargandoSaldo = true;
                      this.errorSaldo = '';
                      const params = new URLSearchParams({ ci: this.ci, desde: this.desde, hasta: this.hasta });
                      if (this.lEntra) { params.set('lEntra', this.lEntra); }
                      if (this.lSale) { params.set('lSale', this.lSale); }
                      if (this.porTurno) {
                          this.$root.querySelectorAll('input[name^=asignaciones]:checked')
                              .forEach((casilla) => params.append('asignaciones[]', casilla.value));
                      }
                      try {
                          const resp = await fetch(`{{ route('licencias.saldo') }}?${params}`, { headers: { 'Accept': 'application/json' } });
                          const cuerpo = await resp.json().catch(() => null);
                          if (pedido !== this.pedidoSaldo) { return; }
                          if (resp.ok && cuerpo) {
                              this.saldo = cuerpo;
                          } else {
                              this.saldo = null;
                              this.errorSaldo = (cuerpo && (cuerpo.error || cuerpo.message)) || 'No se pudo calcular el saldo de permisos.';
                          }
                      } catch (e) {
                          if (pedido === this.pedidoSaldo) {
                              this.saldo = null;
                              this.errorSaldo = 'No se pudo calcular el saldo de permisos.';
                          }
                      } finally {
                          if (pedido === this.pedidoSaldo) { this.cargandoSaldo = false; }
                      }
                  }, 350);
              },
              get excedeTope() {
                  return this.modo === 'uno' && this.tipo === 'personal' && ! this.completo && !! this.saldo && this.saldo.activo && this.saldo.excede;
              },
              get unidadesDeLaDireccion() {
                  if (! this.direccion) { return []; }
                  return this.unidades.filter((u) => String(u.direccionId) === String(this.direccion));
              },
              {{-- Los dos combos filtran sobre lo ya traído: el catálogo entero
                   viaja en un solo pedido, así que escribir no gasta cuota. --}}
              qDireccion: '',
              abiertoDireccion: false,
              qUnidad: '',
              abiertoUnidad: false,
              get direccionElegida() {
                  return this.direcciones.find((d) => String(d.id) === String(this.direccion)) || null;
              },
              get unidadElegida() {
                  return this.unidades.find((u) => String(u.id) === String(this.unidad)) || null;
              },
              get direccionesFiltradas() {
                  const texto = this.qDireccion.trim().toLowerCase();
                  return texto === ''
                      ? this.direcciones
                      : this.direcciones.filter((d) => d.texto.toLowerCase().includes(texto));
              },
              get unidadesFiltradas() {
                  const texto = this.qUnidad.trim().toLowerCase();
                  const base = this.unidadesDeLaDireccion;
                  return texto === '' ? base : base.filter((u) => u.texto.toLowerCase().includes(texto));
              },
              elegirDireccion(opcion) {
                  this.direccion = opcion ? String(opcion.id) : '';
                  this.qDireccion = '';
                  {{-- «Cambiar» deja la lista abierta: si no, el botón parece que
                       no hizo nada y hay que volver a clickear el campo. --}}
                  this.abiertoDireccion = opcion === null;
                  {{-- La unidad cuelga de la dirección: cambiarla deja la anterior
                       sin sentido. --}}
                  this.unidad = '';
                  this.qUnidad = '';
                  this.verFuncionarios();
              },
              elegirUnidad(opcion) {
                  this.unidad = opcion ? String(opcion.id) : '';
                  this.qUnidad = '';
                  this.abiertoUnidad = false;
                  this.verFuncionarios();
              },
              get todosMarcados() {
                  return this.funcionarios.length > 0 && this.seleccionados.length === this.funcionarios.length;
              },
              alternarTodos() {
                  this.seleccionados = this.todosMarcados ? [] : this.funcionarios.map((p) => p.ci);
              },
              async cargarDirecciones() {
                  this.cargandoDirecciones = true;
                  this.errorDirecciones = '';
                  try {
                      const params = new URLSearchParams({ desde: this.desde, hasta: this.hasta });
                      const resp = await fetch(`{{ route('licencias.direcciones') }}?${params}`, { headers: { 'Accept': 'application/json' } });
                      const cuerpo = await resp.json().catch(() => null);
                      if (resp.ok) {
                          this.direcciones = (cuerpo && cuerpo.direcciones) || [];
                          this.unidades = (cuerpo && cuerpo.unidades) || [];
                          if (this.direccion) { this.verFuncionarios(); }
                      } else {
                          this.direcciones = [];
                          this.unidades = [];
                          this.errorDirecciones = (cuerpo && cuerpo.error) || 'No se pudieron traer las direcciones.';
                      }
                  } catch (e) {
                      {{-- Se vacían igual que en el error con respuesta: un catálogo
                           a medias dejaría elegir una dirección que no se verificó. --}}
                      this.direcciones = [];
                      this.unidades = [];
                      this.errorDirecciones = 'No se pudieron traer las direcciones.';
                  } finally {
                      this.cargandoDirecciones = false;
                  }
              },
              async verFuncionarios() {
                  if (! this.direccion) { this.funcionarios = []; this.seleccionados = []; this.errorFuncionarios = ''; return; }
                  this.cargandoFuncionarios = true;
                  this.errorFuncionarios = '';
                  try {
                      const params = new URLSearchParams({
                          direccion: this.direccion,
                          desde: this.desde,
                          hasta: this.hasta,
                      });
                      if (this.unidad) { params.set('unidad', this.unidad); }
                      const resp = await fetch(`{{ route('licencias.direccion.funcionarios') }}?${params}`, { headers: { 'Accept': 'application/json' } });
                      const cuerpo = await resp.json().catch(() => null);
                      if (resp.ok) {
                          this.funcionarios = (cuerpo && cuerpo.funcionarios) || [];
                          if (this.seleccionPrevia !== null) {
                              {{-- Vuelve de un error de validación: se respeta lo que
                                   estaba marcado, cruzado contra quién sigue en la lista. --}}
                              const previa = this.seleccionPrevia;
                              this.seleccionados = this.funcionarios.map((p) => p.ci).filter((ci) => previa.includes(ci));
                              this.seleccionPrevia = null;
                          } else {
                              {{-- Cambió la lista: se marcan todos de nuevo, porque una
                                   selección de la dirección anterior ya no significa nada. --}}
                              this.seleccionados = this.funcionarios.map((p) => p.ci);
                          }
                      } else {
                          this.funcionarios = [];
                          this.seleccionados = [];
                          this.errorFuncionarios = (cuerpo && cuerpo.error) || 'No se pudo traer el personal.';
                      }
                  } catch (e) {
                      this.funcionarios = [];
                      this.seleccionados = [];
                      this.errorFuncionarios = 'No se pudo traer el personal.';
                  } finally {
                      this.cargandoFuncionarios = false;
                  }
              },
              {{-- El rango cambia a quién alcanza y con qué contrato, así que el
                   catálogo y la lista se vuelven a pedir. --}}
              rangoCambio() {
                  if (this.modo !== 'direccion') { return; }
                  this.cargarDirecciones();
              },
              q: '',
              abierto: false,
              cargando: false,
              resultados: [],
              errorApi: '',
              timer: null,
              buscar() {
                  clearTimeout(this.timer);
                  const texto = this.q.trim();
                  if (texto.length < 2) { this.resultados = []; this.errorApi = ''; this.abierto = false; return; }
                  this.timer = setTimeout(async () => {
                      this.cargando = true;
                      this.abierto = true;
                      this.errorApi = '';
                      try {
                          const resp = await fetch(`{{ route('licencias.funcionarios') }}?q=${encodeURIComponent(texto)}`, { headers: { 'Accept': 'application/json' } });
                          const cuerpo = await resp.json().catch(() => null);
                          if (resp.ok) {
                              this.resultados = cuerpo ?? [];
                          } else {
                              this.resultados = [];
                              this.errorApi = (cuerpo && cuerpo.error) || 'No se pudo consultar la API de Mamoré.';
                          }
                      } catch (e) {
                          this.resultados = [];
                          this.errorApi = 'No se pudo consultar la API de Mamoré.';
                      } finally {
                          this.cargando = false;
                      }
                  }, 300);
              },
              cerrar() { this.abierto = false; this.resultados = []; this.errorApi = ''; this.q = ''; },
              {{-- Un funcionario: se recarga con ?ci= para traer sus turnos del servidor. --}}
              elegirUno(item) { window.location = `{{ route('licencias.create') }}?ci=${encodeURIComponent(item.id)}`; },
              {{-- Varios: se agregan fichas del lado del cliente, sin recargar. --}}
              agregar(item) {
                  if (! this.elegidos.some((e) => e.id === item.id)) { this.elegidos.push(item); }
                  this.cerrar();
              },
              quitar(id) { this.elegidos = this.elegidos.filter((e) => e.id !== id); },
          }"
          x-on:click.outside="abierto = false">
        @csrf
        <input type="hidden" name="modo" :value="modo">

        {{-- Paso 1: a quiénes alcanza la licencia. --}}
        <div class="card card--padded">
            <h2 style="margin-top: 0;">¿A quién se licencia?</h2>

            <div class="toolbar" style="gap: 1.25rem; margin-bottom: .75rem;">
                <div class="campo check">
                    <input type="radio" id="modo-uno" value="uno" x-model="modo">
                    <label for="modo-uno" style="margin: 0;">Un funcionario</label>
                </div>
                <div class="campo check">
                    <input type="radio" id="modo-varios" value="varios" x-model="modo">
                    <label for="modo-varios" style="margin: 0;">Varios funcionarios</label>
                </div>
                <div class="campo check">
                    <input type="radio" id="modo-direccion" value="direccion" x-model="modo"
                           x-on:change="if (direcciones.length === 0) { cargarDirecciones(); }">
                    <label for="modo-direccion" style="margin: 0;">Por dirección o unidad</label>
                </div>
            </div>

            @error('modo') <div class="error">{{ $message }}</div> @enderror
            @error('ci') <div class="error">{{ $message }}</div> @enderror
            @error('cis') <div class="error">{{ $message }}</div> @enderror
            @error('direccion') <div class="error">{{ $message }}</div> @enderror
            @error('unidad') <div class="error">{{ $message }}</div> @enderror

            {{-- Combo compartido por los modos «uno» y «varios». --}}
            <div class="campo" style="position: relative;" x-show="modo !== 'direccion'" x-cloak>
                <label for="combo-funcionario">
                    <span x-show="modo === 'uno'">Empleado que requiere licencia</span>
                    <span x-show="modo === 'varios'" x-cloak>Agregar funcionarios a la lista</span>
                    <span class="req">*</span>
                </label>
                <input type="text" id="combo-funcionario" class="input" x-model="q"
                       x-on:input="buscar()"
                       placeholder="Escribí CI o nombre y elegí de la lista…" autocomplete="off">
                <p class="ayuda" style="margin-bottom: 0;">Los funcionarios se buscan en la API de Mamoré.</p>

                <div x-show="abierto" x-cloak
                     style="position: absolute; z-index: 20; top: 100%; left: 0; right: 0; margin-top: .2rem;
                            background: var(--card); border: 1px solid var(--border); border-radius: .4rem;
                            max-height: 16rem; overflow-y: auto; box-shadow: 0 6px 16px rgba(0,0,0,.12);">
                    <template x-if="cargando">
                        <div style="padding: .55rem .7rem; color: var(--muted);">Buscando en Mamoré…</div>
                    </template>
                    <template x-if="! cargando && errorApi">
                        <div style="padding: .55rem .7rem; color: var(--danger);" x-text="errorApi"></div>
                    </template>
                    <template x-if="! cargando && ! errorApi && resultados.length === 0">
                        <div style="padding: .55rem .7rem; color: var(--muted);">Sin resultados en Mamoré.</div>
                    </template>
                    <template x-for="item in resultados" :key="item.id">
                        <button type="button" x-on:click="modo === 'uno' ? elegirUno(item) : agregar(item)" x-text="item.texto"
                                style="display: block; width: 100%; text-align: left; padding: .5rem .7rem;
                                       background: none; border: 0; border-bottom: 1px solid var(--border);
                                       cursor: pointer; font: inherit;"
                                onmouseover="this.style.background='var(--bg)'" onmouseout="this.style.background='transparent'"></button>
                    </template>
                </div>
            </div>

            {{-- Modo «uno»: el funcionario ya cargado desde el servidor. --}}
            <div x-show="modo === 'uno'" x-cloak>
                @if ($persona)
                    <input type="hidden" name="ci" value="{{ $persona['ci'] }}" :disabled="modo !== 'uno'">
                    <dl class="datos grid-2" style="margin: 0;">
                        <div>
                            <dt>Funcionario</dt>
                            <dd>{{ $nombre }} · CI {{ $persona['ci'] }}</dd>
                        </div>
                        <div>
                            <dt>Cargo</dt>
                            <dd>
                                {{ $persona['cargo'] ?: '—' }}
                                @if (!$persona['cargo'])
                                    <span class="pill pill--no">Sin contrato</span>
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt>Dirección administrativa</dt>
                            <dd>{{ $persona['direccion'] ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt>Profesión</dt>
                            <dd>{{ $persona['profesion'] ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt>Fecha de nacimiento</dt>
                            <dd>{{ $persona['nacimiento'] ?: '—' }}{{ is_null($persona['edad']) ? '' : ' · '.$persona['edad'].' años' }}</dd>
                        </div>
                        <div>
                            <dt>PIN reloj</dt>
                            <dd>{{ $persona['pinReloj'] ?: 'Sin PIN' }}</dd>
                        </div>
                    </dl>
                @else
                    <p class="vacio" style="margin: 0; padding: 1rem;">Elegí un funcionario para ver sus turnos asignados.</p>
                @endif
            </div>

            {{-- Modo «varios»: fichas de los elegidos. --}}
            <div x-show="modo === 'varios'" x-cloak>
                <template x-if="elegidos.length === 0">
                    <p class="vacio" style="margin: 0; padding: 1rem;">Todavía no agregaste ningún funcionario.</p>
                </template>
                <div style="display: flex; flex-wrap: wrap; gap: .4rem;">
                    <template x-for="elegido in elegidos" :key="elegido.id">
                        <span style="display: inline-flex; align-items: center; gap: .4rem; padding: .3rem .6rem;
                                     background: var(--bg); border: 1px solid var(--border); border-radius: 9999px; font-size: .78rem;">
                            <input type="hidden" name="cis[]" :value="elegido.id" :disabled="modo !== 'varios'">
                            <span x-text="elegido.texto"></span>
                            <button type="button" x-on:click="quitar(elegido.id)" aria-label="Quitar"
                                    style="border: 0; background: none; cursor: pointer; color: var(--danger); font: inherit; line-height: 1;">&times;</button>
                        </span>
                    </template>
                </div>
                <p class="ayuda" x-show="elegidos.length > 0" x-cloak>
                    <span x-text="elegidos.length"></span> funcionario(s) en la lista.
                </p>
            </div>

            {{-- Modo «dirección»: se elige la dirección —y opcionalmente una de sus
                 unidades— y se licencia a su personal con contrato firmado. --}}
            <div x-show="modo === 'direccion'" x-cloak>
                {{-- Deshabilitados fuera de este alcance: así no viajan al anotar
                     por funcionario y el servidor no recibe campos de dos modos. --}}
                <input type="hidden" name="direccion" :value="direccion" :disabled="modo !== 'direccion'">
                <input type="hidden" name="unidad" :value="unidad" :disabled="modo !== 'direccion'">

                <div class="toolbar" style="align-items: flex-start;">
                    {{-- Combo tipo select2: se escribe y se elige de la lista. Son
                         setenta direcciones largas, así que un <select> obligaba a
                         recorrerlas de a una para encontrar la que se busca. --}}
                    <div class="campo" style="flex: 1; min-width: 16rem; position: relative;"
                         x-on:click.outside="abiertoDireccion = false">
                        <label for="combo-direccion">Dirección administrativa <span class="req">*</span></label>
                        <input type="text" id="combo-direccion" class="input" x-model="qDireccion"
                               x-on:focus="abiertoDireccion = true" x-on:input="abiertoDireccion = true"
                               autocomplete="off"
                               :placeholder="direccionElegida ? direccionElegida.texto : 'Escribí para buscar la dirección…'">

                        <div x-show="abiertoDireccion" x-cloak
                             style="position: absolute; z-index: 20; top: 100%; left: 0; right: 0; margin-top: .2rem;
                                    background: var(--card); border: 1px solid var(--border); border-radius: .4rem;
                                    max-height: 16rem; overflow-y: auto; box-shadow: 0 6px 16px rgba(0,0,0,.12);">
                            <template x-if="cargandoDirecciones">
                                <div style="padding: .55rem .7rem; color: var(--muted);">Trayendo las direcciones…</div>
                            </template>
                            <template x-if="! cargandoDirecciones && direccionesFiltradas.length === 0">
                                <div style="padding: .55rem .7rem; color: var(--muted);">Sin direcciones que coincidan.</div>
                            </template>
                            <template x-for="opcion in direccionesFiltradas" :key="opcion.id">
                                <button type="button" x-on:click="elegirDireccion(opcion)"
                                        x-text="`${opcion.texto} (${opcion.funcionarios})`"
                                        style="display: block; width: 100%; text-align: left; padding: .5rem .7rem;
                                               background: none; border: 0; border-bottom: 1px solid var(--border);
                                               cursor: pointer; font: inherit;"
                                        onmouseover="this.style.background='var(--bg)'" onmouseout="this.style.background='transparent'"></button>
                            </template>
                        </div>

                        <small x-show="direccionElegida" x-cloak style="color: var(--verde); display: block; margin-top: .3rem;">
                            ✔ <span x-text="direccionElegida ? direccionElegida.texto : ''"></span>
                            <button type="button" x-on:click="elegirDireccion(null)"
                                    style="border: 0; background: none; cursor: pointer; color: var(--danger); font: inherit;">
                                cambiar
                            </button>
                        </small>
                    </div>

                    <div class="campo" style="flex: 1; min-width: 16rem; position: relative;"
                         x-on:click.outside="abiertoUnidad = false">
                        <label for="combo-unidad">Unidad</label>
                        <input type="text" id="combo-unidad" class="input" x-model="qUnidad"
                               x-on:focus="abiertoUnidad = true" x-on:input="abiertoUnidad = true"
                               autocomplete="off" :disabled="! direccion"
                               :placeholder="unidadElegida ? unidadElegida.texto : 'Todas las unidades de la dirección'">

                        <div x-show="abiertoUnidad" x-cloak
                             style="position: absolute; z-index: 20; top: 100%; left: 0; right: 0; margin-top: .2rem;
                                    background: var(--card); border: 1px solid var(--border); border-radius: .4rem;
                                    max-height: 16rem; overflow-y: auto; box-shadow: 0 6px 16px rgba(0,0,0,.12);">
                            {{-- Volver a la dirección entera, que es el caso normal. --}}
                            <button type="button" x-on:click="elegirUnidad(null)"
                                    style="display: block; width: 100%; text-align: left; padding: .5rem .7rem;
                                           background: none; border: 0; border-bottom: 1px solid var(--border);
                                           cursor: pointer; font: inherit; color: var(--muted);">
                                Todas las unidades de la dirección
                            </button>
                            <template x-if="unidadesFiltradas.length === 0">
                                <div style="padding: .55rem .7rem; color: var(--muted);"
                                     x-text="qUnidad.trim() === ''
                                         ? 'Esta dirección no tiene unidades cargadas.'
                                         : 'Sin unidades que coincidan.'"></div>
                            </template>
                            <template x-for="opcion in unidadesFiltradas" :key="opcion.id">
                                <button type="button" x-on:click="elegirUnidad(opcion)"
                                        x-text="`${opcion.texto} (${opcion.funcionarios})`"
                                        style="display: block; width: 100%; text-align: left; padding: .5rem .7rem;
                                               background: none; border: 0; border-bottom: 1px solid var(--border);
                                               cursor: pointer; font: inherit;"
                                        onmouseover="this.style.background='var(--bg)'" onmouseout="this.style.background='transparent'"></button>
                            </template>
                        </div>

                        <small x-show="unidadElegida" x-cloak style="color: var(--verde); display: block; margin-top: .3rem;">
                            ✔ <span x-text="unidadElegida ? unidadElegida.texto : ''"></span>
                            <button type="button" x-on:click="elegirUnidad(null)"
                                    style="border: 0; background: none; cursor: pointer; color: var(--danger); font: inherit;">
                                quitar
                            </button>
                        </small>
                    </div>
                </div>

                <div x-show="cargandoDirecciones" x-cloak class="ayuda">Trayendo las direcciones de Mamoré…</div>
                <div x-show="errorDirecciones" x-cloak class="aviso aviso--error" x-text="errorDirecciones"></div>

                <p class="ayuda">
                    El conteo de cada opción es la gente que tuvo contrato en el rango elegido,
                    así que cambia si cambiás las fechas. Se licencia solo a quien además tenga
                    turno asignado en esos días, según su propio horario.
                </p>

                {{-- Quiénes van a quedar licenciados. El alcance anota sin elegir
                     uno por uno, así que se muestra la lista y no solo un número. --}}
                <div x-show="direccion" x-cloak style="margin-top: .5rem;">
                    <div x-show="cargandoFuncionarios" class="ayuda">Trayendo el personal con contrato…</div>
                    <div x-show="errorFuncionarios" class="aviso aviso--error" x-text="errorFuncionarios"></div>

                    <template x-if="! cargandoFuncionarios && ! errorFuncionarios && funcionarios.length === 0">
                        <div class="aviso aviso--error">
                            Ningún funcionario de esa dirección tiene contrato firmado dentro del rango.
                        </div>
                    </template>

                    <div x-show="! cargandoFuncionarios && funcionarios.length > 0">
                        <div class="toolbar" style="margin-bottom: .35rem; align-items: center;">
                            <p class="ayuda" style="margin: 0;">
                                <strong><span x-text="seleccionados.length"></span>
                                de <span x-text="funcionarios.length"></span> funcionario(s)</strong>
                                con contrato firmado quedarán alcanzados.
                            </p>
                            <button type="button" class="btn btn--gris" style="padding: .3rem .55rem;"
                                    x-on:click="alternarTodos()"
                                    x-text="todosMarcados ? 'Desmarcar todos' : 'Marcar todos'"></button>
                        </div>

                        <div x-show="seleccionados.length === 0" x-cloak class="aviso aviso--error">
                            No queda nadie marcado: elegí al menos un funcionario.
                        </div>

                        {{-- Alto en proporción a la pantalla y no fijo: una dirección
                             trae decenas de personas y hay que poder revisarlas sin
                             arrastrar el cuadro de a siete filas. El tope en rem evita
                             que en un monitor grande la tabla empuje al resto del
                             formulario fuera de la vista. --}}
                        <div style="max-height: min(65vh, 40rem); overflow-y: auto;
                                    border: 1px solid var(--border); border-radius: .4rem;">
                            <table style="margin: 0;">
                                {{-- El encabezado se queda a la vista al desplazar: con
                                     el cuadro alto, sin esto se pierde qué columna es cuál. --}}
                                <thead style="position: sticky; top: 0; z-index: 1; background: var(--card);">
                                    <tr>
                                        <th style="width: 2.5rem;">
                                            <input type="checkbox" :checked="todosMarcados"
                                                   x-on:change="alternarTodos()"
                                                   aria-label="Marcar o desmarcar todos">
                                        </th>
                                        <th>CI</th>
                                        <th>Funcionario</th>
                                        <th>Cargo</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="persona in funcionarios" :key="persona.ci">
                                        <tr>
                                            <td>
                                                {{-- El `value` es el carnet, así que lo marcado viaja como
                                                     `cis[]`. El servidor lo cruza contra el personal real de
                                                     la dirección: la selección acota, nunca amplía. --}}
                                                <input type="checkbox" name="cis[]" :value="persona.ci"
                                                       x-model="seleccionados" :disabled="modo !== 'direccion'"
                                                       :aria-label="`Licenciar a ${persona.nombre}`">
                                            </td>
                                            <td x-text="persona.ci"></td>
                                            <td x-text="persona.nombre"></td>
                                            <td x-text="persona.cargo"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Paso 2: turnos del funcionario (solo cuando hay uno cargado). --}}
        @if ($persona)
            <div class="card card--padded" style="margin-top: 1rem;" x-show="modo === 'uno'" x-cloak>
                <div class="cabecera" style="margin: 0 0 .75rem;">
                    <h2 style="margin: 0;">
                        Turnos {{ $incluirVencidos ? '' : 'vigentes' }} de {{ $nombre ?: 'el funcionario' }}
                    </h2>
                    @if ($incluirVencidos)
                        <a class="btn btn--gris" href="{{ route('licencias.create', ['ci' => $ci]) }}">
                            <x-heroicon-o-funnel />Ver solo los vigentes
                        </a>
                    @elseif ($vencidos > 0)
                        <a class="btn btn--gris" href="{{ route('licencias.create', ['ci' => $ci, 'vencidos' => 1]) }}">
                            <x-heroicon-o-clock />Ver también los {{ $vencidos }} vencido(s)
                        </a>
                    @endif
                </div>

                @if ($asignaciones->isEmpty())
                    <div class="aviso aviso--error">
                        @if ($vencidos > 0 && ! $incluirVencidos)
                            El funcionario no tiene turnos vigentes; sí tiene {{ $vencidos }} vencido(s).
                            <a href="{{ route('licencias.create', ['ci' => $ci, 'vencidos' => 1]) }}">Mostrarlos</a>
                            para anotar una licencia retroactiva.
                        @else
                            El funcionario no tiene turnos asignados: no se le puede anotar una licencia.
                        @endif
                    </div>
                @else
                    <p class="ayuda" style="margin-top: 0;">
                        El rango de fechas define qué días se licencian: se anota una licencia por cada
                        día del rango en que el funcionario tenga turno. No hace falta marcar nada.
                    </p>

                    @error('asignaciones') <div class="error">{{ $message }}</div> @enderror

                    <table>
                        <thead>
                            <tr>
                                <th style="width: 2.5rem;" x-show="porTurno" x-cloak></th>
                                <th>Día</th>
                                <th>Turno</th>
                                <th>Entrada</th>
                                <th>Salida</th>
                                <th>Día siguiente</th>
                                <th>Vigencia</th>
                                <th>Estado</th>
                                <th>Horas trabajadas</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($asignaciones as $asignacion)
                                @php
                                    $turno = $asignacion->turno;
                                    $vencida = (bool) $asignacion->hasta?->isPast();
                                    $futura = (bool) $asignacion->desde?->isFuture();
                                    // Sin envío previo van todos marcados: al abrir la
                                    // selección manual arranca igual que el automático.
                                    $marcada = old('asignaciones') === null
                                        || in_array((string) $asignacion->id, (array) old('asignaciones', []), true);
                                @endphp
                                <tr @class(['fila--inactiva' => $vencida])>
                                    <td x-show="porTurno" x-cloak>
                                        {{-- Deshabilitado fuera del modo manual: así no viaja ninguna
                                             asignación y el servidor resuelve los turnos por el rango. --}}
                                        <input type="checkbox" name="asignaciones[]" value="{{ $asignacion->id }}"
                                               @checked($marcada) :disabled="! porTurno || modo !== 'uno'"
                                               x-on:change="consultarSaldo()"
                                               aria-label="Elegir turno {{ $turno->nombreTurno }}">
                                    </td>
                                    <td>{{ $abreviar((int) $turno->dia) }}</td>
                                    <td>{{ $turno->nombreTurno }}</td>
                                    <td>{{ $turno->hEntrada?->format('H:i') ?? '—' }}</td>
                                    <td>{{ $turno->hSalida?->format('H:i') ?? '—' }}</td>
                                    <td>{{ $turno->siguienteDia ? 'Sí' : '' }}</td>
                                    <td>
                                        {{ $asignacion->desde?->format('d/m/Y') ?? '—' }} al
                                        {{ $asignacion->hasta?->format('d/m/Y') ?? '—' }}
                                    </td>
                                    <td>
                                        @if ($vencida)
                                            <span class="pill pill--no">Vencido</span>
                                        @elseif ($futura)
                                            <span class="pill pill--advertencia">Futuro</span>
                                        @else
                                            <span class="pill pill--ok">Vigente</span>
                                        @endif
                                    </td>
                                    <td>{{ rtrim(rtrim(number_format((float) $turno->hTrabajadas, 2, '.', ''), '0'), '.') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <div class="campo check" style="margin: .75rem 0 0;">
                        <input type="checkbox" id="porTurno" x-model="porTurno">
                        <label for="porTurno" style="margin: 0;">
                            Licenciar solo algunos turnos
                            <span class="ayuda" style="font-weight: 400;">
                                (para quien tiene doble turno en un día y falta solo a uno de ellos)
                            </span>
                        </label>
                    </div>
                @endif
            </div>
        @endif

        {{-- Paso 3: tipo, rango, alcance del día y motivo. --}}
        <div class="card card--padded" style="margin-top: 1rem;">
            {{-- Qué es. Solo el permiso personal cuenta contra el tope mensual. --}}
            <div class="campo">
                <label>Tipo <span class="req">*</span></label>
                {{-- Para varios o una dirección el tipo es fijo: licencia
                     institucional. El radio queda oculto pero marcado, y viaja. --}}
                <p x-show="modo !== 'uno'" x-cloak style="margin: .25rem 0;">
                    <span class="pill pill--ok">Licencia institucional</span>
                </p>
                <div class="toolbar" style="gap: 1.25rem;" x-show="modo === 'uno'">
                    @foreach (\App\Models\Licencia::TIPOS as $valor => $etiqueta)
                        <div class="campo check" style="margin: 0;">
                            <input type="radio" id="tipo-{{ $valor }}" name="tipo" value="{{ $valor }}"
                                   x-model="tipo" x-on:change="tipoElegido = true">
                            <label for="tipo-{{ $valor }}" style="margin: 0;">{{ $etiqueta }}</label>
                        </div>
                    @endforeach
                </div>
                <p class="ayuda" style="margin-bottom: 0;">
                    <span x-show="tipo === 'personal'">Lo pide el funcionario para su uso (trámite, médico, asunto familiar). Es siempre por horas y cuenta contra el tope mensual de permisos.</span>
                    <span x-show="tipo === 'institucional'" x-cloak>La dispone la institución (feriado, actividad, capacitación, comisión). Puede ser de turno completo o por horas, y no cuenta contra el tope.</span>
                    <span x-show="modo !== 'uno'" x-cloak><br>Para varios funcionarios o una dirección se anota siempre licencia institucional.</span>
                </p>
                @error('tipo') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="toolbar" style="align-items: flex-end;">
                <div class="campo">
                    <label for="desde">Desde <span class="req">*</span></label>
                    {{-- El rango decide quién tiene contrato en el alcance por
                         dirección, así que al cambiarlo se vuelve a pedir. --}}
                    {{-- El `value` queda además del `x-model`: si Alpine no llegara a
                         correr, el campo igual sale con su fecha y no vacío. --}}
                    <input type="date" id="desde" name="desde" class="input"
                           value="{{ old('desde', now()->toDateString()) }}"
                           x-model="desde" x-on:change="rangoCambio()" required>
                    @error('desde') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="campo">
                    <label for="hasta">Hasta <span class="req">*</span></label>
                    <input type="date" id="hasta" name="hasta" class="input"
                           value="{{ old('hasta', now()->toDateString()) }}"
                           x-model="hasta" x-on:change="rangoCambio()" required>
                    @error('hasta') <div class="error">{{ $message }}</div> @enderror
                </div>
            </div>

            {{-- Alcance. El permiso personal es siempre por horas; la licencia
                 institucional es de turno completo o por horas. Lo valida también
                 `StoreLicenciaRequest`. --}}
            <input type="hidden" name="tCompleto" :value="completo ? 1 : 0">
            <div class="campo">
                <label>Alcance <span class="req">*</span></label>
                {{-- El alcance y los haberes van en la misma fila: son las dos
                     casillas del permiso. Sin tildar «Turno completo», es por horas. --}}
                <div class="toolbar" style="gap: 1.5rem; align-items: center;">
                    <div class="campo check" style="margin: 0;" x-show="tipo !== 'personal'" x-cloak>
                        <input type="checkbox" id="alcance-completo" x-model="completo">
                        <label for="alcance-completo" style="margin: 0;">Turno completo</label>
                    </div>
                    <span class="pill pill--info" x-show="tipo === 'personal'">Por horas</span>
                    <div class="campo check" style="margin: 0;">
                        <input type="checkbox" id="goceHaberes" name="goceHaberes" value="1" @checked(old('goceHaberes', 1))>
                        <label for="goceHaberes" style="margin: 0;">Con goce de haberes</label>
                    </div>
                </div>
                <p class="ayuda" style="margin: .35rem 0 0;" x-show="tipo === 'personal'">
                    El permiso personal no puede ser de turno completo y cuenta contra el tope mensual de permisos.
                </p>
                <p class="ayuda" style="margin: .35rem 0 0;" x-show="tipo !== 'personal'" x-cloak>
                    Si no marcará en todo el día, dejá «Turno completo». Si llegará tarde o se irá
                    temprano, destildalo y cargá desde qué hora hasta qué hora.
                </p>
                @error('tCompleto') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="toolbar" style="align-items: flex-end;" x-show="! completo" x-cloak>
                <div class="campo">
                    <label for="lEntra">Desde las <span class="req">*</span></label>
                    <input type="time" id="lEntra" name="lEntra" class="input"
                           value="{{ old('lEntra') }}" x-model="lEntra" :disabled="completo" :required="! completo">
                    @error('lEntra') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="campo">
                    <label for="lSale">Hasta las <span class="req">*</span></label>
                    <input type="time" id="lSale" name="lSale" class="input"
                           value="{{ old('lSale') }}" x-model="lSale" :disabled="completo" :required="! completo">
                    @error('lSale') <div class="error">{{ $message }}</div> @enderror
                </div>
            </div>

            {{-- Motivo y respaldo en dos columnas, alineados arriba. --}}
            <div class="grid-2" style="align-items: start;">
                <div class="campo">
                    <label for="motivo">Motivo de la ausencia <span class="req">*</span></label>
                    <input type="text" id="motivo" name="motivo" class="input" maxlength="255"
                           value="{{ old('motivo') }}" required>
                    @error('motivo') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="campo">
                    <label for="respaldo">Respaldo</label>
                    <input type="file" id="respaldo" name="respaldo" class="input"
                           accept=".jpg,.jpeg,.png,.pdf">
                    <p class="ayuda">
                        Opcional. Certificado, memorándum o nota que justifica la
                        ausencia. Imagen (JPG o PNG) o PDF, hasta 5 MB.
                    </p>
                    @error('respaldo') <div class="error">{{ $message }}</div> @enderror
                </div>
            </div>

            {{-- Saldo de permisos por horas contra el tope mensual (Configuración).
                 Solo con un funcionario y sin turno completo: en «varios» y «por
                 dirección» no entra un recuadro por persona, y el control queda al
                 guardar. Se recalcula al cambiar fechas, horas o turnos. --}}
            @if ($persona)
                <div x-show="modo === 'uno' && tipo === 'personal' && ! completo && (cargandoSaldo || errorSaldo || (saldo && saldo.activo))" x-cloak
                     style="margin: .75rem 0; border: 1px solid var(--border); border-radius: .4rem; padding: .6rem .75rem;"
                     :style="{ borderColor: excedeTope ? 'var(--danger)' : 'var(--border)' }">
                    <div style="display: flex; justify-content: space-between; align-items: baseline; gap: .5rem; flex-wrap: wrap; margin-bottom: .4rem;">
                        <strong>Permisos por horas de {{ $nombre ?: 'el funcionario' }}</strong>
                        <span class="ayuda" style="margin: 0;" x-show="saldo && saldo.activo">
                            Tope <span x-text="saldo ? saldo.tope : ''"></span>
                            <span x-text="saldo ? saldo.alcance : ''"></span>
                        </span>
                    </div>

                    <div x-show="cargandoSaldo" class="ayuda">Calculando el saldo…</div>
                    <div x-show="errorSaldo && ! cargandoSaldo" class="aviso aviso--error" style="margin: 0;" x-text="errorSaldo"></div>

                    <template x-if="saldo && saldo.activo && ! cargandoSaldo">
                        <div>
                            <table style="margin: 0;">
                                <thead>
                                    <tr>
                                        <th></th>
                                        <th>Usado</th>
                                        <th>Este permiso</th>
                                        <th>Queda</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="bolsa in saldo.bolsas" :key="bolsa.titulo">
                                        <tr :style="bolsa.excede ? { color: 'var(--danger)', fontWeight: 600 } : {}">
                                            <td x-text="bolsa.titulo"></td>
                                            <td x-text="bolsa.usado"></td>
                                            <td x-text="bolsa.pedido ? `+${bolsa.pedido}` : '—'"></td>
                                            <td x-text="bolsa.excede ? 'Se pasa del tope' : bolsa.queda"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>

                            <div x-show="saldo.excede" class="aviso aviso--error" style="margin: .5rem 0 0;">
                                Este permiso supera el tope mensual: acortá las horas o el rango para poder anotarlo.
                            </div>
                            <p x-show="! saldo.excede && ! (lEntra && lSale)" class="ayuda" style="margin: .4rem 0 0;">
                                Cargá la hora de entrada y la de salida para ver cuánto suma este permiso.
                            </p>
                            <p x-show="! saldo.excede && lEntra && lSale && saldo.dias === 0" class="ayuda" style="margin: .4rem 0 0;">
                                En el rango no hay días para licenciar: no tiene turno o ya tiene licencia esos días.
                            </p>
                        </div>
                    </template>
                </div>
            @endif

            <div class="form-acciones">
                <button type="submit" class="btn"
                        :disabled="(modo === 'uno' && ! {{ $persona ? 'true' : 'false' }})
                                   || (modo === 'varios' && elegidos.length === 0)
                                   || (modo === 'direccion' && (! direccion || seleccionados.length === 0))
                                   || excedeTope"
                        :title="excedeTope ? 'Supera el tope mensual de permisos' : ''">
                    <x-heroicon-o-check />Anotar licencia(s)
                </button>
                <a href="{{ route('licencias.index') }}" class="btn btn--gris"><x-heroicon-o-x-mark />Cancelar</a>
            </div>
        </div>
    </form>
@endsection
