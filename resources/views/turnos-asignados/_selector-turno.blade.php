{{-- Selector de turno: una tarjeta por turno, que se elige con un clic en
     cualquier parte, y su semana desplegable. Recibe $turnos y $sufijo (para ids
     únicos si hay varios en la página). --}}
@once
    <style>
        .turnos-opciones { display: grid; gap: .45rem; max-height: 22rem; overflow-y: auto; padding: .1rem; }
        .turno-opcion { display: block; border: 1.5px solid var(--border); border-radius: .55rem; padding: .6rem .75rem;
            cursor: pointer; transition: border-color .12s, background .12s; }
        .turno-opcion:hover { border-color: #86efac; }
        .turno-opcion--elegido { border-color: var(--verde); background: #f0fdf4; }
        .turno-opcion__fila { display: flex; align-items: flex-start; gap: .6rem; }
        .turno-opcion__fila input { margin-top: .2rem; accent-color: var(--verde); }
        .turno-opcion__nombre { font-weight: 700; }
        .turno-opcion__datos { display: block; color: var(--muted); font-size: .75rem; margin-top: .1rem; }
        .turno-opcion__ver { margin-left: auto; background: none; border: 0; padding: .1rem .2rem; cursor: pointer;
            color: var(--verde-osc); font: inherit; font-size: .75rem; font-weight: 600; white-space: nowrap; }
        .turno-opcion__ver:hover { text-decoration: underline; }
        .mini-semana { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: .3rem; margin-top: .55rem; }
        .mini-semana__dia { border-radius: .4rem; padding: .3rem .2rem; text-align: center; font-size: .68rem;
            background: var(--bg); color: var(--muted); }
        .mini-semana__dia strong { display: block; font-size: .7rem; color: var(--fg); }
        .mini-semana__dia--trabaja { background: #dcfce7; color: #166534; }
    </style>
@endonce

<div class="campo" style="margin-bottom: 0;">
    @if ($turnos->isEmpty())
        <div class="aviso aviso--advertencia" style="margin: 0;">
            No hay turnos cargados todavía.
            @can('create', \App\Models\Turno::class)
                <a href="{{ route('turnos.create') }}">Creá uno primero</a>.
            @endcan
        </div>
    @else
        <div class="turnos-opciones" role="radiogroup" aria-label="Turno"
             x-data="{ elegido: @js((string) old('turno_id', '')) }">
            @foreach ($turnos as $turno)
                @php
                    // La semana de lunes a domingo, con las horas de cada día
                    // (puede haber más de un tramo, mañana y tarde).
                    $semana = collect([2, 3, 4, 5, 6, 7, 1])->map(fn (int $dia): array => [
                        'nombre' => mb_substr(\App\Models\Horario::DIAS[$dia], 0, 3),
                        'tramos' => $turno->horarios
                            ->filter(fn ($horario): bool => (int) $horario->dia === $dia)
                            ->sortBy(fn ($horario): string => (string) $horario->hEntrada?->format('H:i'))
                            ->map(fn ($horario): string => $horario->hEntrada?->format('H:i').'–'.$horario->hSalida?->format('H:i'))
                            ->values()
                            ->all(),
                    ]);
                @endphp
                <label class="turno-opcion" x-data="{ ver: false }" for="turno-{{ $sufijo }}-{{ $turno->id }}"
                       :class="elegido === '{{ $turno->id }}' && 'turno-opcion--elegido'">
                    <span class="turno-opcion__fila">
                        <input type="radio" name="turno_id" value="{{ $turno->id }}" id="turno-{{ $sufijo }}-{{ $turno->id }}"
                               data-nombre="{{ $turno->nombre }}" x-model="elegido" required>
                        <span>
                            <span class="turno-opcion__nombre">{{ $turno->nombre }}</span>
                            @if ($turno->sugerido)
                                <span class="pill pill--info pill--mini">Sugerido</span>
                            @endif
                            <span class="turno-opcion__datos">
                                {{ $turno->dias_cubiertos ?: 'Sin días' }}
                                · {{ rtrim(rtrim(number_format($turno->horas_semanales, 2, ',', ''), '0'), ',') }} h por semana
                            </span>
                        </span>
                        <button type="button" class="turno-opcion__ver" x-on:click.prevent="ver = ! ver" :aria-expanded="ver">
                            <span x-text="ver ? 'ocultar semana' : 'ver semana'"></span>
                        </button>
                    </span>
                    <span class="mini-semana" x-show="ver" x-cloak>
                        @foreach ($semana as $dia)
                            <span @class(['mini-semana__dia', 'mini-semana__dia--trabaja' => $dia['tramos'] !== []])>
                                <strong>{{ $dia['nombre'] }}</strong>
                                {!! $dia['tramos'] === [] ? 'libre' : collect($dia['tramos'])->map(fn ($t) => e($t))->implode('<br>') !!}
                            </span>
                        @endforeach
                    </span>
                </label>
            @endforeach
        </div>
    @endif

    @error('turno_id') <div class="error">{{ $message }}</div> @enderror
</div>
