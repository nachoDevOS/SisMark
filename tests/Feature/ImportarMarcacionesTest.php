<?php

use App\Models\Asistencia;
use App\Models\EquipoAuditoria;
use App\Services\LectorArchivoMarcaciones;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

/**
 * Líneas como las del attlog.dat que baja el ZKTeco por USB: CI con espacios
 * adelante, fecha y hora juntas, separadas por tabulación, y fin de línea CRLF.
 */
function contenidoDat(): string
{
    return implode("\r\n", [
        "  1705135\t2024-01-31 16:01:00\t1\t0\t1\t0",
        "  7591009\t2026-10-14 08:02:18\t1\t5\t1\t0",
        " 10830573\t2026-10-14 08:09:06\t1\t0\t1\t0",
    ])."\r\n";
}

function archivoTemporal(string $contenido): string
{
    $ruta = (string) tempnam(sys_get_temp_dir(), 'marcas');
    file_put_contents($ruta, $contenido);

    return $ruta;
}

test('lee el .dat del reloj: CI sin espacios y fecha y hora juntas', function (): void {
    $filas = app(LectorArchivoMarcaciones::class)->leer(archivoTemporal(contenidoDat()));

    expect($filas)->toHaveCount(3)
        ->and($filas[0]['ci'])->toBe('1705135')
        ->and($filas[0]['momento']->format('Y-m-d H:i:s'))->toBe('2024-01-31 16:01:00')
        ->and($filas[2]['ci'])->toBe('10830573')
        ->and($filas[2]['momento']->format('Y-m-d H:i:s'))->toBe('2026-10-14 08:09:06');
});

test('sigue leyendo el CSV de siempre, con su encabezado', function (): void {
    $filas = app(LectorArchivoMarcaciones::class)->leer(archivoTemporal(
        "CI/ID,Nombre,Fecha,Hora\n7633685,MOLINA GUZMAN IGNACIO,14/10/2026,08:02:11\n"
    ));

    expect($filas)->toHaveCount(1)
        ->and($filas[0]['ci'])->toBe('7633685')
        ->and($filas[0]['momento']->format('Y-m-d H:i:s'))->toBe('2026-10-14 08:02:11');
});

test('importa el .dat del reloj y no duplica lo que ya estaba', function (): void {
    Carbon::setTestNow('2026-10-15');

    $subir = fn () => $this->actingAs(superAdmin())->post(route('equipos.marcaciones.importar'), [
        'archivo' => UploadedFile::fake()->createWithContent('6236151200017_attlog.dat', contenidoDat()),
        'motivo' => 'Reloj sin red, historial bajado por USB',
        'consentimiento' => '1',
    ]);

    $subir()->assertSessionHasNoErrors()->assertSessionHas('estado');

    expect(Asistencia::query()->count())->toBe(3)
        ->and(Asistencia::query()->where('ci', '10830573')->sole()->hora->format('H:i:s'))->toBe('08:09:06');

    // Subirlo de nuevo no duplica nada.
    $subir()->assertSessionHasNoErrors();

    expect(Asistencia::query()->count())->toBe(3);
});

test('solo acepta .csv y .dat', function (string $nombre, bool $acepta): void {
    $respuesta = $this->actingAs(superAdmin())->post(route('equipos.marcaciones.importar'), [
        'archivo' => UploadedFile::fake()->createWithContent($nombre, contenidoDat()),
        'motivo' => 'Reloj sin red, historial bajado por USB',
        'consentimiento' => '1',
    ]);

    $acepta ? $respuesta->assertSessionHasNoErrors() : $respuesta->assertSessionHasErrors('archivo');
})->with([
    'dat' => ['attlog.dat', true],
    'csv' => ['marcaciones.csv', true],
    'txt' => ['marcaciones.txt', false],
    'xlsx' => ['marcaciones.xlsx', false],
]);

test('la bitácora dice qué archivo se importó', function (string $nombre, string $archivo): void {
    Carbon::setTestNow('2026-10-15');

    $this->actingAs(superAdmin())->post(route('equipos.marcaciones.importar'), [
        'archivo' => UploadedFile::fake()->createWithContent($nombre, $nombre === 'attlog.dat'
            ? contenidoDat()
            : "CI/ID,Nombre,Fecha,Hora\n7633685,MOLINA GUZMAN IGNACIO,14/10/2026,08:02:11\n"),
        'motivo' => 'Reloj sin red, historial bajado por USB',
        'consentimiento' => '1',
    ]);

    $entrada = EquipoAuditoria::query()->sole();

    expect($entrada->etiquetaAccion())->toBe('Importó archivo')
        ->and($entrada->nombreEquipo())->toBe($archivo);

    $this->get(route('equipos.auditoria'))->assertOk()->assertSee($archivo);
})->with([
    'dat' => ['attlog.dat', 'Archivo .dat (USB)'],
    'csv' => ['marcaciones.csv', 'Archivo CSV'],
]);

test('el modal de importar avisa que está importando y no se cierra mientras tanto', function (): void {
    $this->actingAs(superAdmin())
        ->get(route('equipos.index'))
        ->assertOk()
        ->assertSee('x-on:submit="importando = true"', false)
        ->assertSee('if (! importando) { abierto = false }', false)
        ->assertSee('Importando…');
});
