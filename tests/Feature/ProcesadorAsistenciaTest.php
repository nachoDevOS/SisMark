<?php

use App\Models\AsignacionHorario;
use App\Models\Asistencia;
use App\Models\Licencia;
use App\Models\Persona;
use App\Services\AsignadorTurnos;
use App\Services\ProcesadorAsistencia as P;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-26');

    // Antes del turno: lo heredado del SIA, lunes de 09:00 a 17:00.
    AsignacionHorario::factory()->create([
        'ci' => '111', 'horario_id' => horario(2, '09:00', '17:00')->id,
        'desde' => '2026-01-01', 'hasta' => '2026-12-31',
    ]);

    // Desde el lunes 19: el turno de lunes a viernes de 08:00 a 16:00. Corta lo heredado al 18.
    app(AsignadorTurnos::class)->asignar('111', turnoLunesAViernes(), Carbon::parse('2026-10-19'), Carbon::parse('2026-12-31'));
});

/**
 * @param  list<string>  $horas
 */
function marcar(string $fecha, array $horas): void
{
    foreach ($horas as $hora) {
        Asistencia::factory()->create(['ci' => '111', 'fecha' => $fecha, 'hora' => "{$hora}:00"]);
    }
}

/**
 * Estado de cada día del rango, por fecha. Sin contratos conocidos (Mamoré sin
 * configurar), se procesa todo el rango.
 *
 * @return array<string, string>
 */
function estados(string $desde, string $hasta): array
{
    return app(P::class)
        ->procesarConTramos('111', Carbon::parse($desde), Carbon::parse($hasta), null)
        ->mapWithKeys(fn (array $dia): array => [$dia['fecha']->toDateString() => $dia['estado']])
        ->all();
}

test('con turno, cada día se procesa con los horarios del turno', function (): void {
    marcar('2026-10-19', ['07:55', '16:05']);   // lunes: a horario
    marcar('2026-10-21', ['08:40', '16:05']);   // miércoles: llega tarde
    // martes 20 sin marcas

    $estados = estados('2026-10-19', '2026-10-25');

    expect($estados['2026-10-19'])->toBe(P::CUMPLE)
        ->and($estados['2026-10-20'])->toBe(P::FALTA)
        ->and($estados['2026-10-21'])->toBe(P::ATRASO)
        ->and($estados['2026-10-24'])->toBe(P::NO_LABORABLE)
        ->and($estados['2026-10-25'])->toBe(P::NO_LABORABLE);
});

test('antes del turno se sigue procesando con lo heredado', function (): void {
    // Lunes 12: con el horario viejo (09:00 a 17:00), entrar 09:00 es a horario.
    marcar('2026-10-12', ['08:55', '17:05']);

    expect(estados('2026-10-12', '2026-10-12')['2026-10-12'])->toBe(P::CUMPLE);

    // El martes 13 el horario viejo no tenía nada: no laborable.
    expect(estados('2026-10-13', '2026-10-13')['2026-10-13'])->toBe(P::NO_LABORABLE);
});

test('el lunes del turno no se mezcla con el horario viejo', function (): void {
    // Con el turno, el lunes 19 es de 08:00 a 16:00: el bloque de 09:00 ya no existe.
    marcar('2026-10-19', ['07:55', '16:05']);

    $dia = app(P::class)
        ->procesarConTramos('111', Carbon::parse('2026-10-19'), Carbon::parse('2026-10-19'), null)
        ->first();

    expect($dia['bloques'])->toHaveCount(1)
        ->and($dia['bloques'][0]['horario']->hEntrada->format('H:i'))->toBe('08:00');
});

