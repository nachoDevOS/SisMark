@extends('layouts.app')

@section('titulo', 'Funcionarios sin turno')

@section('contenido')
    <div class="cabecera">
        <div class="cabecera__titulo">
            <span class="cabecera__icono"><x-heroicon-o-calendar-days /></span>
            <h1>Funcionarios sin turno</h1>
        </div>
    </div>

    @if (session('error'))
        <div class="aviso aviso--error">{{ session('error') }}</div>
    @endif

    {{-- Mismos combos que el reporte por dirección (se escribe y se elige de la
         lista), pero los dos opcionales: sin dirección entra la Gobernación entera.
         El catálogo es el de hoy, sin rango: el turno que se mira es el vigente. --}}
    <div x-data="{
             direcciones: [],
             unidades: [],
             cargandoCatalogo: false,
             errorCatalogo: '',

             direccion: '',
             nombreDireccion: '',
             qDireccion: '',
             abiertoDireccion: false,

             unidad: '',
             nombreUnidad: '',
             qUnidad: '',
             abiertoUnidad: false,

             tipo: '',

             generando: false,
             resultado: '',

             get unidadesDeLaDireccion() {
                 if (! this.direccion) { return []; }
                 return this.unidades.filter(u => String(u.direccionId) === String(this.direccion));
             },
             get direccionesFiltradas() { return this.filtrar(this.direcciones, this.qDireccion); },
             get unidadesFiltradas() { return this.filtrar(this.unidadesDeLaDireccion, this.qUnidad); },

             /* Todas las palabras tienen que aparecer, en cualquier orden. */
             filtrar(lista, termino) {
                 const palabras = termino.toLowerCase().split(/\s+/).filter(Boolean);
                 if (! palabras.length) { return lista; }
                 return lista.filter(item => {
                     const heno = item.texto.toLowerCase();
                     return palabras.every(palabra => heno.includes(palabra));
                 });
             },

             async traerCatalogo() {
                 this.cargandoCatalogo = true;
                 this.errorCatalogo = '';
                 try {
                     const resp = await fetch(`{{ route('reportes.marcaciones.direcciones') }}`, { headers: { 'Accept': 'application/json' } });
                     const cuerpo = await resp.json();
                     if (! resp.ok) {
                         this.errorCatalogo = cuerpo.error || 'No se pudieron traer las direcciones.';
                         return;
                     }
                     this.direcciones = cuerpo.direcciones;
                     this.unidades = cuerpo.unidades;
                 } catch (e) {
                     this.errorCatalogo = 'No se pudieron traer las direcciones.';
                 } finally {
                     this.cargandoCatalogo = false;
                 }
             },

             elegirDireccion(item) {
                 this.direccion = item.id;
                 this.nombreDireccion = item.texto;
                 this.qDireccion = item.texto;
                 this.abiertoDireccion = false;
                 /* La unidad de antes cuelga de otra dirección. */
                 this.limpiarUnidad();
             },
             limpiarDireccion() {
                 this.direccion = ''; this.nombreDireccion = ''; this.qDireccion = '';
                 this.abiertoDireccion = false;
                 this.limpiarUnidad();
             },
             elegirUnidad(item) {
                 this.unidad = item.id;
                 this.nombreUnidad = item.texto;
                 this.qUnidad = item.texto;
                 this.abiertoUnidad = false;
             },
             limpiarUnidad() {
                 this.unidad = ''; this.nombreUnidad = ''; this.qUnidad = '';
                 this.abiertoUnidad = false;
             },

             parametros() {
                 const params = new URLSearchParams();
                 if (this.direccion) {
                     params.set('direccion', this.direccion);
                     params.set('nombreDireccion', this.nombreDireccion);
                 }
                 if (this.unidad) {
                     params.set('unidad', this.unidad);
                     params.set('nombreUnidad', this.nombreUnidad);
                 }
                 if (this.tipo) {
                     params.set('tipo', this.tipo);
                     params.set('nombreTipo', this.$refs.tipo.selectedOptions[0].text);
                 }
                 return params;
             },

             async generar(url = null) {
                 this.generando = true;
                 try {
                     const destino = url ?? `{{ route('reportes.funcionarios.sin-turno.generar') }}?${this.parametros().toString()}`;
                     const resp = await fetch(destino, { headers: { 'Accept': 'text/html' } });
                     this.resultado = resp.ok ? await resp.text() : '<div class=\'card card--padded\'>No se pudo generar el reporte.</div>';
                 } catch (e) {
                     this.resultado = '<div class=\'card card--padded\'>Error al generar el reporte.</div>';
                 } finally {
                     this.generando = false;
                 }
             },
         }"
         x-init="traerCatalogo()"
         x-on:click.outside="abiertoDireccion = false; abiertoUnidad = false">

        <div class="card card--padded">
            <p style="margin-top: 0; color: var(--muted);">
                Funcionarios con <strong>contrato firmado</strong> que hoy no tienen ningún turno ni
                horario vigente. Sin elegir dirección entra la Gobernación entera.
            </p>

            <div class="toolbar" style="align-items: flex-end;">
                <div class="campo" style="flex: 1 1 20rem; min-width: 15rem; position: relative;">
                    <label for="combo-direccion">Dirección administrativa</label>
                    <input type="text" id="combo-direccion" class="input" x-model="qDireccion"
                           x-on:input="abiertoDireccion = true; direccion = ''; nombreDireccion = ''; limpiarUnidad()"
                           x-on:focus="limpiarDireccion(); abiertoDireccion = true"
                           :placeholder="cargandoCatalogo ? 'Cargando direcciones…' : 'Todas las direcciones'"
                           :disabled="cargandoCatalogo" autocomplete="off">

                    <div x-show="abiertoDireccion" x-cloak
                         style="position: absolute; z-index: 20; top: 100%; left: 0; right: 0; margin-top: .2rem;
                                background: var(--card); border: 1px solid var(--border); border-radius: .4rem;
                                max-height: 16rem; overflow-y: auto; box-shadow: 0 6px 16px rgba(0,0,0,.12);">
                        <button type="button" x-on:click="limpiarDireccion()"
                                style="display: block; width: 100%; text-align: left; padding: .5rem .7rem;
                                       background: none; border: 0; border-bottom: 1px solid var(--border);
                                       cursor: pointer; font: inherit; font-weight: 600;"
                                onmouseover="this.style.background='var(--bg)'" onmouseout="this.style.background='transparent'">
                            Todas las direcciones
                        </button>
                        <template x-if="direcciones.length > 0 && direccionesFiltradas.length === 0">
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

                    <template x-if="errorCatalogo">
                        <small style="display: block; color: var(--danger); margin-top: .3rem;">
                            <span x-text="errorCatalogo"></span>
                            <button type="button" x-on:click="traerCatalogo()"
                                    style="background: none; border: 0; padding: 0; margin-left: .3rem;
                                           color: inherit; font: inherit; text-decoration: underline; cursor: pointer;">
                                Reintentar
                            </button>
                        </small>
                    </template>
                </div>

                {{-- La unidad aparece recién con la dirección elegida: sus opciones
                     son las de esa dirección. Vacío = todas. --}}
                <div class="campo" style="flex: 1 1 20rem; min-width: 15rem; position: relative;"
                     x-show="direccion" x-cloak>
                    <label for="combo-unidad">Unidad administrativa</label>
                    <input type="text" id="combo-unidad" class="input" x-model="qUnidad"
                           x-on:input="abiertoUnidad = true; unidad = ''; nombreUnidad = ''"
                           x-on:focus="limpiarUnidad(); abiertoUnidad = true"
                           placeholder="Todas las unidades" autocomplete="off">

                    <div x-show="abiertoUnidad" x-cloak
                         style="position: absolute; z-index: 20; top: 100%; left: 0; right: 0; margin-top: .2rem;
                                background: var(--card); border: 1px solid var(--border); border-radius: .4rem;
                                max-height: 16rem; overflow-y: auto; box-shadow: 0 6px 16px rgba(0,0,0,.12);">
                        <button type="button" x-on:click="limpiarUnidad()"
                                style="display: block; width: 100%; text-align: left; padding: .5rem .7rem;
                                       background: none; border: 0; border-bottom: 1px solid var(--border);
                                       cursor: pointer; font: inherit; font-weight: 600;"
                                onmouseover="this.style.background='var(--bg)'" onmouseout="this.style.background='transparent'">
                            Todas las unidades
                        </button>
                        <template x-if="unidadesDeLaDireccion.length === 0">
                            <div style="padding: .55rem .7rem; color: var(--muted);">Esta dirección no tiene unidades cargadas.</div>
                        </template>
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
                </div>
            </div>

            <div class="toolbar" style="align-items: flex-end; margin-top: .75rem;">
                <div class="campo">
                    <label for="tipo">Tipo de contrato</label>
                    <select id="tipo" x-ref="tipo" x-model="tipo" class="input">
                        <option value="">Todos</option>
                        @foreach ($tipos as $opcion)
                            <option value="{{ $opcion['id'] }}">{{ $opcion['nombre'] }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="button" class="btn" x-on:click="generar()" :disabled="generando">
                    <span class="btn__contenido" x-show="! generando"><x-heroicon-o-cog-6-tooth />Generar</span>
                    <span class="btn__contenido" x-show="generando" x-cloak><span class="spinner-anillo"></span>Generando…</span>
                </button>
            </div>
        </div>

        {{-- Resultado. La paginación del parcial se intercepta para no salir de
             la pantalla ni perder los filtros. --}}
        <div x-html="resultado" style="margin-top: 1rem;"
             x-on:click="const enlace = $event.target.closest('a.pag__link'); if (enlace) { $event.preventDefault(); generar(enlace.href); }"></div>
    </div>
@endsection
