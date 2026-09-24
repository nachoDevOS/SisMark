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
