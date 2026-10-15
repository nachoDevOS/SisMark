<?php

use App\Models\AsignacionHorario;
use App\Models\AsignacionTurno;
use App\Models\Asistencia;
use App\Models\Horario;
use App\Models\Licencia;
use App\Models\Turno;
use App\Services\ProcesadorAsistencia as P;
use App\Services\SeparadorHorariosDeTurnos;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
| Escenario con la estructura vieja (turno ↔ horario por pivote):
|
| - «lunes» está en dos turnos (A y B) y además lo usa una asignación del SIA.
| - «martes» solo en el turno A; «miércoles» solo en el B.
| - 222 tiene el turno A; 333 el B (y una asignación anterior dada de baja).
| - 222 tiene licencia el lunes 12; 111 (SIA) tiene licencia en «lunes» también.
*/
beforeEach(function (): void {
    Carbon::setTestNow('2026-10-26');

    $this->lunes = horario(2);
    $this->martes = horario(3);
    $this->miercoles = horario(4);

    $this->turnoA = turnoConPivote('A', [$this->lunes, $this->martes]);
    $this->turnoB = turnoConPivote('B', [$this->lunes, $this->miercoles]);

    asignarConDetalle('222', $this->turnoA, [$this->lunes, $this->martes], '2026-10-01', '2026-12-31');
    asignarConDetalle('333', $this->turnoB, [$this->lunes, $this->miercoles], '2026-10-01', '2026-12-31');
    asignarConDetalle('333', $this->turnoB, [$this->lunes, $this->miercoles], '2026-09-01', '2026-09-30')->delete();

    AsignacionHorario::factory()->create([
        'ci' => '111', 'horario_id' => $this->lunes->id, 'idHorario' => $this->lunes->idHorario,
        'desde' => '2026-01-01', 'hasta' => '2026-12-31',
    ]);

    Licencia::factory()->create(['ci' => '222', 'fecha' => '2026-10-12', 'horario_id' => $this->lunes->id, 'tipo' => Licencia::TIPO_INSTITUCIONAL]);
    Licencia::factory()->create(['ci' => '111', 'fecha' => '2026-10-12', 'horario_id' => $this->lunes->id, 'tipo' => Licencia::TIPO_INSTITUCIONAL]);

    foreach (['222', '333', '111'] as $ci) {
        Asistencia::factory()->create(['ci' => $ci, 'fecha' => '2026-10-05', 'hora' => '07:55:00']);
        Asistencia::factory()->create(['ci' => $ci, 'fecha' => '2026-10-05', 'hora' => '16:05:00']);
        Asistencia::factory()->create(['ci' => $ci, 'fecha' => '2026-10-06', 'hora' => '08:40:00']);
    }
});

