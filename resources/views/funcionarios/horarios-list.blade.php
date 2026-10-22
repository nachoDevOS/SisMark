@php
    $pillPorSituacion = ['vigente' => 'pill--ok', 'vencida' => 'pill--no', 'futura' => 'pill--info'];
    $etiquetaSituacion = ['vigente' => 'Vigente', 'vencida' => 'Vencida', 'futura' => 'Aún no vigente'];
    // Sin acciones cuando la tabla se muestra solo de referencia (modal de licencia).
    $conAcciones = $conAcciones ?? true;
    $columnas = $conAcciones ? 3 : 2;
    $contratos = $contratos ?? [];

    /**
     * El nombre del horario del SIA suele ser «LUN: 08:00 - 16:00», que es día y
     * horario otra vez. Se muestra solo cuando aporta algo que la fila no dice.
     */
    $nombreQueAporta = function (?string $nombre, ?string $entrada, ?string $salida): string {
        $nombre = trim((string) $nombre);

        if ($nombre === '' || ($entrada !== null && $salida !== null
            && str_contains($nombre, $entrada) && str_contains($nombre, $salida))) {
            return '';
        }

        return $nombre;
    };
@endphp
<style>
    .periodo__turno { font-weight: 700; }
    .periodo__turno--sia { color: var(--muted); font-weight: 600; }
    .periodo__separador { color: var(--muted); }
    .horario__tolerancia { margin-left: .6rem; color: var(--muted); font-size: .72rem; }
    tbody tr.fila--contrato th { text-align: left; background: var(--card); border-top: 2px solid var(--border);
        padding: 0; text-transform: none; font-size: .82rem; }
    tbody:first-of-type tr.fila--contrato th { border-top: 0; }
    .contrato-cabecera { display: flex; align-items: center; gap: .45rem; flex-wrap: wrap; width: 100%;
        background: none; border: 0; padding: .55rem .75rem; font: inherit; color: var(--fg); text-align: left; cursor: pointer; }
    .contrato-cabecera:hover { background: var(--bg); }
    .contrato-cabecera svg { width: 1rem; height: 1rem; color: var(--muted); flex-shrink: 0; }
    .contrato-cabecera__flecha { display: inline-flex; transition: transform .15s; }
    .contrato-cabecera__flecha--abierta { transform: rotate(90deg); }
    .contrato-cabecera__datos { color: var(--muted); font-weight: 400; font-size: .75rem; }
    .contrato-cabecera__cantidad { margin-left: auto; color: var(--muted); font-weight: 400; font-size: .72rem; }
</style>
<div class="card">
    <table class="tabla--compacta">
        <thead>
            <tr>
                <th>Día</th>
                <th>Horario</th>
                @if ($conAcciones)
                    <th></th>
                @endif
            </tr>
        </thead>
            @php
                // Los turnos se agrupan bajo su contrato (en el orden en que
                // aparecen); los horarios del sistema anterior, que no tienen
                // contrato, van después, sueltos.
                $porContrato = collect();
                $sueltos = collect();

                foreach ($periodos as $periodo) {
                    $delPeriodo = $asignaciones[$periodo->clave_periodo] ?? collect();
                    // El período es de un turno cuando sus filas son el detalle de
                    // una misma asignación de turno: ahí se concluye y se elimina
                    // el turno entero, no cada día.
                    $turnos = $delPeriodo->pluck('asignacionTurno')->filter()->unique('id');
                    $asignacionTurno = $turnos->count() === 1 && $delPeriodo->every(fn ($fila) => $fila->es_de_turno)
                        ? $turnos->first()
                        : null;
                    $bloque = ['periodo' => $periodo, 'delPeriodo' => $delPeriodo, 'asignacionTurno' => $asignacionTurno];

                    if ($asignacionTurno?->contrato_id) {
                        $porContrato->getOrPut($asignacionTurno->contrato_id, fn () => collect())->push($bloque);
                    } else {
                        $sueltos->push($bloque);
                    }
                }
            @endphp
            {{-- Cada contrato es un <tbody> que se pliega con su flecha: el
                 vigente abierto, los concluidos plegados. --}}
            @foreach ($porContrato as $contratoId => $bloques)
                @php
                    $contrato = $contratos[$contratoId] ?? null;
                    $abierto = ! ($contrato['regularizar'] ?? false);
                @endphp
                <tbody x-data="{ abierto: @js($abierto) }">
                <tr class="fila--contrato">
                    <th colspan="{{ $columnas }}" scope="colgroup">
                        <button type="button" class="contrato-cabecera" x-on:click="abierto = ! abierto" :aria-expanded="abierto">
                            <span class="contrato-cabecera__flecha" :class="abierto && 'contrato-cabecera__flecha--abierta'">
                                <x-heroicon-o-chevron-right />
                            </span>
                            <x-heroicon-o-document-text />
                            <strong>{{ $contrato['codigo'] ?? 'Contrato #'.$contratoId }}</strong>
                            @if ($contrato)
                                @if ($contrato['regularizar'])
                                    <span class="pill pill--mini pill--neutro">Concluido</span>
                                @elseif ($contrato['desde']->isAfter(today()))
                                    <span class="pill pill--mini pill--info">Empieza el {{ $contrato['desde']->format('d/m/Y') }}</span>
                                @else
                                    <span class="pill pill--mini pill--ok">Vigente</span>
                                @endif
                                <span class="contrato-cabecera__datos">
                                    {{ $contrato['desde']->format('d/m/Y') }} al {{ $contrato['hasta']?->format('d/m/Y') ?? 'sin fecha de fin' }}
                                </span>
                            @endif
                            <span class="contrato-cabecera__cantidad">{{ $bloques->count() }} {{ $bloques->count() === 1 ? 'turno' : 'turnos' }}</span>
                        </button>
                    </th>
                </tr>
                @foreach ($bloques as $bloque)
                    @include('funcionarios._periodo-horarios', [...$bloque, 'enContrato' => true, 'abierto' => $abierto])
                @endforeach
                </tbody>
            @endforeach
            <tbody>
                @foreach ($sueltos as $bloque)
                    @include('funcionarios._periodo-horarios', [...$bloque, 'enContrato' => false])
                @endforeach
                @if ($periodos->isEmpty())
                    <tr><td colspan="{{ $columnas }}" class="vacio">El funcionario no tiene horarios asignados en este filtro.</td></tr>
                @endif
            </tbody>
    </table>
</div>

<div class="paginacion">{{ $periodos->links() }}</div>
