{{-- Selector de turno: una tarjeta por turno, con sus horarios desplegables.
     Recibe $turnos y $sufijo (para ids únicos si hay varios en la página). --}}
<div class="campo">
    <label id="turno-etiqueta-{{ $sufijo }}">Turno <span class="req">*</span></label>

    @if ($turnos->isEmpty())
        <div class="aviso aviso--advertencia">
            No hay turnos cargados todavía.
            @can('create', \App\Models\Turno::class)
                <a href="{{ route('turnos.create') }}">Creá uno primero</a>.
            @endcan
        </div>
    @else
        <div role="radiogroup" aria-labelledby="turno-etiqueta-{{ $sufijo }}"
             style="display: grid; gap: .5rem; max-height: 26rem; overflow-y: auto;"
             x-data="{ elegido: @js((string) old('turno_id', '')) }">
            @foreach ($turnos as $turno)
                <div x-data="{ ver: false }"
                     style="border: 1px solid var(--border); border-radius: .4rem; padding: .6rem .75rem;"
                     :style="elegido === '{{ $turno->id }}' ? 'border-color: var(--primary, #16a34a); box-shadow: 0 0 0 1px var(--primary, #16a34a);' : ''">
                    <div style="display: flex; align-items: center; gap: .6rem;">
                        <input type="radio" name="turno_id" value="{{ $turno->id }}" id="turno-{{ $sufijo }}-{{ $turno->id }}"
                               x-model="elegido" required>
                        <label for="turno-{{ $sufijo }}-{{ $turno->id }}" style="margin: 0; flex: 1; cursor: pointer;">
                            <strong>{{ $turno->nombre }}</strong>
                            @if ($turno->sugerido)
                                <span class="pill pill--info">Sugerido</span>
                            @endif
                            <span class="ayuda" style="display: block; margin: 0;">
                                {{ $turno->dias_cubiertos ?: 'Sin días' }}
                                · {{ number_format($turno->horas_semanales, 2) }} h semanales
                                · {{ $turno->horarios->count() }} {{ $turno->horarios->count() === 1 ? 'horario' : 'horarios' }}
                            </span>
                        </label>
                        <button type="button" class="btn btn--gris" style="white-space: nowrap;" x-on:click="ver = ! ver">
                            <x-heroicon-o-eye /><span x-text="ver ? 'Ocultar horarios' : 'Ver horarios'"></span>
                        </button>
                    </div>
                    <div x-show="ver" x-cloak style="margin-top: .5rem; overflow-x: auto;">
                        @include('turnos._horarios', ['horarios' => $turno->horarios])
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @error('turno_id') <div class="error">{{ $message }}</div> @enderror
</div>
