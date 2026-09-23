@props([
    'ci',
    'origen' => '',
    'etiqueta' => 'Asignar turno',
])

@php
    $ciFijo = trim((string) $ci);
    $sufijo = 'ci'.preg_replace('/\W/', '', $ciFijo);
    // Varios formularios de la ficha comparten nombres de campo (`desde`,
    // `hasta`…): el marcador dice cuál rebotó, para reabrir solo ese modal.
    $abierto = old('_form') === 'turno-asignado' ? 'true' : 'false';
    $turnos = \App\Models\Turno::query()->ordenado()->get();
    // El selector es un listado de tarjetas, no un <select>: un turno se elige
    // por su horario, y eso no entra en el texto de un `option`.
    $opciones = $turnos->map(fn ($turno): array => [
        'id' => $turno->id,
        'dia' => (int) $turno->dia,
        'abrev' => mb_strtoupper(mb_substr($turno->nombre_dia, 0, 3)),
        'nombreDia' => mb_strtolower((string) $turno->nombre_dia),
        'nombre' => trim((string) $turno->nombreTurno),
        'entrada' => $turno->hEntrada?->format('H:i') ?? '—',
        'salida' => $turno->hSalida?->format('H:i') ?? '—',
        'tolerancia' => $turno->hTolerancia?->format('H:i'),
        'horas' => rtrim(rtrim(number_format((float) $turno->hTrabajadas, 2, '.', ''), '0'), '.'),
        'siguienteDia' => (bool) $turno->siguienteDia,
        // Texto contra el que corre el buscador: sin tildes y en minúsculas, así
        // «miercoles» encuentra a «Miércoles» y «8:00» no depende del formato.
        'busca' => \Illuminate\Support\Str::lower(\Illuminate\Support\Str::ascii(
            $turno->nombre_dia.' '.$turno->nombreTurno.' '
            .$turno->hEntrada?->format('H:i').' '.$turno->hSalida?->format('H:i')
        )),
    ])->values();
    // Solo los días que tienen turnos cargados: un filtro vacío no aporta.
    $diasConTurnos = collect(\App\Models\Turno::DIAS)
        ->filter(fn (string $nombre, int $numero): bool => $opciones->contains('dia', $numero));
@endphp

{{-- Asignarle un turno a un funcionario ya conocido, sin salir de su ficha. El
     formulario de `turnos-asignados/create` sigue existiendo para cuando se
     entra por el listado y hay que elegir a la persona. --}}
