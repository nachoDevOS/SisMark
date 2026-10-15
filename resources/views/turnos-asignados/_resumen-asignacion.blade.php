{{-- Avisos sobre las fechas elegidas y el resumen de lo que se va a guardar,
     para que quien asigna lo lea antes de confirmar. Usa el estado de
     `fichaAsignacion()` (ver `_ficha-asignacion`). --}}
<div x-show="ficha" x-cloak style="display: grid; gap: .5rem; margin-bottom: 1rem;">
    <p class="aviso aviso--advertencia" style="margin: 0;" x-show="contratoElegido() && ! contratoElegido().hasta">
        El contrato no tiene fecha de fin: indicá hasta cuándo rige el turno.
    </p>
    <p class="aviso aviso--advertencia" style="margin: 0;" x-show="desde && desde < hoy()">
        La fecha de inicio ya pasó: los días del <strong x-text="fechaTexto(desde)"></strong> a hoy se van a controlar
        con este turno, y en los reportes pueden aparecer faltas o atrasos de esos días.
    </p>

    <div class="tarjeta" x-show="puedeAsignar() && turnoNombre && desde && hasta">
        <h3 style="margin: 0 0 .35rem;">Resumen</h3>
        <p style="margin: 0;">
            Se le asignará el turno <strong x-text="`«${turnoNombre}»`"></strong>
            a <strong x-text="ficha?.persona?.nombre || `CI ${ci}`"></strong>
            del <strong x-text="fechaTexto(desde)"></strong> al <strong x-text="fechaTexto(hasta)"></strong><span
                x-show="contratoElegido()">, dentro del contrato <strong x-text="contratoElegido()?.codigo || ''"></strong></span>.
        </p>
        <p class="ayuda" style="margin: .35rem 0 0;">
            Desde ese día se le controla la asistencia con los horarios de este turno.
        </p>
    </div>
</div>
