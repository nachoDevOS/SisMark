@extends('layouts.app')

@section('titulo', 'Licenciar')

@php
    /** Abreviatura del día de la semana, como la grilla del sistema de escritorio. */
    $abreviar = fn (?int $dia): string => mb_strtoupper(mb_substr(\App\Models\Turno::DIAS[$dia] ?? '—', 0, 3));
    $nombre = $persona['nombre'] ?? '';
    $modoInicial = old('modo', 'uno');
@endphp

@section('contenido')
    <div class="cabecera">
        <div class="cabecera__titulo">
            <span class="cabecera__icono"><x-heroicon-o-clipboard-document-check /></span>
            <h1>Licenciar</h1>
        </div>
        <a href="{{ route('licencias.index') }}" class="btn btn--gris"><x-heroicon-o-arrow-left />Volver</a>
    </div>

    @if ($errorMamore)
        <div class="aviso aviso--error">{{ $errorMamore }}</div>
    @elseif ($ciDesconocido)
        <div class="aviso aviso--error">El CI {{ $ci }} no figura en la API de Mamoré.</div>
    @endif

    {{-- `multipart/form-data`: el formulario sube el respaldo de la licencia.
         Sin esto el archivo no viaja y la validación lo rechaza. --}}
    <form action="{{ route('licencias.store') }}" method="POST" enctype="multipart/form-data"
          x-data="{
              modo: @js($modoInicial),
              completo: {{ old('tCompleto', 1) ? 'true' : 'false' }},
              porTurno: {{ old('asignaciones') !== null ? 'true' : 'false' }},
              elegidos: @js($elegidos),
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
                          const resp = await fetch(`{{ route('licencias.funcionarios') }}?q=${encodeURIComponent(texto)}`, { headers: { 'Accept': 'application/json' } });
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
              cerrar() { this.abierto = false; this.resultados = []; this.errorApi = ''; this.q = ''; },
              {{-- Un funcionario: se recarga con ?ci= para traer sus turnos del servidor. --}}
              elegirUno(item) { window.location = `{{ route('licencias.create') }}?ci=${encodeURIComponent(item.id)}`; },
              {{-- Varios: se agregan fichas del lado del cliente, sin recargar. --}}
              agregar(item) {
                  if (! this.elegidos.some((e) => e.id === item.id)) { this.elegidos.push(item); }
                  this.cerrar();
              },
              quitar(id) { this.elegidos = this.elegidos.filter((e) => e.id !== id); },
          }"
          x-on:click.outside="abierto = false">
        @csrf
        <input type="hidden" name="modo" :value="modo">

        {{-- Paso 1: a quiénes alcanza la licencia. --}}
        <div class="card card--padded">
            <h2 style="margin-top: 0;">¿A quién se licencia?</h2>

            <div class="toolbar" style="gap: 1.25rem; margin-bottom: .75rem;">
                <div class="campo check">
                    <input type="radio" id="modo-uno" value="uno" x-model="modo">
                    <label for="modo-uno" style="margin: 0;">Un funcionario</label>
                </div>
                <div class="campo check">
                    <input type="radio" id="modo-varios" value="varios" x-model="modo">
                    <label for="modo-varios" style="margin: 0;">Varios funcionarios</label>
                </div>
                <div class="campo check">
                    <input type="radio" id="modo-todos" value="todos" x-model="modo">
                    <label for="modo-todos" style="margin: 0;">Todos los que trabajen en el rango</label>
                </div>
            </div>

            @error('modo') <div class="error">{{ $message }}</div> @enderror
            @error('ci') <div class="error">{{ $message }}</div> @enderror
            @error('cis') <div class="error">{{ $message }}</div> @enderror

            {{-- Combo compartido por los modos «uno» y «varios». --}}
            <div class="campo" style="position: relative;" x-show="modo !== 'todos'" x-cloak>
                <label for="combo-funcionario">
                    <span x-show="modo === 'uno'">Empleado que requiere licencia</span>
                    <span x-show="modo === 'varios'" x-cloak>Agregar funcionarios a la lista</span>
                    <span class="req">*</span>
                </label>
                <input type="text" id="combo-funcionario" class="input" x-model="q"
                       x-on:input="buscar()"
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
                        <button type="button" x-on:click="modo === 'uno' ? elegirUno(item) : agregar(item)" x-text="item.texto"
                                style="display: block; width: 100%; text-align: left; padding: .5rem .7rem;
                                       background: none; border: 0; border-bottom: 1px solid var(--border);
                                       cursor: pointer; font: inherit;"
                                onmouseover="this.style.background='var(--bg)'" onmouseout="this.style.background='transparent'"></button>
                    </template>
                </div>
            </div>

            {{-- Modo «uno»: el funcionario ya cargado desde el servidor. --}}
            <div x-show="modo === 'uno'" x-cloak>
                @if ($persona)
                    <input type="hidden" name="ci" value="{{ $persona['ci'] }}">
                    <dl class="datos grid-2" style="margin: 0;">
                        <div>
                            <dt>Funcionario</dt>
                            <dd>{{ $nombre }} · CI {{ $persona['ci'] }}</dd>
                        </div>
                        <div>
                            <dt>Cargo</dt>
                            <dd>
                                {{ $persona['cargo'] ?: '—' }}
                                @if (!$persona['cargo'])
                                    <span class="pill pill--no">Sin contrato</span>
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt>Dirección administrativa</dt>
                            <dd>{{ $persona['direccion'] ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt>Profesión</dt>
                            <dd>{{ $persona['profesion'] ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt>Fecha de nacimiento</dt>
                            <dd>{{ $persona['nacimiento'] ?: '—' }}{{ is_null($persona['edad']) ? '' : ' · '.$persona['edad'].' años' }}</dd>
                        </div>
                        <div>
                            <dt>PIN reloj</dt>
                            <dd>{{ $persona['pinReloj'] ?: 'Sin PIN' }}</dd>
                        </div>
                    </dl>
                @else
                    <p class="vacio" style="margin: 0; padding: 1rem;">Elegí un funcionario para ver sus turnos asignados.</p>
                @endif
            </div>

            {{-- Modo «varios»: fichas de los elegidos. --}}
            <div x-show="modo === 'varios'" x-cloak>
                <template x-if="elegidos.length === 0">
                    <p class="vacio" style="margin: 0; padding: 1rem;">Todavía no agregaste ningún funcionario.</p>
                </template>
                <div style="display: flex; flex-wrap: wrap; gap: .4rem;">
                    <template x-for="elegido in elegidos" :key="elegido.id">
                        <span style="display: inline-flex; align-items: center; gap: .4rem; padding: .3rem .6rem;
                                     background: var(--bg); border: 1px solid var(--border); border-radius: 9999px; font-size: .78rem;">
                            <input type="hidden" name="cis[]" :value="elegido.id">
                            <span x-text="elegido.texto"></span>
                            <button type="button" x-on:click="quitar(elegido.id)" aria-label="Quitar"
                                    style="border: 0; background: none; cursor: pointer; color: var(--danger); font: inherit; line-height: 1;">&times;</button>
                        </span>
                    </template>
                </div>
                <p class="ayuda" x-show="elegidos.length > 0" x-cloak>
                    <span x-text="elegidos.length"></span> funcionario(s) en la lista.
                </p>
            </div>

            {{-- Modo «todos»: sin selección, el rango define el alcance. --}}
            <div x-show="modo === 'todos'" x-cloak>
                <div class="aviso">
                    Se licencia a <strong>todos los funcionarios que tengan un turno asignado dentro del rango</strong>
                    de fechas elegido. Es el modo para feriados y tolerancias generales.
                    A cada uno se le anota según su propio horario.
                </div>
            </div>
        </div>

        {{-- Paso 2: turnos del funcionario (solo cuando hay uno cargado). --}}
        @if ($persona)
            <div class="card card--padded" style="margin-top: 1rem;" x-show="modo === 'uno'" x-cloak>
                <div class="cabecera" style="margin: 0 0 .75rem;">
                    <h2 style="margin: 0;">
                        Turnos {{ $incluirVencidos ? '' : 'vigentes' }} de {{ $nombre ?: 'el funcionario' }}
                    </h2>
                    @if ($incluirVencidos)
                        <a class="btn btn--gris" href="{{ route('licencias.create', ['ci' => $ci]) }}">
                            <x-heroicon-o-funnel />Ver solo los vigentes
                        </a>
                    @elseif ($vencidos > 0)
                        <a class="btn btn--gris" href="{{ route('licencias.create', ['ci' => $ci, 'vencidos' => 1]) }}">
                            <x-heroicon-o-clock />Ver también los {{ $vencidos }} vencido(s)
                        </a>
                    @endif
                </div>

                @if ($asignaciones->isEmpty())
                    <div class="aviso aviso--error">
                        @if ($vencidos > 0 && ! $incluirVencidos)
                            El funcionario no tiene turnos vigentes; sí tiene {{ $vencidos }} vencido(s).
                            <a href="{{ route('licencias.create', ['ci' => $ci, 'vencidos' => 1]) }}">Mostrarlos</a>
                            para anotar una licencia retroactiva.
                        @else
                            El funcionario no tiene turnos asignados: no se le puede anotar una licencia.
                        @endif
                    </div>
                @else
                    <p class="ayuda" style="margin-top: 0;">
                        El rango de fechas define qué días se licencian: se anota una licencia por cada
                        día del rango en que el funcionario tenga turno. No hace falta marcar nada.
                    </p>

                    @error('asignaciones') <div class="error">{{ $message }}</div> @enderror

                    <table>
                        <thead>
                            <tr>
                                <th style="width: 2.5rem;" x-show="porTurno" x-cloak></th>
                                <th>Día</th>
                                <th>Turno</th>
                                <th>Entrada</th>
                                <th>Salida</th>
                                <th>Día siguiente</th>
                                <th>Vigencia</th>
                                <th>Estado</th>
                                <th>Horas trabajadas</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($asignaciones as $asignacion)
                                @php
                                    $turno = $asignacion->turno;
                                    $vencida = (bool) $asignacion->hasta?->isPast();
                                    $futura = (bool) $asignacion->desde?->isFuture();
                                    // Sin envío previo van todos marcados: al abrir la
                                    // selección manual arranca igual que el automático.
                                    $marcada = old('asignaciones') === null
                                        || in_array((string) $asignacion->id, (array) old('asignaciones', []), true);
                                @endphp
                                <tr @class(['fila--inactiva' => $vencida])>
                                    <td x-show="porTurno" x-cloak>
                                        {{-- Deshabilitado fuera del modo manual: así no viaja ninguna
                                             asignación y el servidor resuelve los turnos por el rango. --}}
                                        <input type="checkbox" name="asignaciones[]" value="{{ $asignacion->id }}"
                                               @checked($marcada) :disabled="! porTurno || modo !== 'uno'"
                                               aria-label="Elegir turno {{ $turno->nombreTurno }}">
                                    </td>
                                    <td>{{ $abreviar((int) $turno->dia) }}</td>
                                    <td>{{ $turno->nombreTurno }}</td>
                                    <td>{{ $turno->hEntrada?->format('H:i') ?? '—' }}</td>
                                    <td>{{ $turno->hSalida?->format('H:i') ?? '—' }}</td>
                                    <td>{{ $turno->siguienteDia ? 'Sí' : '' }}</td>
                                    <td>
                                        {{ $asignacion->desde?->format('d/m/Y') ?? '—' }} al
                                        {{ $asignacion->hasta?->format('d/m/Y') ?? '—' }}
                                    </td>
                                    <td>
                                        @if ($vencida)
                                            <span class="pill pill--no">Vencido</span>
                                        @elseif ($futura)
                                            <span class="pill pill--advertencia">Futuro</span>
                                        @else
                                            <span class="pill pill--ok">Vigente</span>
                                        @endif
                                    </td>
                                    <td>{{ rtrim(rtrim(number_format((float) $turno->hTrabajadas, 2, '.', ''), '0'), '.') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <div class="campo check" style="margin: .75rem 0 0;">
                        <input type="checkbox" id="porTurno" x-model="porTurno">
                        <label for="porTurno" style="margin: 0;">
                            Licenciar solo algunos turnos
                            <span class="ayuda" style="font-weight: 400;">
                                (para quien tiene doble turno en un día y falta solo a uno de ellos)
                            </span>
                        </label>
                    </div>
                @endif
            </div>
        @endif

        {{-- Paso 3: rango, alcance del día y motivo. --}}
        <div class="card card--padded" style="margin-top: 1rem;">
            <div class="toolbar" style="align-items: flex-end;">
                <div class="campo">
                    <label for="desde">Desde <span class="req">*</span></label>
                    <input type="date" id="desde" name="desde" class="input"
                           value="{{ old('desde', now()->toDateString()) }}" required>
                    @error('desde') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="campo">
                    <label for="hasta">Hasta <span class="req">*</span></label>
                    <input type="date" id="hasta" name="hasta" class="input"
                           value="{{ old('hasta', now()->toDateString()) }}" required>
                    @error('hasta') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="campo">
                    <label for="lEntra">Hora entrada</label>
                    <input type="time" id="lEntra" name="lEntra" class="input"
                           value="{{ old('lEntra') }}" :disabled="completo">
                    @error('lEntra') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="campo">
                    <label for="lSale">Hora salida</label>
                    <input type="time" id="lSale" name="lSale" class="input"
                           value="{{ old('lSale') }}" :disabled="completo">
                    @error('lSale') <div class="error">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="toolbar" style="align-items: flex-end; margin-top: .5rem;">
                <div class="campo check">
                    <input type="checkbox" id="tCompleto" name="tCompleto" value="1" x-model="completo">
                    <label for="tCompleto" style="margin: 0;">Turno completo</label>
                </div>
                <div class="campo check">
                    <input type="checkbox" id="goceHaberes" name="goceHaberes" value="1" @checked(old('goceHaberes', 1))>
                    <label for="goceHaberes" style="margin: 0;">Con goce de haberes</label>
                </div>
                <div class="campo" style="flex: 1; min-width: 14rem;">
                    <label for="motivo">Motivo de la ausencia <span class="req">*</span></label>
                    <input type="text" id="motivo" name="motivo" class="input" maxlength="255"
                           value="{{ old('motivo') }}" required>
                    @error('motivo') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="campo" style="flex: 1; min-width: 14rem;">
                    <label for="respaldo">Respaldo <span class="req">*</span></label>
                    <input type="file" id="respaldo" name="respaldo" class="input"
                           accept=".jpg,.jpeg,.png,.pdf" required>
                    <p class="ayuda">
                        Certificado, memorándum o nota que justifica la ausencia.
                        Imagen (JPG o PNG) o PDF, hasta 5 MB.
                    </p>
                    @error('respaldo') <div class="error">{{ $message }}</div> @enderror
                </div>
            </div>

            <p class="ayuda">
                Si el empleado no marcará su asistencia, dejá «Turno completo».
                Si llegará tarde o se irá temprano, desmarcalo y definí las horas de entrada y salida.
            </p>

            <div class="form-acciones">
                <button type="submit" class="btn"
                        :disabled="(modo === 'uno' && ! {{ $persona ? 'true' : 'false' }}) || (modo === 'varios' && elegidos.length === 0)">
                    <x-heroicon-o-check />Anotar licencia(s)
                </button>
                <a href="{{ route('licencias.index') }}" class="btn btn--gris"><x-heroicon-o-x-mark />Cancelar</a>
            </div>
        </div>
    </form>
@endsection
