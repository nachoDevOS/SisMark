{{-- Campos compartidos por alta y edición de rol. $role null = alta. --}}
@php
    $role ??= null;
    $permisosActuales = old('permisos', $permisosActuales ?? []);
    $habilidades = \App\Policies\RolePolicy::HABILIDADES;
    $modulos = \App\Policies\RolePolicy::MODULOS;
@endphp

<div class="campo">
    <label for="name">Nombre del rol</label>
    <input type="text" id="name" name="name" value="{{ old('name', $role->name ?? '') }}" required>
    @error('name') <div class="error">{{ $message }}</div> @enderror
</div>

<div class="campo">
    <label>Permisos</label>
    @error('permisos') <div class="error">{{ $message }}</div> @enderror

    {{-- La matriz solo ofrece las habilidades que cada módulo admite: las celdas
         de las que no aplican van vacías, en vez de una casilla que no hace
         nada. La columna «Todo» marca y desmarca la fila entera. --}}
    <table>
        <thead>
            <tr>
                <th>Módulo</th>
                <th>Todo</th>
                @foreach ($habilidades as $etiquetaHabilidad)
                    <th>{{ $etiquetaHabilidad }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($modulos as $modulo => $definicion)
                <tr>
                    <td><strong>{{ $definicion['etiqueta'] }}</strong></td>
                    <td>
                        <input type="checkbox" class="permisos-fila"
                               aria-label="Marcar todos los permisos de {{ $definicion['etiqueta'] }}">
                    </td>
                    @foreach ($habilidades as $habilidad => $etiquetaHabilidad)
                        <td>
                            @if (in_array($habilidad, $definicion['habilidades'], true))
                                @php($nombrePermiso = "{$habilidad}:{$modulo}")
                                <input type="checkbox" name="permisos[]" value="{{ $nombrePermiso }}"
                                       aria-label="{{ $etiquetaHabilidad }} en {{ $definicion['etiqueta'] }}"
                                       @checked(in_array($nombrePermiso, $permisosActuales))>
                            @else
                                <span class="ayuda" aria-hidden="true">—</span>
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

<script>
    (function () {
        // «Todo» marca/desmarca la fila, y arranca marcado si ya lo estaba todo.
        document.querySelectorAll('.permisos-fila').forEach(function (todo) {
            const dela = () => Array.from(todo.closest('tr').querySelectorAll('input[name="permisos[]"]'));

            todo.checked = dela().length > 0 && dela().every((casilla) => casilla.checked);
            todo.addEventListener('change', function () {
                dela().forEach((casilla) => { casilla.checked = todo.checked; });
            });

            dela().forEach(function (casilla) {
                casilla.addEventListener('change', function () {
                    todo.checked = dela().every((otra) => otra.checked);
                });
            });
        });
    })();
</script>
