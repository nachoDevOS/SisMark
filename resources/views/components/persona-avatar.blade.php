@props(['thumb' => null, 'full' => null, 'nombre' => ''])

{{-- Avatar del funcionario en las tablas. La foto solo la tiene Mamoré: sin
     ella queda el ícono genérico. Se pinta la miniatura y al pasar el mouse se
     amplía, que es la misma imagen ya descargada: el zoom no pide nada más. --}}
<span class="persona-avatar">
    <span class="persona-foto">
        @if (filled($thumb))
            <img src="{{ $thumb }}"
                 alt="Foto de {{ $nombre ?: 'la persona' }}" loading="lazy"
                 @if (filled($full)) onerror="this.onerror=null; this.src='{{ $full }}'" @endif>
        @else
            <x-heroicon-o-user />
        @endif
    </span>
    @if (filled($thumb))
        <span class="persona-zoom" aria-hidden="true">
            <img src="{{ $thumb }}" alt="" loading="lazy">
        </span>
    @endif
</span>
