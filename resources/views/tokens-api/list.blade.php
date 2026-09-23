<div class="card">
    <table>
        <thead>
            <tr>
                <th>Sistema</th>
                <th>Nombre corto</th>
                <th>Estado</th>
                <th>Credencial</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($sistemas as $sistema)
                <tr @class(['fila--inactiva' => ! $sistema->activo])>
                    <td>
                        <strong>{{ $sistema->nombre }}</strong>
                        @if (filled($sistema->observaciones))
                            <div class="ayuda">{{ Str::limit($sistema->observaciones, 70) }}</div>
                        @endif
                    </td>
                    <td><code>{{ $sistema->slug }}</code></td>
                    <td>
                        <span class="pill {{ $sistema->activo ? 'pill--ok' : 'pill--no' }}">
                            {{ $sistema->activo ? 'Activo' : 'Inactivo' }}
                        </span>
                    </td>
                    <td>
                        {{-- Un sistema tiene un solo token vivo, así que esto es
                             «tiene» o «no tiene», no un conteo. Sin credencial no
                             puede entrar, aunque esté activo. --}}
                        @if ($sistema->tokens_count > 0)
                            <span class="pill pill--info">Con token</span>
                        @else
                            <span class="pill pill--neutro">Sin token</span>
                        @endif
                    </td>
                    <td>
                        <div class="acciones">
                            @can('view', $sistema)
                                <a href="{{ route('tokens-api.show', $sistema) }}" class="btn-icon btn-icon--gris" title="Ver" aria-label="Ver"><x-heroicon-o-eye /></a>
                            @endcan
                            @can('update', $sistema)
                                <a href="{{ route('tokens-api.edit', $sistema) }}" class="btn-icon" title="Editar" aria-label="Editar"><x-heroicon-o-pencil-square /></a>
                            @endcan
                            @can('delete', $sistema)
                                <x-boton-eliminar :accion="route('tokens-api.destroy', $sistema)"
                                                  :mensaje="'Se da de baja el sistema «'.$sistema->nombre.'». Su token deja de funcionar en el próximo pedido.'" />
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="vacio">
                        Todavía no hay ningún sistema registrado.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="paginacion">
    {{ $sistemas->links() }}
</div>
