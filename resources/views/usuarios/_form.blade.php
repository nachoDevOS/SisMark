{{-- Campos compartidos por alta y edición de usuario. $usuario null = alta. --}}
@php
    $usuario ??= null;
    $rolActual = old('rol', $usuario?->roles->first()?->id);
@endphp

<div class="campo">
    <label for="name">Nombre</label>
    <input type="text" id="name" name="name" value="{{ old('name', $usuario->name ?? '') }}" required>
    @error('name') <div class="error">{{ $message }}</div> @enderror
</div>

<div class="campo">
    <label for="email">Correo electrónico</label>
    <input type="text" id="email" name="email" value="{{ old('email', $usuario->email ?? '') }}" required>
    @error('email') <div class="error">{{ $message }}</div> @enderror
</div>

<div class="campo">
    <label for="password">Contraseña</label>
    <x-campo-clave id="password" autocomplete="new-password" :required="! isset($usuario)" />
    <div class="ayuda">
        @isset($usuario) En edición, dejar vacío para no cambiarla. Si se cambia, el usuario deberá poner una propia al entrar. @else Mínimo 8 caracteres. El usuario deberá cambiarla al entrar por primera vez. @endisset
    </div>
    @error('password') <div class="error">{{ $message }}</div> @enderror
</div>

<div class="campo">
    <label for="rol">Rol</label>
    <select id="rol" name="rol" required>
        <option value="" disabled @selected(! $rolActual)>Elegí un rol…</option>
        @foreach ($roles as $rol)
            <option value="{{ $rol->id }}" @selected((int) $rolActual === $rol->id)>{{ $rol->name }}</option>
        @endforeach
    </select>
    @error('rol') <div class="error">{{ $message }}</div> @enderror
</div>
