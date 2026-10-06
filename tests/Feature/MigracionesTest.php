<?php

use Illuminate\Support\Facades\Schema;

test('todas las migraciones levantan la base de cero', function (): void {
    expect(Schema::hasTable('turnos'))->toBeTrue()
        ->and(Schema::hasTable('horario_turno'))->toBeTrue()
        ->and(Schema::hasTable('asignacion_turnos'))->toBeTrue()
        ->and(Schema::hasColumns('asignacion_horarios', ['asignacion_turno_id', 'desde', 'hasta']))->toBeTrue()
        ->and(Schema::hasColumns('configuraciones', ['vigente_desde', 'vigente_hasta', 'motivo']))->toBeTrue()
        ->and(Schema::hasColumn('horarios', 'sugerido'))->toBeFalse();
});
