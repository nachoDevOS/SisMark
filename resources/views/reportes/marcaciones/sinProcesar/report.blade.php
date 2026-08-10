@extends('layouts.app')

@section('titulo', 'Reporte de marcaciones sin procesar')

@section('contenido')
    <div class="cabecera">
        <div class="cabecera__titulo">
            <span class="cabecera__icono"><x-heroicon-o-document-chart-bar /></span>
            <h1>Marcaciones sin procesar</h1>
        </div>
    </div>

    <div x-data="{
             q: '',
             abierto: false,
             cargando: false,
             resultados: [],
             persona: '',
             desde: '{{ $desde }}',
             hasta: '{{ $hasta }}',
             tipo: '',
             timer: null,
             generando: false,
             resultado: '',
             buscar() {
                 this.persona = '';
                 this.resultado = '';
                 clearTimeout(this.timer);
                 const texto = this.q.trim();
                 if (texto.length < 2) { this.resultados = []; this.abierto = false; return; }
                 this.timer = setTimeout(async () => {
                     this.cargando = true;
                     this.abierto = true;
                     try {
                         const resp = await fetch(`{{ route('reportes.marcaciones.funcionarios') }}?q=${encodeURIComponent(texto)}`, { headers: { 'Accept': 'application/json' } });
                         this.resultados = resp.ok ? await resp.json() : [];
                     } catch (e) {
                         this.resultados = [];
                     } finally {
                         this.cargando = false;
                     }
                 }, 300);
             },
             elegir(item) { this.persona = item.id; this.q = item.texto; this.abierto = false; this.resultados = []; },
             async generar() {
                 if (! this.persona) { alert('Elegí un funcionario de la lista.'); return; }
                 this.generando = true;
                 try {
                     const params = new URLSearchParams({ persona: this.persona, desde: this.desde, hasta: this.hasta, tipo: this.tipo, print: 0 });
                     const resp = await fetch(`{{ route('reportes.marcaciones.sin-procesar.generar') }}?${params.toString()}`, { headers: { 'Accept': 'text/html' } });
                     this.resultado = resp.ok ? await resp.text() : '<div class=\'card card--padded\'>No se pudo generar el reporte.</div>';
                 } catch (e) {
                     this.resultado = '<div class=\'card card--padded\'>Error al generar el reporte.</div>';
                 } finally {
                     this.generando = false;
                 }
             },
         }"
         x-on:click.outside="abierto = false">

        <div class="card card--padded">
            <p style="margin-top: 0; color: var(--muted);">
                Reporte de todas las marcaciones crudas de un funcionario en un rango de fechas.
            </p>

            {{-- Combo tipo select2: se escribe y se elige de la lista. --}}
            <div class="campo" style="position: relative;">
                <label for="combo-funcionario">Funcionario (CI o nombre)</label>
                <input type="text" id="combo-funcionario" class="input" x-model="q"
                       x-on:input="buscar()"
                       x-on:focus="q = ''; persona = ''; resultados = []; resultado = ''; abierto = false"
                       placeholder="Escribí CI o nombre y elegí de la lista…" autocomplete="off" autofocus>

                <div x-show="abierto" x-cloak
                     style="position: absolute; z-index: 20; top: 100%; left: 0; right: 0; margin-top: .2rem;
                            background: var(--card); border: 1px solid var(--border); border-radius: .4rem;
                            max-height: 16rem; overflow-y: auto; box-shadow: 0 6px 16px rgba(0,0,0,.12);">
                    <template x-if="cargando">
                        <div style="padding: .55rem .7rem; color: var(--muted);">Buscando…</div>
                    </template>
                    <template x-if="! cargando && resultados.length === 0">
                        <div style="padding: .55rem .7rem; color: var(--muted);">Sin resultados.</div>
                    </template>
                    <template x-for="item in resultados" :key="item.id">
                        <button type="button" x-on:click="elegir(item)"
                                style="display: flex; align-items: center; gap: .55rem; width: 100%; text-align: left;
                                       padding: .5rem .7rem; background: none; border: 0;
                                       border-bottom: 1px solid var(--border);
                                       cursor: pointer; font: inherit;"
                                onmouseover="this.style.background='var(--bg)'" onmouseout="this.style.background='transparent'">
                            {{-- La foto solo la tiene Mamoré; desde la base local va el ícono. --}}
                            <span class="persona-foto" style="width: 1.9rem; height: 1.9rem;">
                                <template x-if="item.foto">
                                    <img :src="item.foto" alt="" loading="lazy">
                                </template>
                                <template x-if="! item.foto">
                                    <x-heroicon-o-user />
                                </template>
                            </span>
                            <span x-text="item.texto"></span>
                        </button>
                    </template>
                </div>

                <small x-show="persona" x-cloak style="color: var(--verde); margin-top: .3rem;">✔ Funcionario seleccionado.</small>
            </div>

            <div class="toolbar" style="margin-top: 1rem; align-items: flex-end;">
                <div class="campo">
                    <label for="desde">Desde</label>
                    <input type="date" id="desde" name="desde" x-model="desde" class="input">
                </div>
                <div class="campo">
                    <label for="hasta">Hasta</label>
                    <input type="date" id="hasta" name="hasta" x-model="hasta" class="input">
                </div>
                <div class="campo">
                    <label for="tipo">Tipo</label>
                    <select id="tipo" name="tipo" x-model="tipo" class="input">
                        <option value="">Todos</option>
                        <option value="R">R — Reloj</option>
                        <option value="M">M — Manual</option>
                        <option value="A">A — Usb</option>
                    </select>
                </div>
                <button type="button" class="btn" x-on:click="generar()" :disabled="! persona || generando">
                    <span class="btn__contenido" x-show="! generando"><x-heroicon-o-cog-6-tooth />Generar</span>
                    <span class="btn__contenido" x-show="generando" x-cloak><span class="spinner-anillo"></span>Generando…</span>
                </button>
            </div>
        </div>

        {{-- Resultado: se carga acá abajo sin recargar ni perder el filtro. --}}
        <div x-html="resultado" style="margin-top: 1rem;"></div>
    </div>
@endsection
