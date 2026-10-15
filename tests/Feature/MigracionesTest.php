<?php

use Illuminate\Support\Facades\Schema;

test('todas las migraciones levantan la base de cero', function (): void {
    expect(Schema::hasTable('turnos'))->toBeTrue()
        ->and(Schema::hasTable('horario_turno'))->toBeFalse()
        ->and(Schema::hasTable('horario_turno_respaldo'))->toBeFalse()
        ->and(Schema::hasColumn('horarios', 'turno_id'))->toBeTrue()
        ->and(collect(Schema::getColumns('horarios'))->firstWhere('name', 'idHorario')['nullable'])->toBeTrue()
        ->and(collect(Schema::getColumns('asignacion_horarios'))->firstWhere('name', 'idHorario')['nullable'])->toBeTrue()
        ->and(collect(Schema::getIndexes('asignacion_horarios'))->contains(fn (array $indice): bool => $indice['unique'] && $indice['columns'] === ['ci', 'horario_id', 'desde']))->toBeTrue()
        ->and(Schema::hasTable('asignacion_turnos'))->toBeTrue()
        ->and(Schema::hasColumns('asignacion_horarios', ['asignacion_turno_id', 'desde', 'hasta']))->toBeTrue()
        ->and(Schema::hasColumns('configuraciones', ['vigente_desde', 'vigente_hasta', 'motivo']))->toBeTrue()
        ->and(Schema::hasColumn('horarios', 'sugerido'))->toBeFalse();
});
