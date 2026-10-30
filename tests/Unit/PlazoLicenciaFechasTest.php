<?php

use App\Models\ConfiguracionPlazoLicencia;
use App\Services\PlazoLicencias;
use Illuminate\Support\Carbon;

/*
| Las fechas límite del plazo para registrar licencias, sin base: hasta qué día
| atrás y hasta qué día adelante se puede pedir, en días corridos o hábiles.
*/

function plazo(int $atraso, int $anticipacion, bool $habiles = false): ConfiguracionPlazoLicencia
{
    return new ConfiguracionPlazoLicencia(['dias_atraso' => $atraso, 'dias_anticipacion' => $anticipacion, 'dias_habiles' => $habiles]);
}

test('en días corridos cuenta todos los días', function (): void {
    $miercoles = Carbon::parse('2026-10-28');

    expect(plazo(3, 7)->primeraFecha($miercoles)->toDateString())->toBe('2026-10-25')
        ->and(plazo(3, 7)->ultimaFecha($miercoles)->toDateString())->toBe('2026-11-04');
});

test('en días hábiles salta sábados y domingos', function (string $hoy, int $atraso, int $anticipacion, string $primera, string $ultima): void {
    $plazo = plazo($atraso, $anticipacion, habiles: true);

    expect($plazo->primeraFecha(Carbon::parse($hoy))->toDateString())->toBe($primera)
        ->and($plazo->ultimaFecha(Carbon::parse($hoy))->toDateString())->toBe($ultima);
})->with([
    'miércoles: 3 atrás es el viernes, 5 adelante el miércoles siguiente' => ['2026-10-28', 3, 5, '2026-10-23', '2026-11-04'],
    'lunes: 1 atrás es el viernes' => ['2026-11-02', 1, 1, '2026-10-30', '2026-11-03'],
    'sábado: 1 atrás es el viernes y 1 adelante el lunes' => ['2026-10-31', 1, 1, '2026-10-30', '2026-11-02'],
]);

test('en 0 ese lado no tiene límite', function (): void {
    $hoy = Carbon::parse('2026-10-28');

    expect(plazo(0, 7)->primeraFecha($hoy))->toBeNull()
        ->and(plazo(3, 0)->ultimaFecha($hoy))->toBeNull()
        ->and(plazo(0, 0)->limita())->toBeFalse()
        ->and(plazo(3, 0)->limita())->toBeTrue();
});

test('no modifica la fecha que recibe', function (): void {
    $hoy = Carbon::parse('2026-10-28');

    plazo(3, 7, habiles: true)->primeraFecha($hoy);

    expect($hoy->toDateString())->toBe('2026-10-28');
});

test('cada límite se lee en palabras', function (): void {
    expect(plazo(1, 3)->legible(1))->toBe('1 día corrido')
        ->and(plazo(1, 3)->legible(3))->toBe('3 días corridos')
        ->and(plazo(1, 3, habiles: true)->legible(1))->toBe('1 día hábil')
        ->and(plazo(1, 3, habiles: true)->legible(3))->toBe('3 días hábiles')
        ->and(plazo(1, 3)->legible(0))->toBe('Sin límite');
});

test('el ejemplo dice qué se podría registrar si se pidiera hoy', function (int $atraso, int $anticipacion, bool $habiles, string $dice): void {
    $ejemplo = app(PlazoLicencias::class)->ejemplo(plazo($atraso, $anticipacion, $habiles), Carbon::parse('2026-10-28'));

    expect($ejemplo)->toBe("Ejemplo con la fecha de hoy (miércoles 28/10/2026): {$dice}.");
})->with([
    '3 y 5 hábiles' => [3, 5, true, 'se puede registrar del viernes 23/10/2026 al miércoles 04/11/2026'],
    '3 y 7 corridos' => [3, 7, false, 'se puede registrar del domingo 25/10/2026 al miércoles 04/11/2026'],
    'solo hacia atrás' => [3, 0, false, 'se puede registrar desde el domingo 25/10/2026, sin límite de anticipación'],
    'solo anticipación' => [0, 7, true, 'se puede registrar hasta el viernes 06/11/2026, sin límite hacia atrás'],
    'sin límites' => [0, 0, false, 'se puede registrar cualquier fecha, sin límite'],
]);
