@extends('layouts.app')

@section('titulo', 'Licenciar')

@php
    /** Abreviatura del día de la semana, como la grilla del sistema de escritorio. */
    $abreviar = fn (?int $dia): string => mb_strtoupper(mb_substr(\App\Models\Horario::DIAS[$dia] ?? '—', 0, 3));
    $nombre = $persona['nombre'] ?? '';
    $modoInicial = old('modo', 'uno');

    /**
     * Quiénes estaban marcados cuando el envío volvió por un error de validación.
     *
     * Sin esto la lista se remarca entera al recargarse, y quien había desmarcado
     * a medio plantel terminaría licenciando a todos sin enterarse. `null` es «no
     * venía de un envío por dirección», que es cuando corresponde marcar a todos.
     *
     * Solo de los alcances con lista para marcar (por dirección y todos): en
     * «varios» los carnets de `cis` son otra lista y no tienen nada que ver.
     *
     * @var list<string>|null
     */
    $seleccionPrevia = in_array($modoInicial, ['direccion', 'todos'], true)
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
              porHorario: {{ old('asignaciones') !== null ? 'true' : 'false' }},
              elegidos: @js($elegidos),
              {{-- Las fechas van primero: deciden a quién se puede licenciar. El
                   buscador, la lista de la dirección y la revisión de los ya
                   elegidos traen solo a quien tuvo contrato esos días. --}}
              desde: @js(old('desde', $desde)),
              hasta: @js(old('hasta', $hasta)),
              hoy: @js(now()->toDateString()),
              manana: @js(now()->addDay()->toDateString()),
              cuando: 'hoy',
              {{-- Plazo para registrar licencias de hoy (null sin plazo): los
                   calendarios no dejan elegir los días de afuera. Quien tiene
                   «Fuera de plazo» lo libera marcando la casilla. --}}
              plazo: @js(app(\App\Services\PlazoLicencias::class)->paraApi()),
              {{-- La sugerencia de la casilla solo viaja a quien la ve. --}}
              sugerenciaPlazo: @js(auth()->user()?->can('Override:Licencia') ? ' Si corresponde igual, marcá «Registrar fuera de plazo».' : ''),
              fueraDePlazo: {{ old('fueraDePlazo') ? 'true' : 'false' }},
              {{-- Contrato de cada elegido en las fechas: texto de la vigencia, o
                   `null` si no tuvo. Una cédula ausente es «todavía no se sabe». --}}
              contratos: {},
              revisandoContratos: false,
              errorContratos: '',
              pedidoContratos: 0,
              timerFechas: null,
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
                  this.cuando = this.desde === this.hasta
                      ? (this.desde === this.hoy ? 'hoy' : (this.desde === this.manana ? 'manana' : 'dia'))
                      : 'rango';
                  {{-- Con «Hoy», «Mañana» o «Un día» el rango es de un solo día. --}}
                  this.$watch('desde', (desde) => { if (this.cuando !== 'rango') { this.hasta = desde; } });
                  this.$watch('desde', () => this.fechasCambiaron());
                  this.$watch('hasta', () => this.fechasCambiaron());
                  this.$watch('modo', () => this.revisarContratos());
                  this.revisarContratos();
                  {{-- Si el envío volvió por un error de validación con la dirección
                       ya elegida, se rearma sola: si no, el combo aparece vacío y
                       parece que se perdió la selección. --}}
                  if (this.modo === 'direccion') { this.cargarDirecciones(); }
                  if (this.modo === 'todos') { this.verFuncionarios(); }
                  {{-- La lista la comparten «por dirección» y «todos»: al pasar de
                       uno a otro se vuelve a pedir la del alcance nuevo. --}}
                  this.$watch('modo', (modo, anterior) => {
                      if (modo === 'todos' || (anterior === 'todos' && modo === 'direccion')) { this.verFuncionarios(); }
                  });
                  {{-- Todo lo que cambia los días o las horas del permiso recalcula
                       el saldo. Los horarios elegidos a mano avisan desde su checkbox. --}}
                  {{-- El permiso personal es solo para un funcionario: varios o por
                       dirección son siempre licencia institucional. --}}
                  this.$watch('modo', (modo) => {
                      if (modo !== 'uno') { this.tipo = 'institucional'; }
                      else if (! this.tipoElegido) { this.tipo = 'personal'; }
                  });
                  {{-- El personal es siempre por horas; el institucional arranca en
                       horario completo y puede pasar a por horas. --}}
                  this.$watch('tipo', (tipo) => { this.completo = tipo !== 'personal' && this.modo !== 'todos'; });
                  {{-- Para todos es siempre por horas: el día completo es un día excepcional. --}}
                  this.$watch('modo', (modo, anterior) => {
                      if (modo === 'todos') { this.completo = false; }
                      else if (anterior === 'todos' && this.tipo !== 'personal') { this.completo = true; }
                  });
                  if (this.modo !== 'uno') { this.tipo = 'institucional'; }
                  if (this.tipo === 'personal' || this.modo === 'todos') { this.completo = false; }
                  ['modo', 'tipo', 'completo', 'desde', 'hasta', 'lEntra', 'lSale', 'porHorario']
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
                      if (this.porHorario) {
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
              {{-- Sin turno esos días no hay jornada que licenciar: no se puede marcar. --}}
              get licenciables() {
                  return this.funcionarios.filter((p) => ! p.sinTurno);
              },
              {{-- Género elegido en la lista: '' es todos. A diferencia del
                   buscador, acota a quién se licencia (el día de la madre, por
                   ejemplo): lo del otro género se desmarca y se oculta. --}}
              filtroGenero: '',
              get marcables() {
                  return this.licenciables.filter((p) => this.filtroGenero === '' || p.genero === this.filtroGenero);
              },
              get todosMarcados() {
                  return this.marcables.length > 0 && this.seleccionados.length === this.marcables.length;
              },
              alternarTodos() {
                  this.seleccionados = this.todosMarcados ? [] : this.marcables.map((p) => p.ci);
              },
              elegirGenero() {
                  this.seleccionados = this.marcables.map((p) => p.ci);
              },
              filtroLista: '',
              coincideConFiltro(persona) {
                  if (this.filtroGenero !== '' && persona.genero !== this.filtroGenero) { return false; }
                  const texto = this.filtroLista.trim().toLowerCase();
                  return texto === '' || persona.ci.toLowerCase().includes(texto) || persona.nombre.toLowerCase().includes(texto);
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
              pedidoFuncionarios: 0,
              async verFuncionarios() {
                  const todos = this.modo === 'todos';
                  const pedido = ++this.pedidoFuncionarios;
                  this.filtroLista = '';
                  if (! todos && ! this.direccion) { this.funcionarios = []; this.seleccionados = []; this.errorFuncionarios = ''; this.cargandoFuncionarios = false; return; }
                  this.funcionarios = [];
                  this.cargandoFuncionarios = true;
                  this.errorFuncionarios = '';
                  try {
                      const params = new URLSearchParams({ desde: this.desde, hasta: this.hasta });
                      if (! todos) { params.set('direccion', this.direccion); }
                      if (! todos && this.unidad) { params.set('unidad', this.unidad); }
                      const ruta = todos ? `{{ route('licencias.todos.funcionarios') }}` : `{{ route('licencias.direccion.funcionarios') }}`;
                      const resp = await fetch(`${ruta}?${params}`, { headers: { 'Accept': 'application/json' } });
                      const cuerpo = await resp.json().catch(() => null);
                      {{-- Cambiar de alcance o de fechas con un pedido en vuelo: la
                           respuesta vieja se descarta, si no pisaría la lista nueva. --}}
                      if (pedido !== this.pedidoFuncionarios) { return; }
                      if (resp.ok) {
                          this.funcionarios = (cuerpo && cuerpo.funcionarios) || [];
                          if (this.seleccionPrevia !== null) {
                              {{-- Vuelve de un error de validación: se respeta lo que
                                   estaba marcado, cruzado contra quién sigue en la lista. --}}
                              const previa = this.seleccionPrevia;
                              this.seleccionados = this.marcables.map((p) => p.ci).filter((ci) => previa.includes(ci));
                              this.seleccionPrevia = null;
                          } else {
                              {{-- Cambió la lista: se marcan todos de nuevo, porque una
                                   selección de la dirección anterior ya no significa nada. --}}
                              this.seleccionados = this.marcables.map((p) => p.ci);
                          }
                      } else {
                          this.funcionarios = [];
                          this.seleccionados = [];
                          this.errorFuncionarios = (cuerpo && cuerpo.error) || 'No se pudo traer el personal.';
                      }
                  } catch (e) {
                      if (pedido !== this.pedidoFuncionarios) { return; }
                      this.funcionarios = [];
                      this.seleccionados = [];
                      this.errorFuncionarios = 'No se pudo traer el personal.';
                  } finally {
                      if (pedido === this.pedidoFuncionarios) { this.cargandoFuncionarios = false; }
                  }
              },
              elegirCuando(opcion) {
                  this.cuando = opcion;
                  if (opcion === 'hoy') { this.desde = this.hoy; this.hasta = this.hoy; }
                  if (opcion === 'manana') { this.desde = this.manana; this.hasta = this.manana; }
                  if (opcion === 'dia') { this.hasta = this.desde; }
              },
              get primeraFecha() {
                  return this.fueraDePlazo ? null : (this.plazo?.desde ?? null);
              },
              get ultimaFecha() {
                  return this.fueraDePlazo ? null : (this.plazo?.hasta ?? null);
              },
              dentroDelPlazo(dia) {
                  return (! this.primeraFecha || dia >= this.primeraFecha) && (! this.ultimaFecha || dia <= this.ultimaFecha);
              },
              {{-- Por qué las fechas no entran en el plazo; vacío si entran. --}}
              get motivoFueraDePlazo() {
                  let motivo = '';
                  if (this.primeraFecha && this.desde && this.desde < this.primeraFecha) {
                      motivo = `No se puede registrar una licencia de antes del ${this.fecha(this.primeraFecha)}.`;
                  } else if (this.ultimaFecha && this.hasta && this.hasta > this.ultimaFecha) {
                      motivo = `No se puede registrar una licencia para después del ${this.fecha(this.ultimaFecha)}.`;
                  }
                  return motivo ? motivo + this.sugerenciaPlazo : '';
              },
              get diasDelRango() {
                  if (! this.desde || ! this.hasta || this.hasta < this.desde) { return 0; }
                  return Math.round((new Date(this.hasta) - new Date(this.desde)) / 86400000) + 1;
              },
              fecha(iso) {
                  return iso ? iso.split('-').reverse().join('/') : '—';
              },
              {{-- Cambiar las fechas cambia a quién se puede licenciar: la lista de
                   la dirección se vuelve a pedir, lo buscado se descarta y los ya
                   elegidos se revisan. Con un respiro, para no pedir mientras se
                   tipea la fecha. --}}
              fechasCambiaron() {
                  clearTimeout(this.timerFechas);
                  this.timerFechas = setTimeout(() => {
                      if (! this.desde || ! this.hasta || this.hasta < this.desde) { return; }
                      this.cerrar();
                      if (this.modo === 'direccion') { this.cargarDirecciones(); }
                      if (this.modo === 'todos') { this.verFuncionarios(); }
                      this.revisarContratos();
                  }, 400);
              },
              get cisElegidos() {
                  if (this.modo === 'uno') { return this.ci ? [this.ci] : []; }
                  if (this.modo === 'varios') { return this.elegidos.map((e) => e.id); }
                  return [];
              },
              async revisarContratos() {
                  const cis = this.cisElegidos;
                  if (cis.length === 0 || ! this.desde || ! this.hasta || this.hasta < this.desde) {
                      this.pedidoContratos++;
                      this.revisandoContratos = false;
                      return;
                  }
                  const pedido = ++this.pedidoContratos;
                  this.revisandoContratos = true;
                  this.errorContratos = '';
                  const params = new URLSearchParams({ desde: this.desde, hasta: this.hasta });
                  cis.forEach((ci) => params.append('cis[]', ci));
                  try {
                      const resp = await fetch(`{{ route('licencias.contratos') }}?${params}`, { headers: { 'Accept': 'application/json' } });
                      const cuerpo = await resp.json().catch(() => null);
                      if (pedido !== this.pedidoContratos) { return; }
                      if (resp.ok && cuerpo) {
                          {{-- Sin Mamoré configurado no se afirma nada: no se marca a nadie. --}}
                          this.contratos = cuerpo.configurado ? cuerpo.contratos : {};
                      } else {
                          this.errorContratos = (cuerpo && (cuerpo.error || cuerpo.message)) || 'No se pudieron revisar los contratos.';
                      }
                  } catch (e) {
                      if (pedido === this.pedidoContratos) { this.errorContratos = 'No se pudieron revisar los contratos.'; }
                  } finally {
                      if (pedido === this.pedidoContratos) { this.revisandoContratos = false; }
                  }
              },
              {{-- Los elegidos que no tuvieron contrato en las fechas. --}}
              get sinContrato() {
                  return this.cisElegidos.filter((ci) => ci in this.contratos && this.contratos[ci] === null);
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
                          const params = new URLSearchParams({ q: texto, desde: this.desde, hasta: this.hasta });
                          const resp = await fetch(`{{ route('licencias.funcionarios') }}?${params}`, { headers: { 'Accept': 'application/json' } });
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
              {{-- Un funcionario: se recarga con ?ci= para traer sus horarios del servidor. --}}
              elegirUno(item) {
                  const params = new URLSearchParams({ ci: item.id, desde: this.desde, hasta: this.hasta });
                  window.location = `{{ route('licencias.create') }}?${params}`;
              },
              {{-- Varios: se agregan fichas del lado del cliente, sin recargar. --}}
              agregar(item) {
                  if (! this.elegidos.some((e) => e.id === item.id)) {
                      this.elegidos.push(item);
                      {{-- Vino del buscador con fechas: ya se sabe su contrato. --}}
                      this.contratos[item.id] = item.contrato ?? null;
                  }
                  this.cerrar();
              },
              quitar(id) { this.elegidos = this.elegidos.filter((e) => e.id !== id); },
              {{-- Traba el botón mientras viaja el pedido: un segundo clic
                   anotaría las licencias dos veces. --}}
              guardando: false,
          }"
          x-on:submit="guardando = true"
          x-on:click.outside="abierto = false">
        @csrf
        <input type="hidden" name="modo" :value="modo">

        {{-- Paso 1: cuándo. Va primero porque decide a quién se puede licenciar:
             solo a quien tuvo contrato —firmado o ya concluido— esos días. --}}
        <div class="card card--padded">
            <h2 style="margin-top: 0;">¿Cuándo?</h2>

            <div class="cuando-opciones" role="radiogroup" aria-label="Fechas de la licencia">
                <button type="button" class="cuando-opcion" :class="cuando === 'hoy' && 'cuando-opcion--activa'" x-on:click="elegirCuando('hoy')"
                        :disabled="! dentroDelPlazo(hoy)" :title="dentroDelPlazo(hoy) ? '' : 'Fuera del plazo para registrar licencias'">
                    <x-heroicon-o-sun />Hoy
                </button>
                <button type="button" class="cuando-opcion" :class="cuando === 'manana' && 'cuando-opcion--activa'" x-on:click="elegirCuando('manana')"
                        :disabled="! dentroDelPlazo(manana)" :title="dentroDelPlazo(manana) ? '' : 'Fuera del plazo para registrar licencias'">
                    <x-heroicon-o-arrow-right-circle />Mañana
                </button>
                <button type="button" class="cuando-opcion" :class="cuando === 'dia' && 'cuando-opcion--activa'" x-on:click="elegirCuando('dia')">
                    <x-heroicon-o-calendar />Un día
                </button>
                <button type="button" class="cuando-opcion" :class="cuando === 'rango' && 'cuando-opcion--activa'" x-on:click="elegirCuando('rango')">
                    <x-heroicon-o-calendar-days />Rango de fechas
                </button>
            </div>

            <div class="toolbar" style="align-items: flex-end; margin-top: .75rem;">
                <div class="campo" style="margin: 0;">
                    <label for="desde">
                        <span x-show="cuando !== 'rango'">Fecha</span>
                        <span x-show="cuando === 'rango'" x-cloak>Desde</span>
                        <span class="req">*</span>
                    </label>
                    {{-- El `value` queda además del `x-model`: si Alpine no llegara a
                         correr, el campo igual sale con su fecha y no vacío. Con
                         «Hoy» y «Mañana» la fecha está puesta y no se edita. --}}
                    <input type="date" id="desde" name="desde" class="input"
                           value="{{ old('desde', $desde) }}"
                           x-model="desde" :readonly="cuando === 'hoy' || cuando === 'manana'"
                           :min="primeraFecha" :max="cuando === 'rango' ? (hasta || ultimaFecha) : ultimaFecha" required>
                    @error('desde') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="campo" style="margin: 0;" x-show="cuando === 'rango'" x-cloak>
                    <label for="hasta">Hasta <span class="req">*</span></label>
                    <input type="date" id="hasta" name="hasta" class="input"
                           value="{{ old('hasta', $hasta) }}"
                           x-model="hasta" :min="desde" :max="ultimaFecha" :disabled="cuando !== 'rango'" required>
                    @error('hasta') <div class="error">{{ $message }}</div> @enderror
                </div>
                {{-- Fuera de «Rango» el hasta es el mismo día y viaja oculto. --}}
                <input type="hidden" name="hasta" :value="hasta" :disabled="cuando === 'rango'">
            </div>
            {{-- Plazo para registrar licencias: se avisa junto a las fechas. Pasarlo
                 pide el permiso y marcarlo a propósito (StoreLicenciaRequest). --}}
            @php
                $plazoLicencias = app(\App\Services\PlazoLicencias::class)->resumen();
            @endphp
            @if ($plazoLicencias)
                <p class="ayuda" style="margin: .4rem 0 0; display: flex; align-items: center; gap: .35rem;">
                    <x-heroicon-o-calendar-days style="width: 1.1em; height: 1.1em; flex-shrink: 0;" /><span>{{ $plazoLicencias }}</span>
                </p>
                @can('Override:Licencia')
                    <div class="campo check" style="margin: .4rem 0 0;">
                        <input type="checkbox" id="fueraDePlazo" name="fueraDePlazo" value="1" x-model="fueraDePlazo">
                        <label for="fueraDePlazo" style="margin: 0;">Registrar fuera de plazo</label>
                    </div>
                @endcan
            @endif

            <div class="aviso aviso--error" style="margin: .5rem 0 0;" x-show="motivoFueraDePlazo" x-cloak x-text="motivoFueraDePlazo"></div>

            <p class="cuando-resumen" x-show="diasDelRango > 0 && ! motivoFueraDePlazo">
                <x-heroicon-o-check-circle />
                <span>
                    <strong x-text="diasDelRango === 1 ? fecha(desde) : `Del ${fecha(desde)} al ${fecha(hasta)}`"></strong>
                    <span x-text="`· ${diasDelRango} día(s) corrido(s)`"></span>.
                    Se puede licenciar a quien tuvo contrato esos días, aunque hoy ya haya terminado.
                </span>
            </p>
            <p class="error" x-show="hasta && desde && hasta < desde" x-cloak>La fecha «Hasta» no puede ser anterior a «Desde».</p>
        </div>

        {{-- Paso 2: a quiénes alcanza la licencia. --}}
        <div class="card card--padded" style="margin-top: 1rem;">
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
                <div class="campo check">
                    <input type="radio" id="modo-todos" value="todos" x-model="modo">
                    <label for="modo-todos" style="margin: 0;">Todos los funcionarios</label>
                </div>
            </div>

            @error('modo') <div class="error">{{ $message }}</div> @enderror
            @error('ci') <div class="error">{{ $message }}</div> @enderror
            @error('cis') <div class="error">{{ $message }}</div> @enderror
            @error('direccion') <div class="error">{{ $message }}</div> @enderror
            @error('unidad') <div class="error">{{ $message }}</div> @enderror

            {{-- Combo compartido por los modos «uno» y «varios». --}}
            <div class="campo" style="position: relative;" x-show="modo === 'uno' || modo === 'varios'" x-cloak>
                <label for="combo-funcionario">
                    <span x-show="modo === 'uno'">Empleado que requiere licencia</span>
                    <span x-show="modo === 'varios'" x-cloak>Agregar funcionarios a la lista</span>
                    <span class="req">*</span>
                </label>
                <input type="text" id="combo-funcionario" class="input" x-model="q"
                       x-on:input="buscar()"
                       placeholder="Escribí CI o nombre y elegí de la lista…" autocomplete="off">

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
                        <div style="padding: .55rem .7rem; color: var(--muted);"
                             x-text="`Nadie con ese nombre o CI tuvo contrato ${diasDelRango === 1 ? 'el ' + fecha(desde) : 'entre el ' + fecha(desde) + ' y el ' + fecha(hasta)}.`"></div>
                    </template>
                    <template x-for="item in resultados" :key="item.id">
                        <button type="button" x-on:click="modo === 'uno' ? elegirUno(item) : agregar(item)"
                                style="display: block; width: 100%; text-align: left; padding: .5rem .7rem;
                                       background: none; border: 0; border-bottom: 1px solid var(--border);
                                       cursor: pointer; font: inherit;"
                                onmouseover="this.style.background='var(--bg)'" onmouseout="this.style.background='transparent'">
                            <span x-text="item.texto"></span>
                            <span class="ayuda" style="display: block; margin: .1rem 0 0;" x-show="item.contrato" x-text="item.contrato"></span>
                        </button>
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
                        <div>
                            <dt>Contrato en esas fechas</dt>
                            <dd>
                                <span x-show="revisandoContratos" class="ayuda">Revisando…</span>
                                <span x-show="! revisandoContratos && contratos[ci]" x-text="contratos[ci]"></span>
                                <span x-show="! revisandoContratos && sinContrato.includes(ci)" x-cloak class="pill pill--no">Sin contrato en esas fechas</span>
                            </dd>
                        </div>
                    </dl>
                @else
                    <p class="vacio" style="margin: 0; padding: 1rem;">Elegí un funcionario para ver sus horarios asignados.</p>
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
                            <span x-show="sinContrato.includes(elegido.id)" class="pill pill--no" title="No tuvo contrato en esas fechas">Sin contrato</span>
                            <button type="button" x-on:click="quitar(elegido.id); revisarContratos()" aria-label="Quitar"
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
                    horario asignado en esos días, según su propio horario.
                </p>
            </div>

            {{-- Modo «todos»: el personal con contrato de toda la Gobernación. Solo
                 por horas: el día completo de todos es un día excepcional. --}}
            <div x-show="modo === 'todos'" x-cloak>
                {{-- La selección viaja en un solo campo: con cientos de casillas el
                     formulario se acercaría al tope de campos de PHP (`max_input_vars`). --}}
                <input type="hidden" name="cisTodos" :value="seleccionados.join(',')" :disabled="modo !== 'todos'">
                <p class="ayuda" style="margin-top: 0;">
                    Se licencia por horas a todo el personal con contrato y turno asignado esos días,
                    sin importar a qué hora sea su turno: el reporte procesado decide después a quién le
                    afecta. Para el día completo de todo el personal usá
                    <a href="{{ route('dias-excepcionales.index') }}">Días excepcionales</a>.
                </p>
            </div>

            {{-- Quiénes van a quedar licenciados, por dirección o para todos. El
                 alcance anota sin elegir uno por uno, así que se muestra la lista y
                 no solo un número. --}}
            <div x-show="(modo === 'direccion' && direccion) || modo === 'todos'" x-cloak style="margin-top: .5rem;">
                    <div x-show="cargandoFuncionarios" class="ayuda">Trayendo el personal con contrato…</div>
                    <div x-show="errorFuncionarios" class="aviso aviso--error" x-text="errorFuncionarios"></div>

                    <template x-if="! cargandoFuncionarios && ! errorFuncionarios && funcionarios.length === 0">
                        <div class="aviso aviso--error"
                             x-text="modo === 'todos'
                                 ? 'Nadie tiene contrato dentro del rango.'
                                 : 'Ningún funcionario de esa dirección tiene contrato firmado dentro del rango.'"></div>
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
                            {{-- Con cientos de filas hace falta encontrar rápido a quien
                                 desmarcar. Solo oculta filas: lo marcado sigue viajando. --}}
                            <select class="input" x-model="filtroGenero" x-on:change="elegirGenero()"
                                    aria-label="Género" style="max-width: 11rem; margin-left: auto;">
                                <option value="">Todos los géneros</option>
                                <option value="femenino">Femenino</option>
                                <option value="masculino">Masculino</option>
                            </select>
                            <input type="search" class="input" x-model="filtroLista"
                                   placeholder="Buscar por CI o nombre…" autocomplete="off"
                                   style="max-width: 18rem;">
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
                                        <th>Turno</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="persona in funcionarios" :key="persona.ci">
                                        {{-- Quien no tiene turno queda apagado, para que resalten
                                             los que sí se licencian; la etiqueta roja conserva su color. --}}
                                        <tr x-show="coincideConFiltro(persona)"
                                            :style="persona.sinTurno ? 'color: var(--muted); background: var(--bg);' : ''">
                                            <td>
                                                {{-- El `value` es el carnet, así que lo marcado viaja como
                                                     `cis[]` (o en `cisTodos`, para todos). El servidor lo
                                                     cruza contra el personal real del alcance: la selección
                                                     acota, nunca amplía. --}}
                                                <input type="checkbox" :name="modo === 'direccion' ? 'cis[]' : null" :value="persona.ci"
                                                       x-model="seleccionados" :disabled="! ['direccion', 'todos'].includes(modo) || persona.sinTurno"
                                                       :aria-label="`Licenciar a ${persona.nombre}`">
                                            </td>
                                            <td x-text="persona.ci"></td>
                                            <td>
                                                <span x-text="persona.nombre"></span>
                                                {{-- Con contrato pero sin turno esos días: al guardar
                                                     no se le anota nada, y se avisa antes. --}}
                                                <div x-show="persona.sinTurno" x-cloak style="margin-top: .25rem;">
                                                    <span class="pill pill--no pill--mini" style="white-space: nowrap;">
                                                        Sin turno asignado en estas fechas
                                                    </span>
                                                </div>
                                            </td>
                                            <td x-text="persona.cargo"></td>
                                            {{-- El turno de esos días: sobre él se aplica la licencia. --}}
                                            <td style="white-space: nowrap;" x-text="persona.turno || '—'"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>
            </div>
        </div>

        {{-- Paso 3: horarios del funcionario (solo cuando hay uno cargado). --}}
        @if ($persona)
            <div class="card card--padded" style="margin-top: 1rem;" x-show="modo === 'uno'" x-cloak>
                <div class="cabecera" style="margin: 0 0 .75rem;">
                    <h2 style="margin: 0;">
                        Horarios {{ $incluirVencidos ? '' : 'vigentes' }} de {{ $nombre ?: 'el funcionario' }}
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
                            El funcionario no tiene horarios vigentes; sí tiene {{ $vencidos }} vencido(s).
                            <a href="{{ route('licencias.create', ['ci' => $ci, 'vencidos' => 1]) }}">Mostrarlos</a>
                            para anotar una licencia retroactiva.
                        @else
                            El funcionario no tiene horarios asignados: no se le puede anotar una licencia.
                        @endif
                    </div>
                @else
                    <p class="ayuda" style="margin-top: 0;">
                        El rango de fechas define qué días se licencian: se anota una licencia por cada
                        día del rango en que el funcionario tenga horario. No hace falta marcar nada.
                    </p>

                    @error('asignaciones') <div class="error">{{ $message }}</div> @enderror

                    <table>
                        <thead>
                            <tr>
                                <th style="width: 2.5rem;" x-show="porHorario" x-cloak></th>
                                <th>Día</th>
                                <th>Horario</th>
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
                                    $horario = $asignacion->horario;
                                    $vencida = (bool) $asignacion->hasta?->isPast();
                                    $futura = (bool) $asignacion->desde?->isFuture();
                                    // Sin envío previo van todos marcados: al abrir la
                                    // selección manual arranca igual que el automático.
                                    $marcada = old('asignaciones') === null
                                        || in_array((string) $asignacion->id, (array) old('asignaciones', []), true);
                                @endphp
                                <tr @class(['fila--inactiva' => $vencida])>
                                    <td x-show="porHorario" x-cloak>
                                        {{-- Deshabilitado fuera del modo manual: así no viaja ninguna
                                             asignación y el servidor resuelve los horarios por el rango. --}}
                                        <input type="checkbox" name="asignaciones[]" value="{{ $asignacion->id }}"
                                               @checked($marcada) :disabled="! porHorario || modo !== 'uno'"
                                               x-on:change="consultarSaldo()"
                                               aria-label="Elegir horario {{ $horario->nombreHorario }}">
                                    </td>
                                    <td>{{ $abreviar((int) $horario->dia) }}</td>
                                    <td>{{ $horario->nombreHorario }}</td>
                                    <td>{{ $horario->hEntrada?->format('H:i') ?? '—' }}</td>
                                    <td>{{ $horario->hSalida?->format('H:i') ?? '—' }}</td>
                                    <td>{{ $horario->siguienteDia ? 'Sí' : '' }}</td>
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
                                    <td>{{ rtrim(rtrim(number_format((float) $horario->hTrabajadas, 2, '.', ''), '0'), '.') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <div class="campo check" style="margin: .75rem 0 0;">
                        <input type="checkbox" id="porHorario" x-model="porHorario">
                        <label for="porHorario" style="margin: 0;">
                            Licenciar solo algunos horarios
                            <span class="ayuda" style="font-weight: 400;">
                                (para quien tiene doble horario en un día y falta solo a uno de ellos)
                            </span>
                        </label>
                    </div>
                @endif
            </div>
        @endif

        {{-- Paso 4: tipo, alcance del día y motivo. --}}
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
                    <span x-show="tipo === 'institucional'" x-cloak>La dispone la institución (feriado, actividad, capacitación, comisión). Puede ser de horario completo o por horas, y no cuenta contra el tope.</span>
                    <span x-show="modo !== 'uno'" x-cloak><br>Para varios funcionarios, una dirección o todos se anota siempre licencia institucional.</span>
                </p>
                @error('tipo') <div class="error">{{ $message }}</div> @enderror
            </div>

            {{-- Alcance. El permiso personal es siempre por horas; la licencia
                 institucional es de horario completo o por horas. Lo valida también
                 `StoreLicenciaRequest`. --}}
            <input type="hidden" name="tCompleto" :value="completo ? 1 : 0">
            <div class="campo">
                <label>Alcance <span class="req">*</span></label>
                {{-- El alcance y los haberes van en la misma fila: son las dos
                     casillas del permiso. Sin tildar «Horario completo», es por horas. --}}
                <div class="toolbar" style="gap: 1.5rem; align-items: center;">
                    <div class="campo check" style="margin: 0;" x-show="tipo !== 'personal' && modo !== 'todos'" x-cloak>
                        <input type="checkbox" id="alcance-completo" x-model="completo">
                        <label for="alcance-completo" style="margin: 0;">Horario completo</label>
                    </div>
                    <span class="pill pill--info" x-show="tipo === 'personal' || modo === 'todos'">Por horas</span>
                    <div class="campo check" style="margin: 0;">
                        <input type="checkbox" id="goceHaberes" name="goceHaberes" value="1" @checked(old('goceHaberes', 1))>
                        <label for="goceHaberes" style="margin: 0;">Con goce de haberes</label>
                    </div>
                </div>
                <p class="ayuda" style="margin: .35rem 0 0;" x-show="tipo === 'personal'">
                    El permiso personal no puede ser de horario completo y cuenta contra el tope mensual de permisos.
                </p>
                <p class="ayuda" style="margin: .35rem 0 0;" x-show="tipo !== 'personal' && modo !== 'todos'" x-cloak>
                    Si no marcará en todo el día, dejá «Horario completo». Si llegará tarde o se irá
                    temprano, destildalo y cargá desde qué hora hasta qué hora.
                </p>
                <p class="ayuda" style="margin: .35rem 0 0;" x-show="modo === 'todos'" x-cloak>
                    Para todo el personal es siempre por horas: cargá desde qué hora hasta qué hora. El día
                    completo de todos se carga en <a href="{{ route('dias-excepcionales.index') }}">Días excepcionales</a>.
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
                 Solo con un funcionario y sin horario completo: en «varios» y «por
                 dirección» no entra un recuadro por persona, y el control queda al
                 guardar. Se recalcula al cambiar fechas, horas o horarios. --}}
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
                                En el rango no hay días para licenciar: no tiene horario o ya tiene licencia esos días.
                            </p>
                        </div>
                    </template>
                </div>
            @endif

            {{-- Por qué no deja anotar, cuando es por el contrato. --}}
            <div class="aviso aviso--error" x-show="sinContrato.length > 0" x-cloak>
                <span x-text="sinContrato.length === 1 ? 'Este funcionario no tuvo contrato en esas fechas.' : `${sinContrato.length} funcionarios no tuvieron contrato en esas fechas.`"></span>
                Quitalos o cambiá las fechas.
            </div>
            <div class="aviso aviso--error" x-show="errorContratos" x-cloak x-text="errorContratos"></div>

            <div class="form-acciones">
                <button type="submit" class="btn"
                        :disabled="(modo === 'uno' && ! {{ $persona ? 'true' : 'false' }})
                                   || (modo === 'varios' && elegidos.length === 0)
                                   || (modo === 'direccion' && (! direccion || seleccionados.length === 0))
                                   || (modo === 'todos' && (cargandoFuncionarios || seleccionados.length === 0))
                                   || excedeTope
                                   || motivoFueraDePlazo !== ''
                                   || sinContrato.length > 0
                                   || revisandoContratos
                                   || guardando"
                        :title="excedeTope ? 'Supera el tope mensual de permisos' : ''">
                    <span class="btn__contenido" x-show="! guardando"><x-heroicon-o-check />Anotar licencia(s)</span>
                    <span class="btn__contenido" x-show="guardando" x-cloak><span class="spinner-anillo"></span>Guardando…</span>
                </button>
                <a href="{{ route('licencias.index') }}" class="btn btn--gris"><x-heroicon-o-x-mark />Cancelar</a>
            </div>
        </div>
    </form>
@endsection
