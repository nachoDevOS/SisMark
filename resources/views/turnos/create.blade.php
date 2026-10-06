@extends('layouts.app')

@section('titulo', 'Nuevo turno')

@php
    // Catálogo de horarios para el selector: viaja una vez y se filtra por día
    // en el navegador, sin idas y vueltas al servidor.
    $catalogo = $horarios->map(fn ($horario) => [
        'id' => $horario->id,
        'dia' => (int) $horario->dia,
        'diaNombre' => $horario->nombre_dia,
        'nombre' => trim((string) $horario->nombreHorario),
        'entrada' => $horario->hEntrada?->format('H:i'),
        'salida' => $horario->hSalida?->format('H:i'),
        'tolEntrada' => $horario->hTolerancia?->format('H:i'),
        'tolSalida' => $horario->sTolerancia?->format('H:i'),
        'eMinima' => $horario->eMinima?->format('H:i'),
        'eMaxima' => $horario->eMaxima?->format('H:i'),
        'sMinima' => $horario->sMinima?->format('H:i'),
        'sMaxima' => $horario->sMaxima?->format('H:i'),
        'horas' => (float) $horario->hTrabajadas,
        'siguienteDia' => (bool) $horario->siguienteDia,
    ])->values();
@endphp

@section('contenido')
    <div class="cabecera">
        <div class="cabecera__titulo">
            <span class="cabecera__icono"><x-heroicon-o-rectangle-stack /></span>
            <h1>Nuevo turno</h1>
        </div>
        <a href="{{ route('turnos.index') }}" class="btn btn--gris"><x-heroicon-o-arrow-left />Volver</a>
    </div>

    <form action="{{ route('turnos.store') }}" method="POST"
          x-data="{
              catalogo: @js($catalogo),
              elegidos: @js(array_map('intval', (array) old('horarioIds', []))),
              dia: '',
              filtro: '',
              minutos(hora) {
                  const [h, m] = (hora || '00:00').split(':').map(Number);
                  return h * 60 + m;
              },
              // Tramo del horario en minutos desde el domingo 00:00. Misma cuenta
              // que StoreTurnoRequest, que es quien decide de verdad.
              tramo(h) {
                  const base = (h.dia - 1) * 1440;
                  const inicio = base + this.minutos(h.entrada);
                  let fin = base + this.minutos(h.salida);
                  if (h.siguienteDia || fin <= inicio) { fin += 1440; }
                  return [inicio, fin];
              },
              choqueCon(h) {
                  const semana = 7 * 1440;
                  const [i1, f1] = this.tramo(h);
                  return this.filas.find(otro => {
                      const [i2, f2] = this.tramo(otro);
                      return [0, semana, -semana].some(c => i1 < f2 + c && i2 + c < f1);
                  }) || null;
              },
              get disponibles() {
                  const texto = this.filtro.trim().toLowerCase();
                  return this.catalogo.filter(h => String(h.dia) === String(this.dia)
                      && ! this.elegidos.includes(h.id)
                      && (texto === '' || `${h.nombre} ${h.entrada} ${h.salida}`.toLowerCase().includes(texto)));
              },
              get filas() {
                  return this.catalogo
                      .filter(h => this.elegidos.includes(h.id))
                      .sort((a, b) => a.dia - b.dia || a.entrada.localeCompare(b.entrada));
              },
              get horas() {
                  return this.filas.reduce((total, h) => total + h.horas, 0);
              },
              agregar(h) {
                  if (this.elegidos.includes(h.id) || this.choqueCon(h)) { return; }
                  this.elegidos.push(h.id);
              },
              quitar(id) {
                  this.elegidos = this.elegidos.filter(e => e !== id);
              },
          }">
        @csrf

        <div class="card card--padded">
            <h2 style="margin-top: 0;">Datos del turno</h2>

            <div class="campo">
                <label for="nombre">Nombre del turno <span class="req">*</span></label>
                <input type="text" id="nombre" name="nombre" class="input" maxlength="60" required
                       value="{{ old('nombre') }}" placeholder="Ej.: Administrativo L-V 08:00 - 16:00">
                @error('nombre') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="campo">
                <label for="observacion">Observación</label>
                <input type="text" id="observacion" name="observacion" class="input" maxlength="255"
                       value="{{ old('observacion') }}">
                @error('observacion') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="campo check" style="margin-bottom: 0;">
                <input type="checkbox" id="sugerido" name="sugerido" value="1" @checked(old('sugerido'))>
                <label for="sugerido" style="margin: 0;">
                    Turno sugerido
                    <small style="display: block; font-weight: 400; color: #6b7280;">
                        Se ofrece en Mamoré para elegir al dar de alta un contrato.
                    </small>
                </label>
            </div>
        </div>

        <div class="card card--padded" style="margin-top: 1rem;">
            <h2 style="margin-top: 0;">Horarios del turno</h2>
            <p class="ayuda">
                Un mismo día puede tener más de un horario (mañana y tarde) siempre que no se pisen.
                <strong>El turno no se edita después</strong>: para cambiar la jornada se crea otro.
            </p>

            <template x-for="id in elegidos" :key="id">
                <input type="hidden" name="horarioIds[]" :value="id">
            </template>

            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Día</th>
                            <th>Entrada</th>
                            <th>Salida</th>
                            <th>Tolerancia</th>
                            <th>Marca entrada</th>
                            <th>Marca salida</th>
                            <th>Horas</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="h in filas" :key="h.id">
                            <tr>
                                <td><strong x-text="h.diaNombre"></strong></td>
                                <td x-text="h.entrada"></td>
                                <td>
                                    <span x-text="h.salida"></span>
                                    <span class="pill pill--advertencia" x-show="h.siguienteDia" title="La salida es al día siguiente">+1 día</span>
                                </td>
                                <td x-text="`${h.tolEntrada} / ${h.tolSalida}`"></td>
                                <td x-text="`${h.eMinima} – ${h.eMaxima}`"></td>
                                <td x-text="`${h.sMinima} – ${h.sMaxima}`"></td>
                                <td x-text="h.horas.toFixed(2)"></td>
                                <td>
                                    <button type="button" class="btn-icon btn-icon--gris" title="Quitar" aria-label="Quitar"
                                            x-on:click="quitar(h.id)"><x-heroicon-o-x-mark /></button>
                                </td>
                            </tr>
                        </template>
                        <tr x-show="filas.length === 0">
                            <td colspan="8" class="vacio">Todavía no agregaste horarios. Elegí un día abajo.</td>
                        </tr>
                    </tbody>
                    <tfoot x-show="filas.length > 0">
                        <tr>
                            <th colspan="6" style="text-align: right;">Horas semanales</th>
                            <th x-text="horas.toFixed(2)"></th>
                            <th></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
            @error('horarioIds') <div class="error">{{ $message }}</div> @enderror
            @error('horarioIds.*') <div class="error">{{ $message }}</div> @enderror
        </div>

        <div class="card card--padded" style="margin-top: 1rem;">
            <h2 style="margin-top: 0;">Agregar horarios</h2>

            {{-- Un botón por día: muestra cuántos horarios ya tiene ese día en el turno. --}}
            <div style="display: flex; flex-wrap: wrap; gap: .4rem; margin-bottom: .75rem;">
                @foreach (\App\Models\Horario::DIAS as $numero => $nombreDia)
                    <button type="button" class="btn"
                            :class="String(dia) === '{{ $numero }}' ? '' : 'btn--gris'"
                            x-on:click="dia = '{{ $numero }}'; filtro = ''">
                        {{ $nombreDia }}
                        <span class="pill pill--info" x-show="filas.filter(h => h.dia === {{ $numero }}).length"
                              x-text="filas.filter(h => h.dia === {{ $numero }}).length"></span>
                    </button>
                @endforeach
            </div>

            <p class="vacio" style="margin: 0;" x-show="! dia">Elegí un día para ver sus horarios.</p>

            <div x-show="dia" x-cloak>
                <div class="buscador" style="margin-bottom: .5rem; max-width: 22rem;">
                    <x-heroicon-o-magnifying-glass />
                    <input type="text" x-model="filtro" placeholder="Filtrar por hora (ej. 08:00)…">
                </div>
                <p class="ayuda" style="margin: 0 0 .5rem;">
                    <span x-text="disponibles.length"></span> horario(s) disponible(s).
                    <strong>Marca entrada / salida</strong> = desde y hasta qué hora el reloj toma la marca.
                </p>

                <div style="max-height: 24rem; overflow: auto; border: 1px solid var(--border); border-radius: .4rem;">
                    <table>
                        <thead style="position: sticky; top: 0; z-index: 1;">
                            <tr>
                                <th>Entrada</th>
                                <th>Salida</th>
                                <th>Tolerancia</th>
                                <th>Marca entrada</th>
                                <th>Marca salida</th>
                                <th>Horas</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="h in disponibles" :key="h.id">
                                <tr :style="choqueCon(h) ? 'opacity: .55;' : ''">
                                    <td><strong x-text="h.entrada"></strong></td>
                                    <td>
                                        <strong x-text="h.salida"></strong>
                                        <span class="pill pill--advertencia" x-show="h.siguienteDia" title="La salida es al día siguiente">+1 día</span>
                                    </td>
                                    <td x-text="`${h.tolEntrada} / ${h.tolSalida}`"></td>
                                    <td x-text="`${h.eMinima} – ${h.eMaxima}`"></td>
                                    <td x-text="`${h.sMinima} – ${h.sMaxima}`"></td>
                                    <td x-text="h.horas.toFixed(2)"></td>
                                    <td style="white-space: nowrap;">
                                        <template x-if="! choqueCon(h)">
                                            <button type="button" class="btn" x-on:click="agregar(h)">
                                                <x-heroicon-o-plus />Agregar
                                            </button>
                                        </template>
                                        <template x-if="choqueCon(h)">
                                            <small style="color: var(--muted);"
                                                   x-text="`Se pisa con ${choqueCon(h).diaNombre} ${choqueCon(h).entrada}–${choqueCon(h).salida}`"></small>
                                        </template>
                                    </td>
                                </tr>
                            </template>
                            <tr x-show="disponibles.length === 0">
                                <td colspan="7" class="vacio">No hay horarios que coincidan para este día.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            @if ($horarios->isEmpty())
                <div class="ayuda">
                    No hay horarios cargados todavía.
                    @can('create', \App\Models\Horario::class)
                        <a href="{{ route('horarios.create') }}">Creá uno primero</a>.
                    @endcan
                </div>
            @endif

            <div class="form-acciones">
                <button type="submit" class="btn" :disabled="elegidos.length === 0"><x-heroicon-o-check />Guardar turno</button>
                <a href="{{ route('turnos.index') }}" class="btn btn--gris"><x-heroicon-o-x-mark />Cancelar</a>
            </div>
        </div>
    </form>
@endsection
