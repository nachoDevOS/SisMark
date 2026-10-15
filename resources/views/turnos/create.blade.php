@extends('layouts.app')

@section('titulo', 'Nuevo turno')

@php
    // Las filas vuelven tal cual si la validación rebota; si no, arranca con una
    // fila de lunes vacía para que se vea qué hay que cargar.
    $filas = collect(old('horarios', [['dia' => '2']]))
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
            'hTrabajadas' => $fila['hTrabajadas'] ?? '',
            'siguienteDia' => (bool) ($fila['siguienteDia'] ?? false),
        ])
        ->values();

    $erroresHorarios = collect($errors->get('horarios'))->merge(collect($errors->get('horarios.*'))->flatten());
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
              dias: @js(\App\Models\Horario::DIAS),
              filas: @js($filas).map(fila => ({ ...fila, clave: Math.random(), automatico: true, copiando: false, copiarA: [] })),
              minutos(hora) {
                  const [h, m] = (hora || '00:00').split(':').map(Number);
                  return h * 60 + m;
              },
              hora(minutos) {
                  const total = ((minutos % 1440) + 1440) % 1440;
                  return String(Math.floor(total / 60)).padStart(2, '0') + ':' + String(total % 60).padStart(2, '0');
              },
              // Mientras no se toquen a mano, tolerancias, ventanas de marca y horas
              // se sugieren a partir de la entrada y la salida.
              sugerir(fila) {
                  if (! fila.automatico || ! fila.hEntrada || ! fila.hSalida) { return; }
                  const entrada = this.minutos(fila.hEntrada);
                  const salida = this.minutos(fila.hSalida);
                  fila.siguienteDia = salida <= entrada;
                  fila.hTolerancia = this.hora(entrada + 10);
                  fila.eMinima = this.hora(entrada - 60);
                  fila.eMaxima = this.hora(entrada + 120);
                  fila.sTolerancia = fila.hSalida;
                  fila.sMinima = fila.hSalida;
                  fila.sMaxima = this.hora(salida + 120);
                  fila.hTrabajadas = (((salida - entrada + 1440) % 1440 || 1440) / 60).toFixed(2);
              },
              // Tramo del horario en minutos desde el domingo 00:00. Misma cuenta
              // que StoreTurnoRequest, que es quien decide de verdad.
              tramo(fila) {
                  const base = (Number(fila.dia) - 1) * 1440;
                  const inicio = base + this.minutos(fila.hEntrada);
                  let fin = base + this.minutos(fila.hSalida);
                  if (fila.siguienteDia || fin <= inicio) { fin += 1440; }
                  return [inicio, fin];
              },
              choqueDe(indice) {
                  const fila = this.filas[indice];
                  if (! fila.dia || ! fila.hEntrada || ! fila.hSalida) { return null; }
                  const semana = 7 * 1440;
                  const [i1, f1] = this.tramo(fila);
                  const otro = this.filas.findIndex((o, j) => j !== indice && o.dia && o.hEntrada && o.hSalida
                      && [0, semana, -semana].some(c => { const [i2, f2] = this.tramo(o); return i1 < f2 + c && i2 + c < f1; }));
                  return otro === -1 ? null : otro + 1;
              },
              get horas() {
                  return this.filas.reduce((total, fila) => total + (Number(fila.hTrabajadas) || 0), 0);
              },
              agregar() {
                  this.filas.push({ dia: '', hEntrada: '', hTolerancia: '', eMinima: '', eMaxima: '', hSalida: '', sTolerancia: '',
                      sMinima: '', sMaxima: '', hTrabajadas: '', siguienteDia: false, clave: Math.random(), automatico: true, copiando: false, copiarA: [] });
              },
              quitar(indice) {
                  this.filas.splice(indice, 1);
              },
              // Repite la fila en los días elegidos, con las mismas horas.
              copiar(indice) {
                  const fila = this.filas[indice];
                  const copias = fila.copiarA.map(dia => ({ ...fila, dia: String(dia), clave: Math.random(), copiando: false, copiarA: [] }));
                  this.filas.splice(indice + 1, 0, ...copias);
                  fila.copiando = false;
                  fila.copiarA = [];
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
                Cargá un horario por día. Un mismo día puede tener más de uno (mañana y tarde) siempre que no se pisen.
                Al poner entrada y salida se sugieren las tolerancias, las ventanas de marca y las horas; podés cambiarlas.
                Con <strong>Copiar a días</strong> repetís un horario en otros días.
                <strong>El turno no se edita después</strong>: para cambiar la jornada se crea otro.
            </p>

            @if ($erroresHorarios->isNotEmpty())
                <div class="error" style="margin-bottom: .75rem;">
                    @foreach ($erroresHorarios as $mensaje)
                        <div>{{ $mensaje }}</div>
                    @endforeach
                </div>
            @endif

            <template x-for="(fila, i) in filas" :key="fila.clave">
                <div class="tarjeta" style="margin-bottom: .75rem;">
                    <div style="display: flex; justify-content: space-between; align-items: center; gap: .5rem; flex-wrap: wrap;">
                        <h3 style="margin: 0;">
                            Horario #<span x-text="i + 1"></span>
                            <span class="pill pill--advertencia" x-show="fila.siguienteDia" title="La salida es al día siguiente">+1 día</span>
                            <span class="pill pill--no" x-show="choqueDe(i)" x-text="`Se pisa con el #${choqueDe(i)}`"></span>
                        </h3>
                        <div style="display: flex; gap: .4rem;">
                            <button type="button" class="btn btn--gris" x-on:click="fila.copiando = ! fila.copiando"
                                    :disabled="! fila.hEntrada || ! fila.hSalida">
                                <x-heroicon-o-document-duplicate />Copiar a días
                            </button>
                            <button type="button" class="btn-icon btn-icon--gris" title="Quitar" aria-label="Quitar"
                                    x-show="filas.length > 1" x-on:click="quitar(i)"><x-heroicon-o-x-mark /></button>
                        </div>
                    </div>

                    <div x-show="fila.copiando" x-cloak style="margin-top: .5rem; padding: .5rem; border: 1px dashed var(--border); border-radius: .4rem;">
                        <div style="display: flex; flex-wrap: wrap; gap: .75rem; align-items: center;">
                            <template x-for="(nombreDia, numero) in dias" :key="numero">
                                <label style="display: flex; gap: .25rem; align-items: center; margin: 0;" x-show="String(numero) !== String(fila.dia)">
                                    <input type="checkbox" :value="numero" x-model="fila.copiarA">
                                    <span x-text="nombreDia"></span>
                                </label>
                            </template>
                            <button type="button" class="btn" x-on:click="copiar(i)" :disabled="fila.copiarA.length === 0">
                                <x-heroicon-o-check />Copiar
                            </button>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr)); gap: .5rem; margin-top: .5rem;">
                        <div class="campo">
                            <label>Día <span class="req">*</span></label>
                            <select :name="`horarios[${i}][dia]`" x-model="fila.dia" required>
                                <option value="">Elegí…</option>
                                <template x-for="(nombreDia, numero) in dias" :key="numero">
                                    <option :value="numero" x-text="nombreDia" :selected="String(numero) === String(fila.dia)"></option>
                                </template>
                            </select>
                        </div>
                        <div class="campo">
                            <label>Entrada <span class="req">*</span></label>
                            <input type="time" :name="`horarios[${i}][hEntrada]`" x-model="fila.hEntrada" x-on:change="sugerir(fila)" required>
                        </div>
                        <div class="campo">
                            <label>Salida <span class="req">*</span></label>
                            <input type="time" :name="`horarios[${i}][hSalida]`" x-model="fila.hSalida" x-on:change="sugerir(fila)" required>
                        </div>
                        <div class="campo">
                            <label>Horas trabajadas <span class="req">*</span></label>
                            <input type="number" step="0.01" min="0" max="24" :name="`horarios[${i}][hTrabajadas]`"
                                   x-model="fila.hTrabajadas" x-on:input="fila.automatico = false" required>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr)); gap: .5rem;">
                        <div class="campo">
                            <label>Tolerancia entrada <span class="req">*</span></label>
                            <input type="time" :name="`horarios[${i}][hTolerancia]`" x-model="fila.hTolerancia" x-on:input="fila.automatico = false" required>
                        </div>
                        <div class="campo">
                            <label>Marca entrada desde <span class="req">*</span></label>
                            <input type="time" :name="`horarios[${i}][eMinima]`" x-model="fila.eMinima" x-on:input="fila.automatico = false" required>
                        </div>
                        <div class="campo">
                            <label>Marca entrada hasta <span class="req">*</span></label>
                            <input type="time" :name="`horarios[${i}][eMaxima]`" x-model="fila.eMaxima" x-on:input="fila.automatico = false" required>
                        </div>
                        <div class="campo">
                            <label>Tolerancia salida <span class="req">*</span></label>
                            <input type="time" :name="`horarios[${i}][sTolerancia]`" x-model="fila.sTolerancia" x-on:input="fila.automatico = false" required>
                        </div>
                        <div class="campo">
                            <label>Marca salida desde <span class="req">*</span></label>
                            <input type="time" :name="`horarios[${i}][sMinima]`" x-model="fila.sMinima" x-on:input="fila.automatico = false" required>
                        </div>
                        <div class="campo">
                            <label>Marca salida hasta <span class="req">*</span></label>
                            <input type="time" :name="`horarios[${i}][sMaxima]`" x-model="fila.sMaxima" x-on:input="fila.automatico = false" required>
                        </div>
                    </div>

                    <div class="campo check" style="margin-bottom: 0;">
                        <input type="checkbox" value="1" :id="`siguiente-${fila.clave}`" :name="`horarios[${i}][siguienteDia]`"
                               x-model="fila.siguienteDia" x-on:change="fila.automatico = false">
                        <label :for="`siguiente-${fila.clave}`" style="margin: 0;">La salida corresponde al día siguiente</label>
                    </div>
                </div>
            </template>

            <div style="display: flex; justify-content: space-between; align-items: center; gap: .5rem; flex-wrap: wrap;">
                <button type="button" class="btn btn--gris" x-on:click="agregar()"><x-heroicon-o-plus />Agregar horario</button>
                <strong>Horas semanales: <span x-text="horas.toFixed(2)"></span></strong>
            </div>

            <p class="ayuda" style="margin-bottom: 0;">
                <strong>Marca entrada / salida</strong> = desde y hasta qué hora el reloj toma la marca.
            </p>

            <div class="form-acciones">
                <button type="submit" class="btn" :disabled="filas.length === 0 || filas.some((f, i) => choqueDe(i))">
                    <x-heroicon-o-check />Guardar turno
                </button>
                <a href="{{ route('turnos.index') }}" class="btn btn--gris"><x-heroicon-o-x-mark />Cancelar</a>
            </div>
        </div>
    </form>
@endsection
