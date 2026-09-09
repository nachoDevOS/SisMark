@extends('layouts.app')

@section('titulo', 'Reporte de marcaciones por dirección')

@section('contenido')
    <div class="cabecera">
        <div class="cabecera__titulo">
            <span class="cabecera__icono"><x-heroicon-o-building-office-2 /></span>
            <h1>Marcaciones por dirección</h1>
        </div>
    </div>

    @if (session('error'))
        <div class="aviso aviso--error">{{ session('error') }}</div>
    @endif

    {{-- Los dos combos se escriben y se eligen de la lista, como el de funcionario
         del reporte individual: la Gobernación tiene 70 direcciones y 452 unidades,
         y un `select` nativo con esa cantidad obliga a bajar con la rueda buscando
         a ojo.

         El catálogo se recarga cuando cambian las fechas: el «(47)» de cada opción
         es cuánta gente tuvo esa dirección en ese rango, y con las fechas de ayer
         mentiría sobre las filas que va a traer el reporte. --}}
    <div x-data="{
             desde: '{{ $desde }}',
             hasta: '{{ $hasta }}',

             direcciones: [],
             unidades: [],
             cargandoCatalogo: false,
             errorCatalogo: '',
             /* El catálogo en pantalla es de este rango. Si un refresco falla se
                conserva el anterior —el combo sigue usable— pero hay que poder
                decir que los conteos son del rango viejo. */
             rangoDelCatalogo: '',
             temporizadorCatalogo: null,
             peticionCatalogo: null,
             catalogoPendiente: false,

             direccion: '',
             nombreDireccion: '',
             qDireccion: '',
             abiertoDireccion: false,

             unidad: '',
             nombreUnidad: '',
             qUnidad: '',
             abiertoUnidad: false,

             generando: false,
             resultado: '',

             /* Las unidades de la dirección elegida. Sin dirección no se ofrece
                ninguna: una unidad suelta no dice de dónde cuelga. */
             get unidadesDeLaDireccion() {
                 if (! this.direccion) { return []; }
                 return this.unidades.filter(u => String(u.direccionId) === String(this.direccion));
             },
             get direccionesFiltradas() { return this.filtrar(this.direcciones, this.qDireccion); },
             get unidadesFiltradas() { return this.filtrar(this.unidadesDeLaDireccion, this.qUnidad); },

             /* Todas las palabras tienen que aparecer, en cualquier orden: quien
                escribe «social gestion» encuentra «Servicio Departamental de
                Gestión Social» igual que quien lo escribe al revés. */
             filtrar(lista, termino) {
                 const palabras = termino.toLowerCase().split(/\s+/).filter(Boolean);
                 if (! palabras.length) { return lista; }
                 return lista.filter(item => {
                     const heno = item.texto.toLowerCase();
                     return palabras.every(palabra => heno.includes(palabra));
                 });
             },

             /* ¿Los conteos del combo son de otro rango que el que está elegido?
                Pasa cuando un refresco falla y se conserva el catálogo anterior. */
             get catalogoDesactualizado() {
                 return this.rangoDelCatalogo !== '' && this.rangoDelCatalogo !== `${this.desde}|${this.hasta}`;
             },

             /* Pide el catálogo, pero **una sola vez**.

                Elegir un rango cambia `desde` y `hasta` casi seguido, así que sin
                esperar salían dos pedidos pisándose; y si encima se apretaba
                Generar, el tercero encontraba a Mamoré ocupado y volvía como «no
                se pudo conectar» aunque no hubiera nada roto. Se espera a que la
                mano pare, se cancela lo que quedó en vuelo, y mientras el reporte
                se está generando se posterga: el catálogo puede esperar, el
                reporte es lo que el usuario pidió. */
             cargarCatalogo() {
                 clearTimeout(this.temporizadorCatalogo);
                 this.temporizadorCatalogo = setTimeout(() => this.traerCatalogo(), 400);
             },

             async traerCatalogo() {
                 if (this.generando) { this.catalogoPendiente = true; return; }

                 this.peticionCatalogo?.abort();
                 const corte = new AbortController();
                 this.peticionCatalogo = corte;

                 const rango = `${this.desde}|${this.hasta}`;
                 this.cargandoCatalogo = true;
                 this.errorCatalogo = '';
                 try {
                     const params = new URLSearchParams({ desde: this.desde, hasta: this.hasta });
                     const resp = await fetch(`{{ route('reportes.marcaciones.direcciones') }}?${params.toString()}`, { headers: { 'Accept': 'application/json' }, signal: corte.signal });
                     const cuerpo = await resp.json();
                     if (! resp.ok) {
                         this.errorCatalogo = cuerpo.error || 'No se pudieron traer las direcciones.';
                         return;
                     }
                     this.direcciones = cuerpo.direcciones;
                     this.unidades = cuerpo.unidades;
                     this.rangoDelCatalogo = rango;
                     /* La dirección elegida puede no tener a nadie en el rango
                        nuevo: se suelta en vez de generar un reporte vacío que
                        parecería que nadie marcó. */
                     if (this.direccion && ! this.direcciones.some(d => String(d.id) === String(this.direccion))) {
                         this.limpiarDireccion();
                         this.resultado = '';
                     } else if (this.unidad && ! this.unidadesDeLaDireccion.some(u => String(u.id) === String(this.unidad))) {
                         this.limpiarUnidad();
                         this.resultado = '';
                     }
                 } catch (e) {
                     /* Cancelado por uno más nuevo: no es una falla que mostrar. */
                     if (e.name === 'AbortError') { return; }
                     this.errorCatalogo = 'No se pudieron traer las direcciones.';
                 } finally {
                     /* Solo apaga el spinner el pedido que sigue siendo el vigente:
                        el cancelado ya no manda sobre la pantalla. */
                     if (this.peticionCatalogo === corte) { this.cargandoCatalogo = false; }
                 }
             },

             elegirDireccion(item) {
                 this.direccion = item.id;
                 this.nombreDireccion = item.texto;
                 this.qDireccion = item.texto;
                 this.abiertoDireccion = false;
                 /* Cambiar de dirección invalida la unidad: la de antes cuelga de
                    otra dirección y filtraría a nadie. */
                 this.limpiarUnidad();
                 this.resultado = '';
             },
             limpiarDireccion() {
                 this.direccion = ''; this.nombreDireccion = ''; this.qDireccion = '';
                 this.limpiarUnidad();
             },
             elegirUnidad(item) {
                 this.unidad = item.id;
                 this.nombreUnidad = item.texto;
                 this.qUnidad = item.texto;
                 this.abiertoUnidad = false;
                 this.resultado = '';
             },
             limpiarUnidad() {
                 this.unidad = ''; this.nombreUnidad = ''; this.qUnidad = '';
                 this.abiertoUnidad = false;
             },

             async generar() {
                 if (! this.direccion) { alert('Elegí una dirección de la lista.'); return; }

                 /* El reporte tiene prioridad: se corta el refresco del catálogo
                    que pudiera estar por salir o en vuelo. Los dos van contra
                    Mamoré, y pisándose el segundo vuelve como «no se pudo
                    conectar» sin que haya nada roto. */
                 clearTimeout(this.temporizadorCatalogo);
                 this.peticionCatalogo?.abort();
                 this.cargandoCatalogo = false;

                 this.generando = true;
                 try {
                     const params = new URLSearchParams({
                         direccion: this.direccion,
                         nombre: this.nombreDireccion,
                         desde: this.desde,
                         hasta: this.hasta,
                         print: 0,
                     });
                     /* Sin unidad no se manda el parámetro: la dirección entera. */
                     if (this.unidad) {
                         params.set('unidad', this.unidad);
                         params.set('nombreUnidad', this.nombreUnidad);
                     }
                     const resp = await fetch(`{{ route('reportes.marcaciones.direccion.generar') }}?${params.toString()}`, { headers: { 'Accept': 'text/html' } });
                     this.resultado = resp.ok ? await resp.text() : '<div class=\'card card--padded\'>No se pudo generar el reporte.</div>';
                 } catch (e) {
                     this.resultado = '<div class=\'card card--padded\'>Error al generar el reporte.</div>';
                 } finally {
                     this.generando = false;
                     /* El refresco que se postergó mientras se generaba. */
                     if (this.catalogoPendiente) { this.catalogoPendiente = false; this.traerCatalogo(); }
                 }
             },
         }"
         x-init="traerCatalogo()"
         x-on:click.outside="abiertoDireccion = false; abiertoUnidad = false">

        <div class="card card--padded">
            <p style="margin-top: 0; color: var(--muted);">
                Una fila por funcionario, con sus totales del rango: días controlados, atrasos,
                faltas, abandonos y horas. Entra quien tuvo <strong>contrato dentro del rango</strong>,
                aunque hoy ya no esté; si tuvo más de uno —una renovación, o un pase de
                dirección— se ven todos, y el hueco entre dos contratos no se controla.
            </p>

            <div class="toolbar" style="align-items: flex-end;">
                {{-- Combo de dirección: se escribe y se elige de la lista. --}}
                <div class="campo" style="flex: 1 1 20rem; min-width: 15rem; position: relative;">
                    <label for="combo-direccion">Dirección administrativa</label>
                    <input type="text" id="combo-direccion" class="input" x-model="qDireccion"
                           x-on:input="abiertoDireccion = true; direccion = ''; nombreDireccion = ''; limpiarUnidad(); resultado = ''"
                           x-on:focus="qDireccion = ''; limpiarDireccion(); abiertoDireccion = true"
                           :placeholder="cargandoCatalogo ? 'Cargando direcciones…' : 'Escribí para buscar y elegí de la lista…'"
                           :disabled="cargandoCatalogo" autocomplete="off" autofocus>

                    <div x-show="abiertoDireccion" x-cloak
                         style="position: absolute; z-index: 20; top: 100%; left: 0; right: 0; margin-top: .2rem;
                                background: var(--card); border: 1px solid var(--border); border-radius: .4rem;
                                max-height: 16rem; overflow-y: auto; box-shadow: 0 6px 16px rgba(0,0,0,.12);">
                        <template x-if="direccionesFiltradas.length === 0">
                            <div style="padding: .55rem .7rem; color: var(--muted);">Sin resultados.</div>
                        </template>
                        <template x-for="item in direccionesFiltradas" :key="item.id">
                            <button type="button" x-on:click="elegirDireccion(item)"
                                    style="display: flex; align-items: center; justify-content: space-between; gap: .55rem;
                                           width: 100%; text-align: left; padding: .5rem .7rem; background: none; border: 0;
                                           border-bottom: 1px solid var(--border); cursor: pointer; font: inherit;"
                                    onmouseover="this.style.background='var(--bg)'" onmouseout="this.style.background='transparent'">
                                <span x-text="item.texto"></span>
                                <small style="color: var(--muted); white-space: nowrap;" x-text="`${item.funcionarios} func.`"></small>
                            </button>
                        </template>
                    </div>

                    {{-- Tres estados que no pueden salir juntos ni contradecirse.

                         Antes el error y el «✔ seleccionada» se pintaban en la
                         misma línea: la pantalla decía a la vez que no había
                         direcciones y que había una elegida, cuando lo que había
                         pasado es que falló un refresco de conteos y la lista
                         anterior seguía perfectamente usable. --}}
                    <template x-if="errorCatalogo && direcciones.length === 0">
                        <small style="display: block; color: var(--danger); margin-top: .3rem;">
                            <span x-text="errorCatalogo"></span>
                            <button type="button" x-on:click="traerCatalogo()"
                                    style="background: none; border: 0; padding: 0; margin-left: .3rem;
                                           color: inherit; font: inherit; text-decoration: underline; cursor: pointer;">
                                Reintentar
                            </button>
                        </small>
                    </template>
                    <template x-if="errorCatalogo && direcciones.length > 0">
                        <small style="display: block; color: #92400e; margin-top: .3rem;">
                            No se pudieron actualizar los conteos<span x-show="catalogoDesactualizado"> (son los del rango anterior)</span>.
                            La lista sirve igual.
                            <button type="button" x-on:click="traerCatalogo()"
                                    style="background: none; border: 0; padding: 0; margin-left: .3rem;
                                           color: inherit; font: inherit; text-decoration: underline; cursor: pointer;">
                                Reintentar
                            </button>
                        </small>
                    </template>
                    <template x-if="! errorCatalogo && direccion">
                        <small style="display: block; color: var(--verde); margin-top: .3rem;">✔ Dirección seleccionada.</small>
                    </template>
                </div>

                {{-- Combo de unidad: aparece recién con la dirección elegida, porque
                     sus opciones son las de esa dirección. Vacío = todas. --}}
                <div class="campo" style="flex: 1 1 20rem; min-width: 15rem; position: relative;"
                     x-show="direccion" x-cloak>
                    <label for="combo-unidad">Unidad administrativa</label>
                    <input type="text" id="combo-unidad" class="input" x-model="qUnidad"
                           x-on:input="abiertoUnidad = true; unidad = ''; nombreUnidad = ''; resultado = ''"
                           x-on:focus="qUnidad = ''; limpiarUnidad(); abiertoUnidad = true"
                           placeholder="Todas las unidades" autocomplete="off">

                    <div x-show="abiertoUnidad" x-cloak
                         style="position: absolute; z-index: 20; top: 100%; left: 0; right: 0; margin-top: .2rem;
                                background: var(--card); border: 1px solid var(--border); border-radius: .4rem;
                                max-height: 16rem; overflow-y: auto; box-shadow: 0 6px 16px rgba(0,0,0,.12);">
                        {{-- Primera opción: la dirección entera. Es el caso normal, así
                             que tiene que poder elegirse sin borrar el texto a mano. --}}
                        <button type="button" x-on:click="limpiarUnidad()"
                                style="display: block; width: 100%; text-align: left; padding: .5rem .7rem;
                                       background: none; border: 0; border-bottom: 1px solid var(--border);
                                       cursor: pointer; font: inherit; font-weight: 600;"
                                onmouseover="this.style.background='var(--bg)'" onmouseout="this.style.background='transparent'">
                            Todas las unidades
                        </button>
                        <template x-if="unidadesDeLaDireccion.length === 0">
                            <div style="padding: .55rem .7rem; color: var(--muted);">
                                Esta dirección no tiene unidades cargadas en el rango.
                            </div>
                        </template>
                        {{-- Escribió algo que no cruza con ninguna: sin este aviso
                             la lista queda con «Todas las unidades» sola y parece que
                             la dirección no tuviera ninguna. --}}
                        <template x-if="unidadesDeLaDireccion.length > 0 && unidadesFiltradas.length === 0">
                            <div style="padding: .55rem .7rem; color: var(--muted);">Sin resultados.</div>
                        </template>
                        <template x-for="item in unidadesFiltradas" :key="item.id">
                            <button type="button" x-on:click="elegirUnidad(item)"
                                    style="display: flex; align-items: center; justify-content: space-between; gap: .55rem;
                                           width: 100%; text-align: left; padding: .5rem .7rem; background: none; border: 0;
                                           border-bottom: 1px solid var(--border); cursor: pointer; font: inherit;"
                                    onmouseover="this.style.background='var(--bg)'" onmouseout="this.style.background='transparent'">
                                <span x-text="item.texto"></span>
                                <small style="color: var(--muted); white-space: nowrap;" x-text="`${item.funcionarios} func.`"></small>
                            </button>
                        </template>
                    </div>

                    <small x-show="! unidad" x-cloak style="color: var(--muted); margin-top: .3rem;">
                        Sin elegir ninguna entra la dirección entera.
                    </small>
                </div>
            </div>

            {{-- El rango va en su propia fila, debajo de los combos: los dos
                 desplegables se abren hacia abajo y tapaban las fechas cuando
                 compartían la línea. --}}
            <div class="toolbar" style="align-items: flex-end; margin-top: .75rem;">
                <div class="campo">
                    <label for="desde">Desde</label>
                    <input type="date" id="desde" x-model="desde" x-on:change="cargarCatalogo()" class="input">
                </div>
                <div class="campo">
                    <label for="hasta">Hasta</label>
                    <input type="date" id="hasta" x-model="hasta" x-on:change="cargarCatalogo()" class="input">
                </div>
                <button type="button" class="btn" x-on:click="generar()" :disabled="! direccion || generando">
                    <span class="btn__contenido" x-show="! generando"><x-heroicon-o-cog-6-tooth />Generar</span>
                    <span class="btn__contenido" x-show="generando" x-cloak><span class="spinner-anillo"></span>Generando…</span>
                </button>
            </div>

            <p x-show="generando" x-cloak style="color: var(--muted); margin-bottom: 0;">
                Se está procesando la dirección entera: cada funcionario se cruza contra su
                turno, sus licencias y sus contratos. Puede tardar.
            </p>
        </div>

        {{-- Resultado: se carga acá abajo sin recargar ni perder el filtro. --}}
        <div x-html="resultado" style="margin-top: 1rem;"></div>
    </div>
@endsection
