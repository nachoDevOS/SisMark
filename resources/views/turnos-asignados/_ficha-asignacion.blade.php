{{-- Lo que hay que ver antes de asignar: los datos del funcionario, los turnos
     que ya tiene y sus contratos firmados vigentes, de los que se elige uno.
     Usa el estado de `fichaAsignacion()` del formulario que lo incluye
     (`ficha`, `contratoId`, `desde`, `hasta`…). El resumen y los avisos de
     fechas van aparte, en `_resumen-asignacion`. --}}
@once
    <style>
        .contrato-opcion { border: 1px solid var(--border); border-radius: .4rem; padding: .65rem .75rem; margin-top: .4rem; }
        .contrato-opcion--elegible { cursor: pointer; }
        .contrato-opcion--elegible:hover { background: var(--bg); }
        .contrato-opcion--elegido { border-color: var(--primary, #16a34a); box-shadow: 0 0 0 1px var(--primary, #16a34a); }
        .contrato-opcion--ocupado { opacity: .65; }
    </style>
    <script>
        window.fichaAsignacion = (inicial) => ({
            ficha: null,
            cargandoFicha: false,
            errorFicha: '',
            contratoId: inicial.contratoId || null,
            turnoNombre: inicial.turnoNombre || '',
            desde: inicial.desde,
            hasta: inicial.hasta,
            minDesde: null,
            maxHasta: null,
            async cargarFicha(ci) {
                this.ficha = null;
                this.errorFicha = '';
                if (! ci) { return; }
                this.cargandoFicha = true;
                try {
                    const resp = await fetch(`{{ route('turnos-asignados.ficha') }}?ci=${encodeURIComponent(ci)}`, { headers: { 'Accept': 'application/json' } });
                    if (! resp.ok) { throw new Error(); }
                    this.ficha = await resp.json();
                    // Si volvió de un error de validación se respeta lo elegido y
                    // sus fechas; si hay un solo contrato libre, se elige solo.
                    const libres = this.ficha.contratos.filter(c => ! c.turno);
                    const elegido = libres.find(c => String(c.id) === String(this.contratoId));
                    if (elegido) {
                        this.marcarContrato(elegido, false);
                    } else {
                        this.contratoId = null;
                        if (libres.length === 1) { this.marcarContrato(libres[0], true); }
                    }
                } catch (e) {
                    this.errorFicha = 'No se pudo cargar la información del funcionario. Reintentá.';
                } finally {
                    this.cargandoFicha = false;
                }
            },
            // Las fechas salen del contrato; se pueden acortar, no estirar.
            marcarContrato(contrato, conFechas) {
                this.contratoId = contrato.id;
                this.minDesde = contrato.desde;
                this.maxHasta = contrato.hasta;
                if (conFechas) {
                    this.desde = contrato.desde;
                    this.hasta = contrato.hasta || '';
                }
            },
            contratoElegido() {
                return this.ficha?.contratos.find(c => String(c.id) === String(this.contratoId)) || null;
            },
            // El selector de turno vive en su propio componente: el nombre del
            // elegido se toma del `change` que sube hasta el formulario.
            notarTurno(evento) {
                if (evento.target.name === 'turno_id') { this.turnoNombre = evento.target.dataset.nombre || ''; }
            },
            fechaTexto(iso) {
                if (! iso) { return ''; }
                const [anio, mes, dia] = iso.split('-');
                return `${dia}/${mes}/${anio}`;
            },
            hoy() {
                const fecha = new Date();
                return `${fecha.getFullYear()}-${String(fecha.getMonth() + 1).padStart(2, '0')}-${String(fecha.getDate()).padStart(2, '0')}`;
            },
            puedeAsignar() {
                return this.ficha !== null && (! this.ficha.mamore || this.contratoId !== null);
            },
        });
    </script>
@endonce

<input type="hidden" name="contrato_id" :value="contratoId || ''">

<p class="vacio" style="margin: 0; padding: .75rem;" x-show="cargandoFicha" x-cloak>Consultando al funcionario en Mamoré…</p>
<div class="error" x-show="errorFicha" x-cloak x-text="errorFicha"></div>

<template x-if="ficha">
    <div style="display: grid; gap: .75rem; margin-bottom: 1rem;">
        {{-- 1. Quién es --}}
        <div class="tarjeta">
            <div style="display: flex; justify-content: space-between; gap: .5rem; flex-wrap: wrap; align-items: center;">
                <h3 style="margin: 0;">1. Funcionario</h3>
                <a x-show="ficha.persona && ficha.persona.ver" :href="ficha.persona?.ver" target="_blank" rel="noopener" class="btn btn--gris">
                    <x-heroicon-o-arrow-top-right-on-square />Ver ficha completa
                </a>
            </div>
            <template x-if="ficha.persona">
                <dl class="datos grid-2" style="margin: .5rem 0 0;">
                    <div><dt>Nombre</dt><dd x-text="ficha.persona.nombre || '—'"></dd></div>
                    <div><dt>CI</dt><dd x-text="ficha.persona.ciCompleto || ficha.persona.ci"></dd></div>
                    <div><dt>Cargo actual</dt><dd x-text="ficha.persona.cargo || '—'"></dd></div>
                    <div><dt>Dirección</dt><dd x-text="ficha.persona.direccion || '—'"></dd></div>
                    <template x-if="ficha.persona.tipoContrato">
                        <div><dt>Tipo de contrato</dt><dd x-text="ficha.persona.tipoContrato"></dd></div>
                    </template>
                </dl>
            </template>
            <p class="vacio" style="margin: .5rem 0 0;" x-show="! ficha.persona">No figura en Mamoré ni en la base local.</p>
        </div>

        {{-- 2. Qué turno tiene hoy --}}
        <div class="tarjeta">
            <h3 style="margin: 0;">2. Turnos que ya tiene</h3>
            <p class="ayuda" style="margin: .25rem 0 .5rem;">
                Los vigentes y los que todavía no empezaron. El turno nuevo no puede cruzarse en fechas con ninguno de estos.
            </p>
            <p class="aviso aviso--advertencia" style="margin: 0;" x-show="ficha.turnos.length === 0">
                No tiene ningún turno asignado: hoy <strong>no se le controla la asistencia</strong>.
            </p>
            <template x-for="(turno, i) in ficha.turnos" :key="i">
                <div style="display: flex; flex-wrap: wrap; gap: .4rem; align-items: center; padding: .35rem 0; border-top: 1px solid var(--border);">
                    <strong x-text="turno.nombre"></strong>
                    <span x-text="`del ${turno.desdeTexto} al ${turno.hastaTexto}`"></span>
                    <span class="pill" :class="turno.vigente ? 'pill--ok' : 'pill--info'" x-text="turno.vigente ? 'Vigente hoy' : 'Por empezar'"></span>
                    <span class="pill pill--advertencia" x-show="! turno.contrato"
                          title="Se asignó a mano, sin contrato: si Mamoré extiende o anula el contrato, este turno no se mueve solo">Sin contrato</span>
                </div>
            </template>
        </div>

        {{-- 3. A qué contrato se ata --}}
        <div class="tarjeta">
            <h3 style="margin: 0;">3. Contrato <span class="req" x-show="ficha.mamore">*</span></h3>
            <p class="ayuda" style="margin: .25rem 0 .5rem;" x-show="ficha.mamore">
                El turno se asigna dentro de un <strong>contrato firmado vigente</strong> de Mamoré. Sus fechas salen del
                contrato y no pueden pasarlo. Si después el contrato se extiende con una adenda o se anula, el turno se
                ajusta solo.
            </p>
            <p class="ayuda" style="margin: .25rem 0 0;" x-show="! ficha.mamore">Mamoré no está configurado: el turno se asigna sin contrato.</p>
            <div class="error" x-show="ficha.errorContratos" x-text="ficha.errorContratos"></div>
            <p class="aviso aviso--error" style="margin: 0;"
               x-show="ficha.mamore && ! ficha.errorContratos && ficha.contratos.length === 0">
                No tiene contrato firmado vigente en Mamoré: <strong>no se le puede asignar turno</strong>. Primero tiene que
                cargarse y firmarse su contrato en Mamoré.
            </p>
            {{-- Con varios contratos se elige uno; con uno solo no hay nada que
                 elegir: se toma ese y se dice. --}}
            <p style="margin: .25rem 0 0; font-weight: 600;" x-show="ficha.contratos.filter(c => ! c.turno).length > 1">
                Tiene más de un contrato vigente: elegí a cuál corresponde el turno.
            </p>
            <p style="margin: .25rem 0 0; font-weight: 600;" x-show="ficha.contratos.filter(c => ! c.turno).length === 1">
                El turno se asigna dentro de este contrato:
            </p>
            <template x-for="contrato in ficha.contratos" :key="contrato.id">
                <div class="contrato-opcion"
                     :class="{
                         'contrato-opcion--elegido': String(contratoId) === String(contrato.id),
                         'contrato-opcion--ocupado': contrato.turno !== null,
                         'contrato-opcion--elegible': contrato.turno === null && ficha.contratos.filter(c => ! c.turno).length > 1,
                     }"
                     x-on:click="if (contrato.turno === null) { marcarContrato(contrato, true); }">
                    <div style="display: flex; gap: .5rem; align-items: center; flex-wrap: wrap;">
                        <input type="radio" :value="contrato.id" :checked="String(contratoId) === String(contrato.id)"
                               x-show="contrato.turno === null && ficha.contratos.filter(c => ! c.turno).length > 1"
                               :aria-label="`Elegir el contrato ${contrato.codigo || contrato.id}`">
                        <strong x-text="contrato.codigo || `Contrato #${contrato.id}`"></strong>
                        <span class="pill" :class="contrato.empezo ? 'pill--ok' : 'pill--info'"
                              x-text="contrato.empezo ? 'Vigente' : 'Empieza el ' + contrato.desdeTexto"></span>
                    </div>
                    <dl class="datos grid-2" style="margin: .35rem 0 0;">
                        <div><dt>Cargo</dt><dd x-text="contrato.cargo || '—'"></dd></div>
                        <div><dt>Dirección</dt><dd x-text="contrato.direccion || '—'"></dd></div>
                        <div><dt>Inicio</dt><dd x-text="contrato.desdeTexto"></dd></div>
                        <div><dt>Fin</dt><dd x-text="contrato.hastaTexto || 'Sin fecha de fin (contrato abierto)'"></dd></div>
                    </dl>
                    <template x-if="contrato.turno">
                        <p class="aviso aviso--advertencia" style="margin: .4rem 0 0;"
                           x-text="`Este contrato ya tiene el turno «${contrato.turno.nombre}» (del ${contrato.turno.desdeTexto} al ${contrato.turno.hastaTexto}). Un contrato admite un solo turno.`"></p>
                    </template>
                </div>
            </template>
            @error('contrato_id') <div class="error">{{ $message }}</div> @enderror
        </div>
    </div>
</template>
