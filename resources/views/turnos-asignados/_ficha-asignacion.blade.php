{{-- Lo que hay que ver antes de asignar: los datos del funcionario, los turnos
     que ya tiene y sus contratos firmados o concluidos, de los que se elige uno.
     Usa el estado de `fichaAsignacion()` del formulario que lo incluye
     (`ficha`, `contratoId`, `desde`, `hasta`…). El resumen y los avisos de
     fechas van aparte, en `_resumen-asignacion`. --}}
@include('turnos-asignados._ficha-asignacion-js')

@once
    <style>
        .contrato-opcion { border: 1px solid var(--border); border-radius: .4rem; padding: .65rem .75rem; margin-top: .4rem; }
        .contrato-opcion--elegible { cursor: pointer; }
        .contrato-opcion--elegible:hover { background: var(--bg); }
        .contrato-opcion--elegido { border-color: var(--primary, #16a34a); box-shadow: 0 0 0 1px var(--primary, #16a34a); }
        .contrato-opcion--ocupado { opacity: .65; }
    </style>
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
            <p class="aviso aviso--advertencia" style="margin: 0;" x-show="ficha.turnos.length === 0 && ficha.heredados.length === 0">
                No tiene turno ni horarios asignados: hoy <strong>no se le controla la asistencia</strong>.
            </p>
            {{-- Sin turno pero con horarios del SIA: igual se le controla, y al
                 asignar el turno esos horarios se cortan el día antes del inicio. --}}
            <template x-for="(heredado, i) in ficha.heredados" :key="`h${i}`">
                <div style="display: flex; flex-wrap: wrap; gap: .4rem; align-items: center; padding: .35rem 0; border-top: 1px solid var(--border);">
                    <strong>Horarios del sistema anterior</strong>
                    <span x-text="`del ${heredado.desdeTexto} al ${heredado.hastaTexto}`"></span>
                    <span class="pill pill--info" x-text="heredado.vigente ? 'Vigentes hoy' : 'Por empezar'"></span>
                    <span class="ayuda" style="margin: 0;">Terminan el día antes del inicio del turno nuevo.</span>
                </div>
            </template>
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
                El turno se asigna dentro de un <strong>contrato firmado o concluido</strong> de Mamoré. Sus fechas salen
                del contrato y no pueden pasarlo. Si después el contrato se extiende con una adenda o se anula, el turno se
                ajusta solo. Los contratos «Por regularizar» ya terminaron sin turno: se les asigna para que esos días se
                controlen.
            </p>
            <p class="ayuda" style="margin: .25rem 0 0;" x-show="! ficha.mamore">Mamoré no está configurado: el turno se asigna sin contrato.</p>
            <div class="error" x-show="ficha.errorContratos" x-text="ficha.errorContratos"></div>
            <p class="aviso aviso--advertencia" style="margin: 0;" x-show="nadaQueAsignar()">
                <strong>No hay contrato al que asignarle turno:</strong> sus contratos ya tienen turno o los cubrían horarios
                del sistema anterior. Para cambiarle el turno, concluí el que tiene.
            </p>
            <p class="aviso aviso--error" style="margin: 0;"
               x-show="ficha.mamore && ! ficha.errorContratos && ficha.contratos.length === 0">
                No tiene contrato firmado ni concluido en Mamoré: <strong>no se le puede asignar turno</strong>. Primero tiene que
                cargarse y firmarse su contrato en Mamoré.
            </p>
            {{-- Con varios contratos se elige uno; con uno solo no hay nada que
                 elegir: se toma ese y se dice. --}}
            <p style="margin: .25rem 0 0; font-weight: 600;" x-show="contratosLibres().length > 1">
                Tiene más de un contrato: elegí a cuál corresponde el turno.
            </p>
            <p style="margin: .25rem 0 0; font-weight: 600;" x-show="contratosLibres().length === 1">
                El turno se asigna dentro de este contrato:
            </p>
            <template x-for="contrato in contratosVisibles()" :key="contrato.id">
                <div class="contrato-opcion"
                     :class="{
                         'contrato-opcion--elegido': String(contratoId) === String(contrato.id),
                         'contrato-opcion--ocupado': ! elegible(contrato),
                         'contrato-opcion--elegible': elegible(contrato) && contratosLibres().length > 1,
                     }"
                     x-on:click="if (elegible(contrato)) { marcarContrato(contrato, true); }">
                    <div style="display: flex; gap: .5rem; align-items: center; flex-wrap: wrap;">
                        <input type="radio" :value="contrato.id" :checked="String(contratoId) === String(contrato.id)"
                               x-show="elegible(contrato) && contratosLibres().length > 1"
                               :aria-label="`Elegir el contrato ${contrato.codigo || contrato.id}`">
                        <strong x-text="contrato.codigo || `Contrato #${contrato.id}`"></strong>
                        <span class="pill" :class="contrato.regularizar ? 'pill--advertencia' : (contrato.empezo ? 'pill--ok' : 'pill--info')"
                              x-text="contrato.regularizar ? 'Por regularizar' : (contrato.empezo ? 'Vigente' : 'Empieza el ' + contrato.desdeTexto)"></span>
                    </div>
                    <dl class="datos grid-2" style="margin: .35rem 0 0;">
                        <div><dt>Cargo</dt><dd x-text="contrato.cargo || '—'"></dd></div>
                        <div><dt>Dirección</dt><dd x-text="contrato.direccion || '—'"></dd></div>
                        <div><dt>Inicio</dt><dd x-text="contrato.desdeTexto"></dd></div>
                        <div><dt>Fin</dt><dd x-text="contrato.hastaTexto || 'Sin fecha de fin (contrato abierto)'"></dd></div>
                    </dl>
                    <template x-if="contrato.cobertura?.motivo">
                        <p class="aviso" style="margin: .4rem 0 0;" x-text="contrato.cobertura.motivo"></p>
                    </template>
                    {{-- Un contrato que terminó se regulariza de a tramos y puede
                         tener varios turnos; el vigente admite uno solo. --}}
                    <template x-if="! contrato.regularizar && contrato.turno && elegible(contrato)">
                        <p class="aviso" style="margin: .4rem 0 0;"
                           x-text="`Sin turno del ${contrato.cobertura.libreDesdeTexto} al ${contrato.cobertura.libreHastaTexto || 'fin del contrato'}: el turno nuevo cubre ese tramo.`"></p>
                    </template>
                    <template x-if="! contrato.regularizar && contrato.turno && ! elegible(contrato)">
                        <p class="aviso aviso--advertencia" style="margin: .4rem 0 0;"
                           x-text="`Este contrato ya tiene el turno «${contrato.turno.nombre}» (del ${contrato.turno.desdeTexto} al ${contrato.turno.hastaTexto}). Un contrato admite un solo turno.`"></p>
                    </template>
                </div>
            </template>
            @error('contrato_id') <div class="error">{{ $message }}</div> @enderror
        </div>
    </div>
</template>