@can('create', \App\Models\AsignacionTurno::class)
    <div x-data="{
             abierto: {{ $abierto }},
             dia: '',
             buscar: '',
             turnoId: @js(old('turno_id', '')),
             turnos: @js($opciones),
             abrir() {
                 this.abierto = true;
                 // El buscador toma el foco: lo normal es tipear el horario o el
                 // día apenas se abre, sin tener que ir al campo con el mouse.
                 this.$nextTick(() => this.$refs.buscador?.focus());
             },
             get filtrados() {
                 const termino = this.buscar.trim().toLowerCase()
                     .normalize('NFD').replace(new RegExp('[\u0300-\u036f]', 'g'), '');

                 return this.turnos.filter((t) => (this.dia === '' || t.dia === Number(this.dia))
                     && (termino === '' || t.busca.includes(termino)));
             },
             get elegido() {
                 return this.turnos.find((t) => String(t.id) === String(this.turnoId)) ?? null;
             },
         }" {{ $attributes }}>
        <button type="button" class="btn" x-on:click="abrir()">
            <x-heroicon-o-plus />{{ $etiqueta }}
        </button>

        <div class="modal-fondo" x-show="abierto" x-cloak
             x-on:click.self="abierto = false" x-on:keydown.escape.window="abierto = false">
            <div class="modal-caja modal-caja--ancha modal-caja--turnos">
                <h2>Asignar turno a CI {{ $ciFijo }}</h2>

                <form method="POST" action="{{ route('turnos-asignados.store') }}">
                    @csrf
                    <input type="hidden" name="_form" value="turno-asignado">
                    <input type="hidden" name="ci" value="{{ $ciFijo }}">
                    <input type="hidden" name="origen" value="{{ $origen }}">
                    @error('ci') <div class="error">{{ $message }}</div> @enderror

                    <div class="campo">
                        <label id="turno-etiqueta-{{ $sufijo }}">Turno <span class="req">*</span></label>

                        @if ($turnos->isEmpty())
                            <div class="aviso aviso--advertencia">
                                No hay turnos cargados todavía.
                                @can('create', \App\Models\Turno::class)
                                    <a href="{{ route('horarios.create') }}">Creá uno primero</a>.
                                @endcan
                            </div>
                        @else
                            <div class="turno-picker" role="radiogroup" aria-labelledby="turno-etiqueta-{{ $sufijo }}">
                                {{-- Buscador por nombre, día u hora. `keydown.enter.prevent`
                                     porque un Enter acá mandaría el formulario entero. --}}
                                <div class="turno-picker__buscador">
                                    <x-heroicon-o-magnifying-glass />
                                    <input type="search" x-ref="buscador" x-model="buscar"
                                           placeholder="Buscar por turno, día u hora (ej. 08:00, lunes)"
                                           aria-label="Buscar turno"
                                           x-on:keydown.enter.prevent>
                                    <button type="button" class="turno-picker__limpiar" x-show="buscar" x-cloak
                                            x-on:click="buscar = ''; $refs.buscador.focus()"
                                            title="Limpiar" aria-label="Limpiar búsqueda">
                                        <x-heroicon-o-x-mark />
                                    </button>
                                </div>

                                {{-- Filtro por día: con 46 turnos cargados, mirar los del día
                                     que interesa es más rápido que recorrer la lista entera. --}}
                                <div class="turno-picker__dias">
                                    <button type="button" class="chip" :class="{ 'chip--activo': dia === '' }"
                                            x-on:click="dia = ''">Todos</button>
                                    @foreach ($diasConTurnos as $numero => $nombreDia)
                                        <button type="button" class="chip" :class="{ 'chip--activo': dia === '{{ $numero }}' }"
                                                x-on:click="dia = '{{ $numero }}'">{{ $nombreDia }}</button>
                                    @endforeach
                                    <span class="turno-picker__conteo" aria-live="polite"
                                          x-text="filtrados.length === 1 ? '1 turno' : filtrados.length + ' turnos'"></span>
                                </div>

                                <div class="turno-picker__lista">
                                    <template x-for="turno in filtrados" :key="turno.id">
                                        <label class="turno-opcion" :class="{ 'turno-opcion--elegido': String(turnoId) === String(turno.id) }">
                                            <input type="radio" name="turno_id" :value="turno.id" x-model="turnoId" required>
                                            <span class="turno-opcion__cuerpo">
                                                <span class="turno-opcion__titulo">
                                                    <span class="turno-opcion__dia" x-text="turno.abrev"></span>
                                                    <span x-text="turno.nombre"></span>
                                                </span>
                                                <span class="turno-opcion__horario">
                                                    <span x-text="turno.entrada"></span> → <span x-text="turno.salida"></span>
                                                </span>
                                                <span class="turno-opcion__meta">
                                                    <span x-text="turno.horas + ' h de jornada'"></span>
                                                    <template x-if="turno.tolerancia">
                                                        <span x-text="'tolerancia hasta ' + turno.tolerancia"></span>
                                                    </template>
                                                    <template x-if="turno.siguienteDia">
                                                        <span class="pill pill--advertencia">Sale al día siguiente</span>
                                                    </template>
                                                </span>
                                            </span>
                                        </label>
                                    </template>

                                    <template x-if="filtrados.length === 0">
                                        <p class="vacio" style="margin: 0;"
                                           x-text="buscar ? 'Ningún turno coincide con «' + buscar + '».' : 'No hay turnos cargados para ese día.'"></p>
                                    </template>
                                </div>
                            </div>

                            <p class="ayuda" x-show="! elegido">Elegí el turno que va a cumplir el funcionario.</p>
                            <p class="turno-elegido" x-show="elegido" x-cloak>
                                <x-heroicon-o-check-circle />
                                <span>
                                    Se le asigna <strong x-text="elegido?.nombre"></strong>,
                                    de <strong x-text="elegido?.entrada"></strong> a <strong x-text="elegido?.salida"></strong>,
                                    los <span x-text="elegido?.nombreDia"></span>.
                                </span>
                            </p>
                        @endif

                        @error('turno_id') <div class="error">{{ $message }}</div> @enderror
                    </div>

                    <div class="grid-2">
                        <div class="campo">
                            <label for="turno-desde-{{ $sufijo }}">Desde <span class="req">*</span></label>
                            <input type="date" id="turno-desde-{{ $sufijo }}" name="desde"
                                   value="{{ old('desde', now()->toDateString()) }}" required>
                            @error('desde') <div class="error">{{ $message }}</div> @enderror
                        </div>
                        <div class="campo">
                            <label for="turno-hasta-{{ $sufijo }}">Hasta <span class="req">*</span></label>
                            <input type="date" id="turno-hasta-{{ $sufijo }}" name="hasta"
                                   value="{{ old('hasta', now()->endOfYear()->toDateString()) }}" required>
                            @error('hasta') <div class="error">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div class="campo">
                        <label for="turno-obs-{{ $sufijo }}">Observación</label>
                        <input type="text" id="turno-obs-{{ $sufijo }}" name="observacion" maxlength="1000"
                               value="{{ old('observacion') }}">
                        @error('observacion') <div class="error">{{ $message }}</div> @enderror
                    </div>

                    <div class="modal-acciones">
                        <button type="button" class="btn btn--gris" x-on:click="abierto = false">
                            <x-heroicon-o-x-mark />Cancelar
                        </button>
                        <button type="submit" class="btn"><x-heroicon-o-check />Asignar turno</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endcan
