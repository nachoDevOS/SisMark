{{-- Plazo para registrar licencias (App\Models\ConfiguracionPlazoLicencia). Va aparte de los
     grupos de Configuracion porque tiene tabla propia y rige por día, no por
     mes. Recibe $plazos (todas las vigencias, de la más nueva a la más vieja)
     y $plazoVigente (la de hoy, o null). --}}
@php
    $hoy = today();
    $plazoHoy = app(\App\Services\PlazoLicencias::class)->resumen();
    // Lo que muestra el formulario al abrir (o al rebotar la validación): de
    // ahí sale el ejemplo inicial, que después se recalcula al escribir.
    $enFormulario = new \App\Models\ConfiguracionPlazoLicencia([
        'dias_atraso' => (int) old('dias_atraso', $plazoVigente?->dias_atraso ?? 0),
        'dias_anticipacion' => (int) old('dias_anticipacion', $plazoVigente?->dias_anticipacion ?? 0),
        'dias_habiles' => (bool) old('dias_habiles', $plazoVigente?->dias_habiles ?? false),
    ]);
@endphp

<section id="plazo-licencias" class="card card--padded" style="margin-bottom: 1rem;">
    <div style="display: flex; gap: .6rem; align-items: flex-start; margin-bottom: 1rem;">
        <span class="cabecera__icono"><x-heroicon-o-calendar-days /></span>
        <div>
            <h2 style="margin: 0;">Plazo para registrar licencias</h2>
            <p class="ayuda" style="margin: .2rem 0 0;">
                Hasta cuántos días atrás y cuántos adelante de hoy se puede registrar una licencia. Rige para la
                solicitud del funcionario desde Mamoré y para el alta de Recursos Humanos en SisMark. Quien tenga
                el permiso «Fuera de plazo» en Licencias puede pasarlo desde SisMark, marcándolo en el formulario.
            </p>
        </div>
    </div>

    @if ($plazoHoy)
        <div class="aviso" style="margin-bottom: 1rem;">Rige hoy: {{ $plazoHoy }}</div>
    @endif

    {{-- El ejemplo repite en el navegador la cuenta de ConfiguracionPlazoLicencia::primeraFecha()
         y ultimaFecha(): en hábiles se saltan sábados y domingos. --}}
    <form action="{{ route('configuracion.plazo-licencias.update') }}" method="POST"
          x-data="{
              atras: @js($enFormulario->dias_atraso),
              adelante: @js($enFormulario->dias_anticipacion),
              habiles: @js($enFormulario->dias_habiles),
              hoy: new Date({{ $hoy->year }}, {{ $hoy->month - 1 }}, {{ $hoy->day }}),
              correr(dias) {
                  const fecha = new Date(this.hoy);
                  if (! this.habiles) { fecha.setDate(fecha.getDate() + dias); return fecha; }
                  const paso = dias < 0 ? -1 : 1;
                  for (let restan = Math.abs(dias); restan > 0;) {
                      fecha.setDate(fecha.getDate() + paso);
                      if (fecha.getDay() !== 0 && fecha.getDay() !== 6) { restan--; }
                  }
                  return fecha;
              },
              fecha(f) {
                  const dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
                  const dos = (n) => String(n).padStart(2, '0');
                  return `${dias[f.getDay()]} ${dos(f.getDate())}/${dos(f.getMonth() + 1)}/${f.getFullYear()}`;
              },
              get ejemplo() {
                  const atras = Math.max(0, parseInt(this.atras) || 0);
                  const adelante = Math.max(0, parseInt(this.adelante) || 0);
                  const primera = atras > 0 ? this.fecha(this.correr(-atras)) : null;
                  const ultima = adelante > 0 ? this.fecha(this.correr(adelante)) : null;
                  let rango = 'se puede registrar cualquier fecha, sin límite';
                  if (primera && ultima) { rango = `se puede registrar del ${primera} al ${ultima}`; }
                  else if (primera) { rango = `se puede registrar desde el ${primera}, sin límite de anticipación`; }
                  else if (ultima) { rango = `se puede registrar hasta el ${ultima}, sin límite hacia atrás`; }
                  return `Ejemplo con la fecha de hoy (${this.fecha(this.hoy)}): ${rango}.`;
              },
          }">
        @csrf
        @method('PUT')

        <div class="grid-2" style="max-width: 32rem;">
            <div class="campo">
                <label for="dias_atraso">Días hacia atrás <span class="req">*</span></label>
                <input type="number" id="dias_atraso" name="dias_atraso" min="0" max="{{ \App\Models\ConfiguracionPlazoLicencia::MAX_DIAS }}" required
                       value="{{ $enFormulario->dias_atraso }}" x-model="atras">
                <div class="ayuda">Ej.: 3 — una licencia de hace más de 3 días ya no se registra.</div>
                @error('dias_atraso') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="campo">
                <label for="dias_anticipacion">Días de anticipación <span class="req">*</span></label>
                <input type="number" id="dias_anticipacion" name="dias_anticipacion" min="0" max="{{ \App\Models\ConfiguracionPlazoLicencia::MAX_DIAS }}" required
                       value="{{ $enFormulario->dias_anticipacion }}" x-model="adelante">
                <div class="ayuda">Ej.: 7 — no se registra una licencia para más de 7 días adelante.</div>
                @error('dias_anticipacion') <div class="error">{{ $message }}</div> @enderror
            </div>
        </div>
        <p class="ayuda" style="margin: -.4rem 0 .8rem;">En 0, ese lado no tiene límite.</p>

        <div class="campo check">
            <input type="checkbox" id="dias_habiles" name="dias_habiles" value="1"
                   @checked($enFormulario->dias_habiles) x-model="habiles">
            <label for="dias_habiles" style="margin: 0;">Contar en días hábiles (lunes a viernes)</label>
        </div>
        <p class="ayuda" style="margin: -.4rem 0 1rem;">
            Sin marcar, se cuentan días corridos. En hábiles, una semana son 5 días. Los feriados no se descuentan.
        </p>

        <div class="aviso" style="margin-bottom: 1rem;">
            <x-heroicon-o-calendar-days style="width: 1em; height: 1em; vertical-align: -.15em;" />
            <span x-text="ejemplo">{{ app(\App\Services\PlazoLicencias::class)->ejemplo($enFormulario) }}</span>
        </div>

        <div class="tarjeta" style="margin-bottom: 1rem;">
            <div class="grid-2">
                <div class="campo">
                    <label for="plazo-vigente_desde">Rige desde el día <span class="req">*</span></label>
                    <input type="date" id="plazo-vigente_desde" name="plazo_desde" required
                           min="{{ $hoy->toDateString() }}"
                           value="{{ old('plazo_desde', $hoy->toDateString()) }}">
                    <div class="ayuda">
                        Desde ese día y hasta que se cargue otro cambio. Solo hoy o un día futuro: lo que ya rigió no
                        se cambia.
                    </div>
                    @error('plazo_desde') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="campo">
                    <label for="plazo-motivo">Motivo del cambio <span class="req">*</span></label>
                    <textarea id="plazo-motivo" name="plazo_motivo" rows="3" maxlength="500" required
                              placeholder="Ej.: Instructivo de Recursos Humanos N.º 012/2026">{{ old('plazo_motivo') }}</textarea>
                    @error('plazo_motivo') <div class="error">{{ $message }}</div> @enderror
                </div>
            </div>
        </div>

        <div class="form-acciones">
            <button type="submit" class="btn"><x-heroicon-o-check />Guardar plazo</button>
        </div>
    </form>

    {{-- Historial: una fila por vigencia, de la más nueva a la más vieja. --}}
    <h3 style="margin: 1.5rem 0 .5rem;">Historial</h3>

    @if ($plazos->isEmpty())
        <p class="vacio" style="margin: 0;">Todavía no se cargó ningún plazo: las licencias se registran sin límite de fechas.</p>
    @else
        <div style="overflow-x: auto;">
            <table class="tabla--compacta">
                <thead>
                    <tr>
                        <th>Vigencia</th>
                        <th>Hacia atrás</th>
                        <th>Anticipación</th>
                        <th>Motivo</th>
                        <th>Cargado por</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($plazos as $plazo)
                        @php
                            $estado = $plazo->vigente_desde->greaterThan($hoy)
                                ? 'programado'
                                : ($plazo->is($plazoVigente) ? 'vigente' : 'anterior');
                        @endphp
                        <tr @class(['fila--inactiva' => $estado === 'anterior'])>
                            <td style="white-space: nowrap;">
                                <strong>{{ $plazo->vigente_desde->format('d/m/Y') }}</strong>
                                @if ($plazo->vigente_hasta)
                                    al <strong>{{ $plazo->vigente_hasta->format('d/m/Y') }}</strong>
                                @else
                                    <span class="ayuda">en adelante</span>
                                @endif
                                <div>
                                    @if ($estado === 'programado')
                                        <span class="pill pill--mini pill--info">Programado</span>
                                    @elseif ($estado === 'vigente')
                                        <span class="pill pill--mini pill--ok">Vigente</span>
                                    @else
                                        <span class="pill pill--mini pill--no">Anterior</span>
                                    @endif
                                </div>
                            </td>
                            <td>{{ $plazo->legible($plazo->dias_atraso) }}</td>
                            <td>{{ $plazo->legible($plazo->dias_anticipacion) }}</td>
                            <td style="max-width: 22rem;">{{ $plazo->motivo }}</td>
                            <td style="white-space: nowrap;">
                                {{ $plazo->registrador?->name ?? '—' }}
                                <div class="ayuda">{{ $plazo->updated_at?->format('d/m/Y H:i') }}</div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