test('la vista previa del reporte procesado cierra con los totales del rango, como el imprimible', function (): void {
    Persona::factory()->create(['ci' => '111']);
    marcar('2026-10-20', ['08:25', '16:05']);

    $parametros = ['persona' => '111', 'desde' => '2026-10-19', 'hasta' => '2026-10-23'];

    $this->actingAs(superAdmin())
        ->get(route('reportes.marcaciones.procesado.generar', $parametros))
        ->assertOk()
        ->assertSeeInOrder(['Total:', '25 min']);

    $this->get(route('reportes.marcaciones.procesado.generar', $parametros + ['print' => 1]))
        ->assertOk()
        ->assertSeeInOrder(['Total:', '25 min']);
});

test('con dos licencias el mismo día, cada una va en su renglón dentro de las columnas de licencia', function (): void {
    Persona::factory()->create(['ci' => '111']);
    $lunes = AsignacionHorario::query()->whereNotNull('asignacion_turno_id')->with('horario')->get()
        ->first(fn ($fila): bool => (int) $fila->horario->dia === 2)->horario;
    $hora = fn (string $hm): string => "1899-12-30 {$hm}:00";

    Licencia::factory()->create([
        'ci' => '111', 'fecha' => '2026-10-19', 'horario_id' => $lunes->id, 'tCompleto' => false,
        'lEntra' => $hora('08:00'), 'lSale' => $hora('12:00'), 'goceHaberes' => true, 'motivo' => 'TRÁMITE', 'tipo' => Licencia::TIPO_PERSONAL,
    ]);
    Licencia::factory()->create([
        'ci' => '111', 'fecha' => '2026-10-19', 'horario_id' => $lunes->id, 'tCompleto' => false,
        'lEntra' => $hora('12:00'), 'lSale' => $hora('16:00'), 'goceHaberes' => false, 'motivo' => 'DÍA DEL PADRE', 'tipo' => Licencia::TIPO_PERSONAL,
    ]);

    $parametros = ['persona' => '111', 'desde' => '2026-10-19', 'hasta' => '2026-10-19'];
    $lineas = [
        '<div class="lic-linea">08:00</div><div class="lic-linea">12:00</div>',
        '<div class="lic-linea">12:00</div><div class="lic-linea">16:00</div>',
        '<div class="lic-linea">No</div><div class="lic-linea">No</div>',
        '<div class="lic-linea">Sí</div><div class="lic-linea">No</div>',
        'TRÁMITE', 'DÍA DEL PADRE',
    ];

    // Cada columna, una línea por licencia, en el mismo orden: se leen de punta a punta.
    $this->actingAs(superAdmin())
        ->get(route('reportes.marcaciones.procesado.generar', $parametros))
        ->assertOk()
        ->assertSeeInOrder($lineas, false);

    // El imprimible, igual.
    $this->get(route('reportes.marcaciones.procesado.generar', $parametros + ['print' => 1]))
        ->assertOk()
        ->assertSeeInOrder($lineas, false);

    // El Excel: un renglón por licencia dentro de cada celda, con ajuste de texto.
    $respuesta = $this->get(route('reportes.marcaciones.procesado.generar', $parametros + ['print' => 2]))->assertOk();
    $hoja = IOFactory::load($respuesta->baseResponse->getFile()->getPathname())->getActiveSheet();
    $fila = collect($hoja->getRowIterator())
        ->map(fn ($renglon): int => $renglon->getRowIndex())
        ->first(fn (int $numero): bool => str_contains((string) $hoja->getCell("M{$numero}")->getValue(), 'TRÁMITE'));

    expect($fila)->not->toBeNull()
        ->and($hoja->getCell("I{$fila}")->getValue())->toBe("08:00\n12:00")
        ->and($hoja->getCell("J{$fila}")->getValue())->toBe("12:00\n16:00")
        ->and($hoja->getCell("K{$fila}")->getValue())->toBe("No\nNo")
        ->and($hoja->getCell("L{$fila}")->getValue())->toBe("Sí\nNo")
        ->and($hoja->getCell("M{$fila}")->getValue())->toBe("TRÁMITE\nDÍA DEL PADRE")
        ->and($hoja->getStyle("I{$fila}")->getAlignment()->getWrapText())->toBeTrue();
});
