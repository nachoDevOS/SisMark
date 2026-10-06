<?php

use App\Models\Configuracion;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-15');
    $this->actingAs(superAdmin());
});

/**
 * Guarda la sección de licencias por la pantalla.
 */
function guardarTope(int $horas, int $minutos, string $alcance, string $desde, ?string $motivo = 'Resolución 045/2026')
{
    return test()->put(route('configuracion.update', 'licencias'), array_filter([
        'licencias_tope_permiso_mensual' => ['horas' => $horas, 'minutos' => $minutos],
        'licencias_tope_permiso_alcance' => $alcance,
        'vigente_desde' => $desde,
        'motivo' => $motivo,
    ], fn ($valor) => $valor !== null));
}

function fila(string $clave, string $desde): ?Configuracion
{
    return Configuracion::query()->where('clave', $clave)->where('vigente_desde', $desde)->first();
}

test('guarda desde el mes elegido, con motivo, y rige en adelante', function (): void {
    guardarTope(1, 30, 'contrato', '2026-10')->assertSessionHasNoErrors();

    $tope = fila(Configuracion::TOPE_PERMISO_MENSUAL, '2026-10-01');

    expect($tope->valor)->toBe('90')
        ->and($tope->motivo)->toBe('Resolución 045/2026')
        ->and($tope->vigente_hasta)->toBeNull()
        ->and($tope->getRawOriginal('vigente_desde'))->toBe('2026-10-01')
        ->and(Configuracion::valor(Configuracion::TOPE_PERMISO_MENSUAL, Carbon::parse('2027-05-10')))->toBe('90');
});

test('el motivo es obligatorio', function (): void {
    guardarTope(1, 30, 'contrato', '2026-10', null)->assertSessionHasErrors('motivo');

    expect(Configuracion::query()->count())->toBe(0);
});

test('no se carga desde un mes que ya pasó', function (): void {
    guardarTope(1, 30, 'contrato', '2026-09')->assertSessionHasErrors('vigente_desde');

    expect(Configuracion::query()->count())->toBe(0);
});

test('un mes en curso que ya tiene vigencia no se reescribe', function (): void {
    guardarTope(1, 30, 'contrato', '2026-10')->assertSessionHasNoErrors();
    guardarTope(1, 0, 'contrato', '2026-10')->assertSessionHasErrors('vigente_desde');

    expect(fila(Configuracion::TOPE_PERMISO_MENSUAL, '2026-10-01')->valor)->toBe('90');
});

test('lo programado para un mes futuro se puede reemplazar sin duplicar', function (): void {
    guardarTope(1, 0, 'contrato', '2026-12')->assertSessionHasNoErrors();
    guardarTope(0, 45, 'mes', '2026-12', 'Corrección')->assertSessionHasNoErrors();

    expect(Configuracion::query()->where('clave', Configuracion::TOPE_PERMISO_MENSUAL)->count())->toBe(1)
        ->and(fila(Configuracion::TOPE_PERMISO_MENSUAL, '2026-12-01')->valor)->toBe('45')
        ->and(fila(Configuracion::TOPE_PERMISO_MENSUAL, '2026-12-01')->motivo)->toBe('Corrección');
});

test('cargar la siguiente cierra la anterior el último día del mes previo', function (): void {
    guardarTope(1, 30, 'contrato', '2026-10');
    guardarTope(1, 0, 'mes', '2027-01');

    expect(fila(Configuracion::TOPE_PERMISO_MENSUAL, '2026-10-01')->vigente_hasta->toDateString())->toBe('2026-12-31')
        ->and(fila(Configuracion::TOPE_PERMISO_MENSUAL, '2027-01-01')->vigente_hasta)->toBeNull()
        ->and(fila(Configuracion::TOPE_PERMISO_ALCANCE, '2026-10-01')->vigente_hasta->toDateString())->toBe('2026-12-31');
});

test('una carga entre medio queda cerrada antes de la programada', function (): void {
    guardarTope(1, 30, 'contrato', '2026-10');
    guardarTope(1, 0, 'contrato', '2027-01');
    guardarTope(1, 15, 'contrato', '2026-11');

    expect(fila(Configuracion::TOPE_PERMISO_MENSUAL, '2026-10-01')->vigente_hasta->toDateString())->toBe('2026-10-31')
        ->and(fila(Configuracion::TOPE_PERMISO_MENSUAL, '2026-11-01')->vigente_hasta->toDateString())->toBe('2026-12-31')
        ->and(fila(Configuracion::TOPE_PERMISO_MENSUAL, '2027-01-01')->vigente_hasta)->toBeNull();
});

test('cada mes lee el valor que regía ese mes', function (string $fecha, ?string $esperado): void {
    guardarTope(1, 30, 'contrato', '2026-10');
    guardarTope(1, 0, 'mes', '2027-01');

    expect(Configuracion::valor(Configuracion::TOPE_PERMISO_MENSUAL, Carbon::parse($fecha)))->toBe($esperado);
})->with([
    'antes de la primera carga' => ['2026-09-30', null],
    'primer mes' => ['2026-10-01', '90'],
    'último día de la primera' => ['2026-12-31', '90'],
    'primer día de la segunda' => ['2027-01-01', '60'],
    'mucho después' => ['2030-06-15', '60'],
]);

test('el alcance sin cargar rige por defecto por contrato', function (): void {
    expect(Configuracion::vigente(Configuracion::TOPE_PERMISO_ALCANCE))->toBe(Configuracion::TOPE_POR_CONTRATO);
});

test('la pantalla muestra el historial con sus fechas y el motivo', function (): void {
    guardarTope(1, 30, 'contrato', '2026-10');
    guardarTope(1, 0, 'mes', '2027-01', 'Res. 100/2026');

    $this->get(route('configuracion.edit'))
        ->assertSuccessful()
        ->assertSeeInOrder(['01/01/2027', 'en adelante', 'Programado', '1h 00m', 'Por mes', 'Res. 100/2026'])
        ->assertSeeInOrder(['01/10/2026', '31/12/2026', 'Vigente', '1h 30m', 'Por contrato', 'Resolución 045/2026'])
        ->assertSee('Hay un cambio programado');
});

test('sin permiso no entra a la configuración', function (): void {
    $this->actingAs(usuarioCon('ViewAny:Turno'))
        ->get(route('configuracion.edit'))
        ->assertForbidden();
});
