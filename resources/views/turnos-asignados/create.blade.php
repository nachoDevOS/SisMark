@extends('layouts.app')

@section('titulo', 'Asignar turno')

@php
    $abreviar = fn (?int $dia): string => mb_strtoupper(mb_substr(\App\Models\Turno::DIAS[$dia] ?? '—', 0, 3));
    $turnosPorDia = $turnos->groupBy(fn ($turno) => (int) $turno->dia);
@endphp

@section('contenido')
    <div class="cabecera">
        <div class="cabecera__titulo">
            <span class="cabecera__icono"><x-heroicon-o-clock /></span>
            <h1>Asignar turno</h1>
        </div>
        <a href="{{ route('turnos-asignados.index') }}" class="btn btn--gris"><x-heroicon-o-arrow-left />Volver</a>
    </div>

    <form action="{{ route('turnos-asignados.store') }}" method="POST"
          x-data="{
              ci: @js(old('ci', $ci)),
              etiqueta: @js($ficha ? trim($ci.' — '.($ficha['nombre'] ?? '')).($ficha['cargo'] ? ' · '.$ficha['cargo'] : '') : ''),
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
                          const resp = await fetch(`{{ route('turnos-asignados.funcionarios') }}?q=${encodeURIComponent(texto)}`, { headers: { 'Accept': 'application/json' } });
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
              elegir(item) {
                  this.ci = item.id;
                  this.etiqueta = item.texto;
                  this.abierto = false;
                  this.resultados = [];
                  this.q = '';
              },
          }"
          x-on:click.outside="abierto = false">
        @csrf
        <input type="hidden" name="ci" :value="ci">
        <input type="hidden" name="origen" value="{{ $origen }}">

        <div class="card card--padded">
            <h2 style="margin-top: 0;">¿A quién se le asigna?</h2>

            {{-- Con el funcionario ya elegido (se entró desde su ficha) el combo
                 sirve para cambiarlo, pero no hace falta usarlo. --}}
            <div class="campo" style="position: relative;">
                <label for="combo-funcionario">Funcionario <span class="req">*</span></label>
                <input type="text" id="combo-funcionario" class="input" x-model="q" x-on:input="buscar()"
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
                        <button type="button" x-on:click="elegir(item)" x-text="item.texto"
                                style="display: block; width: 100%; text-align: left; padding: .5rem .7rem;
                                       background: none; border: 0; border-bottom: 1px solid var(--border);
                                       cursor: pointer; font: inherit;"
                                onmouseover="this.style.background='var(--bg)'" onmouseout="this.style.background='transparent'"></button>
                    </template>
                </div>
            </div>

            <p class="vacio" style="margin: 0; padding: .75rem;" x-show="! ci" x-cloak>
                Todavía no elegiste un funcionario.
            </p>
            <dl class="datos" style="margin: 0;" x-show="ci" x-cloak>
                <dt>Funcionario elegido</dt>
                <dd x-text="etiqueta || ci"></dd>
            </dl>

            @error('ci') <div class="error">{{ $message }}</div> @enderror
        </div>

        <div class="card card--padded" style="margin-top: 1rem;">
            <h2 style="margin-top: 0;">Turno y vigencia</h2>

            <div class="campo">
                <label for="turno_id">Turno <span class="req">*</span></label>
                <select id="turno_id" name="turno_id" class="input" required>
                    <option value="">Seleccione un turno</option>
                    @foreach (\App\Models\Turno::DIAS as $numero => $nombreDia)
                        @if ($turnosPorDia->has($numero))
                            <optgroup label="{{ $nombreDia }}">
                                @foreach ($turnosPorDia[$numero] as $turno)
                                    <option value="{{ $turno->id }}" @selected((string) old('turno_id') === (string) $turno->id)>
                                        {{ $abreviar((int) $turno->dia) }} · {{ trim((string) $turno->nombreTurno) }}
                                        ({{ $turno->hEntrada?->format('H:i') }} – {{ $turno->hSalida?->format('H:i') }})
                                    </option>
                                @endforeach
                            </optgroup>
                        @endif
                    @endforeach
                </select>
                @if ($turnos->isEmpty())
                    <div class="ayuda">
                        No hay turnos cargados todavía.
                        @can('create', \App\Models\Turno::class)
                            <a href="{{ route('horarios.create') }}">Creá uno primero</a>.
                        @endcan
                    </div>
                @endif
                @error('turno_id') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="grid-2">
                <div class="campo">
                    <label for="desde">Desde <span class="req">*</span></label>
                    <input type="date" id="desde" name="desde" class="input"
                           value="{{ old('desde', now()->toDateString()) }}" required>
                    @error('desde') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="campo">
                    <label for="hasta">Hasta <span class="req">*</span></label>
                    <input type="date" id="hasta" name="hasta" class="input"
                           value="{{ old('hasta', now()->endOfYear()->toDateString()) }}" required>
                    @error('hasta') <div class="error">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="campo">
                <label for="observacion">Observación</label>
                <input type="text" id="observacion" name="observacion" class="input" maxlength="1000"
                       value="{{ old('observacion') }}">
                @error('observacion') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="form-acciones">
                <button type="submit" class="btn" :disabled="! ci"><x-heroicon-o-check />Asignar turno</button>
                <a href="{{ route('turnos-asignados.index') }}" class="btn btn--gris"><x-heroicon-o-x-mark />Cancelar</a>
            </div>
        </div>
    </form>
@endsection
