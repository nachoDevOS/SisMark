{{-- Campos compartidos por alta y edición. $equipo puede no existir (alta). --}}
@php
    $equipo ??= null;
@endphp

<div class="campo">
    <label for="nombre">Nombre</label>
    <input type="text" id="nombre" name="nombre" value="{{ old('nombre', $equipo->nombre ?? '') }}" required>
    @error('nombre') <div class="error">{{ $message }}</div> @enderror
</div>

<div class="campo">
    <label for="ip">Dirección IP</label>
    <input type="text" id="ip" name="ip" value="{{ old('ip', $equipo->ip ?? '') }}" required>
    <div class="ayuda">IP del equipo en la red LAN, ej. 192.168.1.201</div>
    @error('ip') <div class="error">{{ $message }}</div> @enderror
</div>

<div class="campo">
    <label for="puerto">Puerto</label>
    <input type="number" id="puerto" name="puerto" min="1" max="65535"
           value="{{ old('puerto', $equipo->puerto ?? 4370) }}" required>
    <div class="ayuda">Puerto TCP del protocolo ZKTeco (4370 por defecto)</div>
    @error('puerto') <div class="error">{{ $message }}</div> @enderror
</div>

<div class="campo">
    <label for="comm_key">COMM key</label>
    <input type="number" id="comm_key" name="comm_key" min="0"
           value="{{ old('comm_key', $equipo->comm_key ?? 0) }}" required>
    <div class="ayuda">Clave de comunicación del equipo (0 si no tiene)</div>
    @error('comm_key') <div class="error">{{ $message }}</div> @enderror
</div>

<div class="campo">
    <label for="ubicacion">Ubicación</label>
    <input type="text" id="ubicacion" name="ubicacion" value="{{ old('ubicacion', $equipo->ubicacion ?? '') }}">
    <div class="ayuda">Dónde está físicamente, ej. "Puerta principal"</div>
    @error('ubicacion') <div class="error">{{ $message }}</div> @enderror
</div>

<div class="campo check">
    <input type="checkbox" id="activo" name="activo" value="1"
           @checked(old('activo', $equipo->activo ?? true))>
    <label for="activo" style="margin: 0;">Activo (participa en la sincronización)</label>
</div>

{{-- Sincronización automática: qué días y a qué horas el sistema baja solo las
     marcaciones de este equipo. Sin horas cargadas no corre nada, por eso el
     checkbox y la lista van juntos.

     La lectura NO borra nada del reloj: el equipo conserva su historial
     completo. Vaciarlo es otra acción, con su propio botón y su propio permiso. --}}
@php
    $horariosGuardados = old('sync_horarios', $equipo?->horariosSync() ?? []);
    $horariosGuardados = array_values(array_filter((array) $horariosGuardados, fn ($hora): bool => filled($hora)));

    // Sin días guardados se marcan los siete: es lo que hace el equipo cuando la
    // lista está vacía, y el formulario tiene que mostrar lo que va a pasar.
    $diasGuardados = old('sync_dias', $equipo?->diasSync() ?: array_keys(\App\Models\Turno::DIAS));
    $diasGuardados = array_map('intval', (array) $diasGuardados);
@endphp

<div class="campo"
     x-data="{
         automatica: {{ old('sync_automatica', $equipo->sync_automatica ?? false) ? 'true' : 'false' }},
         horarios: {{ Illuminate\Support\Js::from($horariosGuardados ?: ['']) }},
         agregar() { this.horarios.push(''); },
         quitar(i) { this.horarios.splice(i, 1); if (this.horarios.length === 0) { this.horarios.push(''); } },
     }">
    <div class="check">
        <input type="checkbox" id="sync_automatica" name="sync_automatica" value="1" x-model="automatica">
        <label for="sync_automatica" style="margin: 0;">Sincronizar automáticamente en días y horas fijas</label>
    </div>
    <div class="ayuda">
        El sistema baja las marcaciones de este equipo, sin que nadie apriete el botón,
        en cada una de las horas de la lista y solo en los días marcados.
        Las marcaciones <strong>no se borran del reloj</strong>: solo se copian al sistema.
    </div>
    @error('sync_automatica') <div class="error">{{ $message }}</div> @enderror

    <div x-show="automatica" x-cloak style="margin-top: .6rem;">
        <label>Días de sincronización</label>

        <div style="display: flex; flex-wrap: wrap; gap: .25rem 1rem; margin-bottom: .2rem;">
            @foreach (\App\Models\Turno::DIAS as $numero => $nombre)
                <div class="check">
                    <input type="checkbox" id="sync_dia_{{ $numero }}" name="sync_dias[]"
                           value="{{ $numero }}" @checked(in_array($numero, $diasGuardados, true))>
                    <label for="sync_dia_{{ $numero }}" style="margin: 0;">{{ $nombre }}</label>
                </div>
            @endforeach
        </div>

        <div class="ayuda">
            Sin ningún día marcado el equipo se sincroniza todos los días.
            Destildar sábado y domingo evita hablar con el reloj cuando no hay nadie marcando.
        </div>

        @error('sync_dias') <div class="error">{{ $message }}</div> @enderror
        @foreach ($errors->get('sync_dias.*') as $mensajes)
            @foreach ($mensajes as $mensaje)
                <div class="error">{{ $mensaje }}</div>
            @endforeach
        @endforeach

        <label style="margin-top: .8rem; display: block;">Horas de sincronización</label>

        <template x-for="(hora, i) in horarios" :key="i">
            <div style="display: flex; gap: .5rem; align-items: center; margin-bottom: .4rem;">
                <input type="time" name="sync_horarios[]" x-model="horarios[i]" style="max-width: 10rem;">
                <button type="button" class="btn btn--gris" x-on:click="quitar(i)" aria-label="Quitar hora">
                    <x-heroicon-o-x-mark />
                </button>
            </div>
        </template>

        <button type="button" class="btn btn--gris" x-on:click="agregar()">
            <x-heroicon-o-plus />Agregar hora
        </button>

        <div class="ayuda">
            Ejemplo: 08:30, 13:00 y 19:00 —una vez cerrado cada bloque de marcación—.
            Cada corrida trae lo que falte desde la anterior, así que repetir una hora no duplica nada.
        </div>

        @error('sync_horarios') <div class="error">{{ $message }}</div> @enderror
        @foreach ($errors->get('sync_horarios.*') as $mensajes)
            @foreach ($mensajes as $mensaje)
                <div class="error">{{ $mensaje }}</div>
            @endforeach
        @endforeach
    </div>
</div>
