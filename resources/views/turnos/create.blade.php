@extends('layouts.app')

@section('titulo', 'Nuevo turno')

@php
    // Si la validación rebota, las filas vuelven tal cual; si no, arranca vacío
    // y se cargan con la carga rápida del paso 2.
    $filas = collect(old('horarios', []))
        ->map(fn ($fila) => [
            'dia' => (string) ($fila['dia'] ?? ''),
            'hEntrada' => $fila['hEntrada'] ?? '',
            'hTolerancia' => $fila['hTolerancia'] ?? '',
            'eMinima' => $fila['eMinima'] ?? '',
            'eMaxima' => $fila['eMaxima'] ?? '',
            'hSalida' => $fila['hSalida'] ?? '',
            'sTolerancia' => $fila['sTolerancia'] ?? '',
            'sMinima' => $fila['sMinima'] ?? '',
            'sMaxima' => $fila['sMaxima'] ?? '',
        ])
        ->values();

    $erroresHorarios = collect($errors->get('horarios'))->merge(collect($errors->get('horarios.*'))->flatten());
@endphp

@section('contenido')
    <style>
        .paso { display: flex; align-items: center; gap: .6rem; margin: 0 0 .35rem; }
        .paso__numero { display: inline-flex; align-items: center; justify-content: center; width: 1.75rem; height: 1.75rem;
            border-radius: 9999px; background: var(--verde); color: #fff; font-weight: 700; font-size: .85rem; flex-shrink: 0; }
        .paso h2 { margin: 0; font-size: 1.05rem; }
        .paso-ayuda { margin: 0 0 1rem 2.35rem; }

        .dias-chips { display: flex; flex-wrap: wrap; gap: .4rem; }
        .dia-chip { min-width: 3.1rem; padding: .5rem .7rem; border-radius: .5rem; border: 1.5px solid var(--border);
            background: var(--card); color: var(--fg); font-weight: 700; cursor: pointer; transition: all .12s; }
        .dia-chip:hover { border-color: var(--verde); }
        .dia-chip--activo { background: var(--verde); border-color: var(--verde); color: #fff; }
        .atajos { display: flex; flex-wrap: wrap; gap: .35rem; margin-top: .5rem; }
        .atajo { background: none; border: 0; padding: .15rem .3rem; color: var(--verde-osc); font-size: .75rem;
            font-weight: 600; cursor: pointer; text-decoration: underline; }

        .carga-rapida { display: grid; grid-template-columns: 1fr auto; gap: 1rem; align-items: end; }
        .carga-rapida__horas { display: flex; gap: .75rem; align-items: end; flex-wrap: wrap; }
        .carga-rapida__horas .campo { margin: 0; min-width: 8rem; }
        .vista-previa { margin-top: .85rem; padding: .6rem .8rem; border-radius: .5rem; background: #f0fdf4;
            border: 1px solid #bbf7d0; color: #166534; font-size: .8rem; }
        .vista-previa--aviso { background: #fffbeb; border-color: #fde68a; color: #92400e; }

        .semana { border: 1px solid var(--border); border-radius: .625rem; overflow: hidden; }
        .semana__dia { display: grid; grid-template-columns: 7.5rem 1fr; border-top: 1px solid var(--border); }
        .semana__dia:first-child { border-top: 0; }
        .semana__nombre { padding: .75rem .9rem; font-weight: 700; background: var(--bg); display: flex; align-items: center; }
        .semana__dia--libre .semana__nombre { color: var(--muted); font-weight: 600; }
        .semana__tramos { padding: .4rem .75rem; display: flex; flex-direction: column; gap: .4rem; justify-content: center; }
        .semana__libre { color: var(--muted); font-size: .8rem; font-style: italic; }
        .tramo { display: flex; align-items: center; gap: .6rem; flex-wrap: wrap; }
        .tramo input[type=time] { padding: .35rem .5rem; border: 1px solid var(--border); border-radius: .4rem; font: inherit; }
        .tramo__horas { font-weight: 700; color: var(--fg); min-width: 3.5rem; }
        .tramo__resumen { color: var(--muted); font-size: .72rem; flex: 1; min-width: 12rem; }
        .tramo__acciones { display: flex; gap: .3rem; margin-left: auto; }
        .ajustes { margin: .25rem 0 .4rem; padding: .75rem; border: 1px dashed var(--border); border-radius: .5rem; background: #fafafa; }
        .ajustes__fila { display: grid; grid-template-columns: minmax(14rem, 22rem) 1fr; gap: .5rem; align-items: center; margin-bottom: .45rem; font-size: .8rem; }
        .ajustes__fila input[type=time], .ajustes__fila input[type=number] { padding: .3rem .45rem; border: 1px solid var(--border); border-radius: .4rem; font: inherit; }
        .ajustes__valores { display: flex; gap: .4rem; align-items: center; flex-wrap: wrap; }

        .totales { display: flex; gap: .6rem; flex-wrap: wrap; margin-top: .85rem; }
        .total { padding: .55rem .85rem; border-radius: .5rem; background: var(--bg); border: 1px solid var(--border); font-size: .8rem; }
        .total strong { font-size: 1.05rem; display: block; }

        @media (max-width: 720px) {
            .carga-rapida { grid-template-columns: 1fr; }
            .semana__dia { grid-template-columns: 1fr; }
            .ajustes__fila { grid-template-columns: 1fr; }
        }
    </style>

    <div class="cabecera">
        <div class="cabecera__titulo">
            <span class="cabecera__icono"><x-heroicon-o-rectangle-stack /></span>
            <h1>Nuevo turno</h1>
        </div>
        <a href="{{ route('turnos.index') }}" class="btn btn--gris"><x-heroicon-o-arrow-left />Volver</a>
    </div>

    <form action="{{ route('turnos.store') }}" method="POST"
          x-data="{
              dias: @js(\App\Models\Horario::DIAS),
              // La semana laboral empieza el lunes; en la base, 1 es domingo.
              orden: ['2', '3', '4', '5', '6', '7', '1'],
              letras: { '2': 'L', '3': 'M', '4': 'X', '5': 'J', '6': 'V', '7': 'S', '1': 'D' },
              filas: @js($filas).map(fila => ({ ...fila, clave: Math.random(), automatico: true, abierto: false })),
              rapida: { dias: ['2', '3', '4', '5', '6'], entrada: '08:00', salida: '16:00' },
              nombre: @js(old('nombre', '')),
              nombreTocado: @js(filled(old('nombre'))),

              minutos(hora) {
                  const [h, m] = (hora || '00:00').split(':').map(Number);
                  return h * 60 + m;
              },
              hora(minutos) {
                  const total = ((minutos % 1440) + 1440) % 1440;
                  return String(Math.floor(total / 60)).padStart(2, '0') + ':' + String(total % 60).padStart(2, '0');
              },
              duracion(entrada, salida) {
                  return ((this.minutos(salida) - this.minutos(entrada) + 1440) % 1440 || 1440) / 60;
              },
              horasTexto(horas) {
                  const h = Math.floor(horas), m = Math.round((horas - h) * 60);
                  return m ? `${h} h ${m} min` : `${h} h`;
              },
              // Mientras no se toquen a mano, tolerancias y ventanas de marca se
              // sugieren a partir de la entrada y la salida.
              sugerir(fila) {
                  if (! fila.automatico || ! fila.hEntrada || ! fila.hSalida) { return; }
                  const entrada = this.minutos(fila.hEntrada);
                  const salida = this.minutos(fila.hSalida);
                  fila.hTolerancia = this.hora(entrada + 10);
                  fila.eMinima = this.hora(entrada - 60);
                  fila.eMaxima = this.hora(entrada + 120);
                  fila.sTolerancia = fila.hSalida;
                  fila.sMinima = fila.hSalida;
                  fila.sMaxima = this.hora(salida + 120);
              },

              alternarDia(dia) {
                  const i = this.rapida.dias.indexOf(dia);
                  i === -1 ? this.rapida.dias.push(dia) : this.rapida.dias.splice(i, 1);
              },
              elegir(dias) { this.rapida.dias = [...dias]; },
              get rapidaNocturna() {
                  return this.rapida.entrada && this.rapida.salida && this.minutos(this.rapida.salida) <= this.minutos(this.rapida.entrada);
              },
              get puedeAgregar() {
                  return this.rapida.dias.length > 0 && this.rapida.entrada && this.rapida.salida;
              },
              // Un horario por día elegido, con lo sugerido. Lo que ya está igual
              // (mismo día y mismas horas) no se repite.
              agregarTramo() {
                  if (! this.puedeAgregar) { return; }
                  for (const dia of this.orden.filter(d => this.rapida.dias.includes(d))) {
                      const repetido = this.filas.some(f => f.dia === dia && f.hEntrada === this.rapida.entrada && f.hSalida === this.rapida.salida);
                      if (repetido) { continue; }
                      const fila = { dia, hEntrada: this.rapida.entrada, hSalida: this.rapida.salida, hTolerancia: '', eMinima: '', eMaxima: '',
                          sTolerancia: '', sMinima: '', sMaxima: '',
                          clave: Math.random(), automatico: true, abierto: false };
                      this.sugerir(fila);
                      this.filas.push(fila);
                  }
              },
              quitar(fila) {
                  this.filas.splice(this.filas.indexOf(fila), 1);
              },
              vaciar() { this.filas = []; },

              delDia(dia) {
                  return this.filas.filter(f => f.dia === dia).sort((a, b) => this.minutos(a.hEntrada) - this.minutos(b.hEntrada));
              },
              // Tramo del horario en minutos desde el domingo 00:00. Misma cuenta
              // que StoreTurnoRequest, que es quien decide de verdad.
              tramo(fila) {
                  const base = (Number(fila.dia) - 1) * 1440;
                  const inicio = base + this.minutos(fila.hEntrada);
                  let fin = base + this.minutos(fila.hSalida);
                  if (fin <= inicio) { fin += 1440; }
                  return [inicio, fin];
              },
              choqueDe(fila) {
                  if (! fila.dia || ! fila.hEntrada || ! fila.hSalida) { return null; }
                  const semana = 7 * 1440;
                  const [i1, f1] = this.tramo(fila);
                  const otro = this.filas.find(o => o !== fila && o.dia && o.hEntrada && o.hSalida
                      && [0, semana, -semana].some(c => { const [i2, f2] = this.tramo(o); return i1 < f2 + c && i2 + c < f1; }));
                  return otro ? `${this.dias[otro.dia]} ${otro.hEntrada}–${otro.hSalida}` : null;
              },
              get hayChoques() { return this.filas.some(f => this.choqueDe(f)); },
              // Las horas no se cargan: salen de la entrada y la salida, igual que
              // en StoreTurnoRequest.
              // Sale al día siguiente cuando la salida no es posterior a la entrada
              // (22:00 → 06:00). No se elige: se deduce, igual que en StoreTurnoRequest.
              nocturno(fila) {
                  return fila.hEntrada && fila.hSalida && this.minutos(fila.hSalida) <= this.minutos(fila.hEntrada);
              },
              horasDe(fila) {
                  return fila.hEntrada && fila.hSalida ? this.duracion(fila.hEntrada, fila.hSalida) : 0;
              },
              get horas() {
                  return this.filas.reduce((total, fila) => total + this.horasDe(fila), 0);
              },
              get diasLaborables() { return new Set(this.filas.map(f => f.dia)).size; },

              // «L-V 08:00-16:00»: días agrupados por horario, con los seguidos en rango.
              get nombreSugerido() {
                  const grupos = {};
                  for (const f of this.filas) {
                      if (! f.hEntrada || ! f.hSalida) { continue; }
                      const clave = `${f.hEntrada}-${f.hSalida}`;
                      (grupos[clave] ??= new Set()).add(f.dia);
                  }
                  const partes = Object.entries(grupos).map(([horas, dias]) => `${this.rangoDias(dias)} ${horas}`);
                  return partes.join(' / ').slice(0, 60);
              },
              rangoDias(dias) {
                  const posiciones = this.orden.map((d, i) => dias.has(d) ? i : -1).filter(i => i !== -1);
                  const tramos = [];
                  for (const p of posiciones) {
                      const ultimo = tramos[tramos.length - 1];
                      ultimo && ultimo[1] === p - 1 ? (ultimo[1] = p) : tramos.push([p, p]);
                  }
                  return tramos.map(([a, b]) => {
                      const desde = this.letras[this.orden[a]], hasta = this.letras[this.orden[b]];
                      return a === b ? desde : (b - a === 1 ? `${desde},${hasta}` : `${desde}-${hasta}`);
                  }).join(',');
              },
              resumenAjustes(fila) {
                  return `Atraso después de ${fila.hTolerancia || '—'} · el reloj toma la entrada de ${fila.eMinima || '—'} a ${fila.eMaxima || '—'} y la salida de ${fila.sMinima || '—'} a ${fila.sMaxima || '—'}`;
              },
          }"
          x-effect="if (! nombreTocado) { nombre = nombreSugerido }">
        @csrf

        {{-- Paso 1 --}}
        <div class="card card--padded">
            <div class="paso"><span class="paso__numero">1</span><h2>¿Cómo se llama el turno?</h2></div>
            <p class="ayuda paso-ayuda">Se arma solo con los horarios que cargues abajo; podés cambiarlo.</p>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr)); gap: 0 1rem;">
                <div class="campo">
                    <label for="nombre">Nombre del turno <span class="req">*</span></label>
                    <input type="text" id="nombre" name="nombre" class="input" maxlength="60" required
                           x-model="nombre" x-on:input="nombreTocado = nombre !== ''" placeholder="Ej.: L-V 08:00-16:00">
                    @error('nombre') <div class="error">{{ $message }}</div> @enderror
                </div>

                <div class="campo">
                    <label for="observacion">Observación</label>
                    <input type="text" id="observacion" name="observacion" class="input" maxlength="255"
                           value="{{ old('observacion') }}" placeholder="Opcional: para quién es, desde cuándo…">
                    @error('observacion') <div class="error">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="campo check" style="margin-bottom: 0;">
                <input type="checkbox" id="sugerido" name="sugerido" value="1" @checked(old('sugerido'))>
                <label for="sugerido" style="margin: 0;">
                    Ofrecerlo en Mamoré
                    <small style="display: block; font-weight: 400; color: #6b7280;">
                        Turno sugerido: aparece para elegir al dar de alta un contrato.
                    </small>
                </label>
            </div>
        </div>

        {{-- Paso 2 --}}
        <div class="card card--padded" style="margin-top: 1rem;">
            <div class="paso"><span class="paso__numero">2</span><h2>¿Qué días y en qué horario trabaja?</h2></div>
            <p class="ayuda paso-ayuda">
                Elegí los días, poné la hora de entrada y de salida, y agregalos.
                ¿Trabaja mañana y tarde? Agregá primero la mañana y después la tarde para los mismos días.
            </p>

            <div class="carga-rapida">
                <div>
                    <div class="campo" style="margin-bottom: 0;">
                        <label>Días</label>
                        <div class="dias-chips">
                            <template x-for="dia in orden" :key="dia">
                                <button type="button" class="dia-chip" :class="rapida.dias.includes(dia) && 'dia-chip--activo'"
                                        x-on:click="alternarDia(dia)" :title="dias[dia]" :aria-pressed="rapida.dias.includes(dia)"
                                        x-text="dias[dia].slice(0, 3)"></button>
                            </template>
                        </div>
                        <div class="atajos">
                            <button type="button" class="atajo" x-on:click="elegir(['2', '3', '4', '5', '6'])">Lunes a viernes</button>
                            <button type="button" class="atajo" x-on:click="elegir(['2', '3', '4', '5', '6', '7'])">Lunes a sábado</button>
                            <button type="button" class="atajo" x-on:click="elegir(orden)">Todos los días</button>
                            <button type="button" class="atajo" x-on:click="elegir([])">Ninguno</button>
                        </div>
                    </div>
                </div>

                <div class="carga-rapida__horas">
                    <div class="campo">
                        <label for="rapida-entrada">Entra a las</label>
                        <input type="time" id="rapida-entrada" x-model="rapida.entrada">
                    </div>
                    <div class="campo">
                        <label for="rapida-salida">Sale a las</label>
                        <input type="time" id="rapida-salida" x-model="rapida.salida">
                    </div>
                    <button type="button" class="btn" x-on:click="agregarTramo()" :disabled="! puedeAgregar">
                        <x-heroicon-o-plus />Agregar
                    </button>
                </div>
            </div>

            <div class="vista-previa" :class="rapidaNocturna && 'vista-previa--aviso'" x-show="puedeAgregar">
                <span x-text="`Se agrega ${rapida.dias.length === 1 ? '1 día' : rapida.dias.length + ' días'} (${rangoDias(new Set(rapida.dias))}) de ${rapida.entrada} a ${rapida.salida}: ${horasTexto(duracion(rapida.entrada, rapida.salida))} por día.`"></span>
                <strong x-show="rapidaNocturna"> Turno nocturno: sale al día siguiente.</strong>
            </div>
        </div>

        {{-- Paso 3 --}}
        <div class="card card--padded" style="margin-top: 1rem;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: .5rem; flex-wrap: wrap;">
                <div class="paso"><span class="paso__numero">3</span><h2>Revisá cómo queda la semana</h2></div>
                <button type="button" class="btn btn--gris" x-show="filas.length > 0" x-on:click="vaciar()">
                    <x-heroicon-o-arrow-path />Empezar de nuevo
                </button>
            </div>
            <p class="ayuda paso-ayuda">
                Podés corregir las horas acá mismo. En <strong>Ajustar</strong> están la tolerancia y desde qué hora el reloj
                toma cada marca: ya vienen calculadas, cambialas solo si hace falta.
                <strong>El turno no se edita después de guardarlo</strong>: para cambiar la jornada se crea otro.
            </p>

            @if ($erroresHorarios->isNotEmpty())
                <div class="error" style="margin-bottom: .75rem;">
                    @foreach ($erroresHorarios as $mensaje)
                        <div>{{ $mensaje }}</div>
                    @endforeach
                </div>
            @endif

            <div class="semana">
                <template x-for="dia in orden" :key="dia">
                    <div class="semana__dia" :class="delDia(dia).length === 0 && 'semana__dia--libre'">
                        <div class="semana__nombre" x-text="dias[dia]"></div>
                        <div class="semana__tramos">
                            <span class="semana__libre" x-show="delDia(dia).length === 0">No trabaja</span>

                            <template x-for="fila in delDia(dia)" :key="fila.clave">
                                <div>
                                    <div class="tramo">
                                        {{-- Los campos van con el índice de la fila en `filas`, que es lo
                                             que recibe StoreTurnoRequest. --}}
                                        <input type="hidden" :name="`horarios[${filas.indexOf(fila)}][dia]`" :value="fila.dia">
                                        <input type="time" :name="`horarios[${filas.indexOf(fila)}][hEntrada]`" x-model="fila.hEntrada"
                                               x-on:change="sugerir(fila)" aria-label="Entrada" required>
                                        <span>a</span>
                                        <input type="time" :name="`horarios[${filas.indexOf(fila)}][hSalida]`" x-model="fila.hSalida"
                                               x-on:change="sugerir(fila)" aria-label="Salida" required>
                                        <span class="tramo__horas" x-text="horasTexto(horasDe(fila))" title="Se calcula de la entrada a la salida"></span>
                                        <span class="pill pill--advertencia" x-show="nocturno(fila)" :title="`Entra ${dias[fila.dia]} a las ${fila.hEntrada} y sale al día siguiente a las ${fila.hSalida}`">Sale al día siguiente</span>
                                        <span class="pill pill--no" x-show="choqueDe(fila)" x-text="`Coincide con ${choqueDe(fila)}`"></span>
                                        <span class="tramo__resumen" x-show="! fila.abierto" x-text="resumenAjustes(fila)"></span>
                                        <div class="tramo__acciones">
                                            <button type="button" class="btn-icon btn-icon--gris" x-on:click="fila.abierto = ! fila.abierto"
                                                    :title="fila.abierto ? 'Cerrar ajustes' : 'Ajustar tolerancia y marcas'" aria-label="Ajustar">
                                                <x-heroicon-o-adjustments-horizontal />
                                            </button>
                                            <button type="button" class="btn-icon btn-icon--peligro" x-on:click="quitar(fila)"
                                                    title="Quitar este horario" aria-label="Quitar"><x-heroicon-o-x-mark /></button>
                                        </div>
                                    </div>

                                    {{-- Siempre en el DOM (x-show, no x-if): estos campos viajan
                                         en el formulario aunque el panel esté cerrado. --}}
                                    <div class="ajustes" x-show="fila.abierto" x-cloak>
                                        <div class="ajustes__fila">
                                            <span>Es <strong>atraso</strong> si marca la entrada después de</span>
                                            <div class="ajustes__valores">
                                                <input type="time" :name="`horarios[${filas.indexOf(fila)}][hTolerancia]`" x-model="fila.hTolerancia" x-on:input="fila.automatico = false">
                                            </div>
                                        </div>
                                        <div class="ajustes__fila">
                                            <span>El reloj toma la <strong>entrada</strong> entre</span>
                                            <div class="ajustes__valores">
                                                <input type="time" :name="`horarios[${filas.indexOf(fila)}][eMinima]`" x-model="fila.eMinima" x-on:input="fila.automatico = false">
                                                <span>y</span>
                                                <input type="time" :name="`horarios[${filas.indexOf(fila)}][eMaxima]`" x-model="fila.eMaxima" x-on:input="fila.automatico = false">
                                            </div>
                                        </div>
                                        <div class="ajustes__fila">
                                            <span>Es <strong>salida anticipada</strong> si marca antes de</span>
                                            <div class="ajustes__valores">
                                                <input type="time" :name="`horarios[${filas.indexOf(fila)}][sTolerancia]`" x-model="fila.sTolerancia" x-on:input="fila.automatico = false">
                                            </div>
                                        </div>
                                        <div class="ajustes__fila">
                                            <span>El reloj toma la <strong>salida</strong> entre</span>
                                            <div class="ajustes__valores">
                                                <input type="time" :name="`horarios[${filas.indexOf(fila)}][sMinima]`" x-model="fila.sMinima" x-on:input="fila.automatico = false">
                                                <span>y</span>
                                                <input type="time" :name="`horarios[${filas.indexOf(fila)}][sMaxima]`" x-model="fila.sMaxima" x-on:input="fila.automatico = false">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </div>

            <div class="totales">
                <div class="total"><strong x-text="horasTexto(horas)"></strong>por semana</div>
                <div class="total"><strong x-text="diasLaborables"></strong>días que trabaja</div>
                <div class="total"><strong x-text="filas.length"></strong>horarios en total</div>
            </div>

            <div class="vista-previa vista-previa--aviso" x-show="filas.length === 0" style="margin-top: 1rem;">
                Todavía no hay horarios: cargalos en el paso 2.
            </div>
            <div class="vista-previa vista-previa--aviso" x-show="hayChoques" x-cloak style="margin-top: 1rem;">
                Hay horarios que coinciden en el mismo momento: cambiá las horas o quitá uno para poder guardar.
            </div>

            <div class="form-acciones">
                <button type="submit" class="btn" :disabled="filas.length === 0 || hayChoques">
                    <x-heroicon-o-check />Guardar turno
                </button>
                <a href="{{ route('turnos.index') }}" class="btn btn--gris"><x-heroicon-o-x-mark />Cancelar</a>
            </div>
        </div>
    </form>
@endsection