function turnoConPivote(string $nombre, array $horarios): Turno
{
    $turno = Turno::factory()->create(['nombre' => $nombre]);

    foreach ($horarios as $horario) {
        DB::table('horario_turno_respaldo')->insert([
            'turno_id' => $turno->id, 'horario_id' => $horario->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return $turno;
}

function asignarConDetalle(string $ci, Turno $turno, array $horarios, string $desde, string $hasta): AsignacionTurno
{
    $asignacion = AsignacionTurno::factory()->create(['ci' => $ci, 'turno_id' => $turno->id, 'desde' => $desde, 'hasta' => $hasta]);

    foreach ($horarios as $horario) {
        AsignacionHorario::factory()->create([
            'ci' => $ci, 'horario_id' => $horario->id, 'idHorario' => $horario->idHorario,
            'asignacion_turno_id' => $asignacion->id, 'desde' => $desde, 'hasta' => $hasta,
        ]);
    }

    return $asignacion;
}

/**
 * Estado y bloques de cada día de octubre, sin ids: lo que ve un reporte.
 *
 * @return array<string, array{estado: string, bloques: list<array{entrada: string, salida: string, estado: string}>}>
 */
function procesadoDeOctubre(string $ci): array
{
    return app(P::class)
        ->procesarConTramos($ci, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-25'), null)
        ->mapWithKeys(fn (array $dia): array => [$dia['fecha']->toDateString() => [
            'estado' => $dia['estado'],
            'bloques' => collect($dia['bloques'])->map(fn (array $bloque): array => [
                'entrada' => $bloque['horario']->hEntrada->format('H:i'),
                'salida' => $bloque['horario']->hSalida->format('H:i'),
                'estado' => $bloque['estado'],
            ])->all(),
        ]])
        ->all();
}

test('la asistencia procesada da exactamente lo mismo antes y después de separar', function (): void {
    $antes = collect(['222', '333', '111'])->mapWithKeys(fn (string $ci): array => [$ci => procesadoDeOctubre($ci)]);

    // La licencia del lunes 12 sí pesa: si no se repuntara, ese día saldría como falta.
    expect($antes['222']['2026-10-12']['estado'])->toBe(P::LICENCIA);

    app(SeparadorHorariosDeTurnos::class)->separar('horario_turno_respaldo');

    $despues = collect(['222', '333', '111'])->mapWithKeys(fn (string $ci): array => [$ci => procesadoDeOctubre($ci)]);

    expect($despues->all())->toBe($antes->all());
});

test('cada turno queda con copias propias y lo de cada turno apunta a sus copias', function (): void {
    $resumen = app(SeparadorHorariosDeTurnos::class)->separar('horario_turno_respaldo');

    expect($resumen['copias'])->toBe(4);

    foreach ([$this->turnoA, $this->turnoB] as $turno) {
        $propios = $turno->horarios()->pluck('id');

        expect($propios)->toHaveCount(2)
            ->and($propios)->not->toContain($this->lunes->id);

        $detalle = AsignacionHorario::withTrashed()
            ->whereIn('asignacion_turno_id', AsignacionTurno::withTrashed()->where('turno_id', $turno->id)->select('id'))
            ->get();

        expect($detalle->pluck('horario_id')->diff($propios))->toBeEmpty();

        // Las copias nacen sin código del SIA; las filas repuntadas conservan el suyo.
        expect($turno->horarios()->whereNotNull('idHorario')->count())->toBe(0);
        $detalle->each(fn (AsignacionHorario $fila) => expect($fila->idHorario)->not->toBeNull());
    }

    $copiaDelLunesEnA = $this->turnoA->horarios()->where('dia', '2')->value('id');

    expect(Licencia::query()->where('ci', '222')->value('horario_id'))->toBe($copiaDelLunesEnA)
        ->and(Licencia::query()->where('ci', '111')->value('horario_id'))->toBe($this->lunes->id);
});

test('lo del SIA no se toca y el original que quedó sin uso se da de baja', function (): void {
    app(SeparadorHorariosDeTurnos::class)->separar('horario_turno_respaldo');

    // «lunes» lo sigue usando la asignación del SIA de 111: queda vivo.
    expect($this->lunes->fresh()->trashed())->toBeFalse()
        ->and(AsignacionHorario::query()->where('ci', '111')->value('horario_id'))->toBe($this->lunes->id);

    // «martes» y «miércoles» ya no los usa nadie.
    foreach ([$this->martes, $this->miercoles] as $original) {
        $original = Horario::withTrashed()->find($original->id);

        expect($original->trashed())->toBeTrue()
            ->and($original->deleteObservacion)->toStartWith(SeparadorHorariosDeTurnos::OBSERVACION_BAJA);
    }
});

test('correrlo dos veces no duplica nada', function (): void {
    $separador = app(SeparadorHorariosDeTurnos::class);
    $separador->separar('horario_turno_respaldo');
    $horarios = Horario::withTrashed()->count();

    expect($separador->separar('horario_turno_respaldo')['copias'])->toBe(0)
        ->and(Horario::withTrashed()->count())->toBe($horarios);
});

test('revertir deja todo como estaba', function (): void {
    $detalleAntes = AsignacionHorario::withTrashed()->orderBy('id')->get(['id', 'horario_id', 'idHorario'])->toArray();
    $licenciasAntes = Licencia::query()->orderBy('id')->pluck('horario_id', 'id')->all();
    $horariosAntes = Horario::withTrashed()->orderBy('id')->pluck('id')->all();

    $separador = app(SeparadorHorariosDeTurnos::class);
    $separador->separar('horario_turno_respaldo');
    $separador->revertir('horario_turno_respaldo');

    expect(AsignacionHorario::withTrashed()->orderBy('id')->get(['id', 'horario_id', 'idHorario'])->toArray())->toBe($detalleAntes)
        ->and(Licencia::query()->orderBy('id')->pluck('horario_id', 'id')->all())->toBe($licenciasAntes)
        ->and(Horario::withTrashed()->orderBy('id')->pluck('id')->all())->toBe($horariosAntes)
        ->and(Horario::onlyTrashed()->count())->toBe(0);
});

test('aborta si una asignación de turno usa un horario que no es de ese turno', function (): void {
    $ajeno = horario(6);
    asignarConDetalle('444', $this->turnoA, [$ajeno], '2026-10-01', '2026-12-31');

    DB::beginTransaction();

    expect(fn () => app(SeparadorHorariosDeTurnos::class)->separar('horario_turno_respaldo'))
        ->toThrow(RuntimeException::class, 'no es de su turno');

    DB::rollBack();
});
