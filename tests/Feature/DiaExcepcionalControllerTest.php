<?php

use App\Models\DiaExcepcional;
use App\Models\User;
use App\Services\RespaldoDocumento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(asSuperAdmin());
    // Ningún test toca el bucket real: sin el disco falso, las altas que sí
    // mandan respaldo subirían un archivo a DigitalOcean.
    Storage::fake('s3');
});

/**
 * Respaldo válido para el alta. Adjuntarlo es opcional: hay tolerancias que se
 * cargan por instrucción verbal y el decreto llega después, o nunca.
 */
function respaldoDelDia(string $nombre = 'decreto.pdf'): UploadedFile
{
    return UploadedFile::fake()->create($nombre, 40, 'application/pdf');
}

/*
|--------------------------------------------------------------------------
| Listado
|--------------------------------------------------------------------------
|
| La pantalla `index` solo arma el marco: las filas las trae por AJAX la ruta
| `dias-excepcionales.list`, que devuelve la tabla como vista parcial. Por eso
| lo que se busca y se filtra se prueba contra `list` y no contra `index`.
*/

test('la pantalla del listado abre', function () {
    $this->get(route('dias-excepcionales.index'))
        ->assertOk()
        ->assertSee('Días excepcionales');
});

test('el listado muestra los días excepcionales', function () {
    DiaExcepcional::factory()->create([
        'fecha' => '2025-01-01',
        'motivoInasistencia' => 'AÑO NUEVO',
    ]);

    $this->get(route('dias-excepcionales.list'))
        ->assertOk()
        ->assertSee('AÑO NUEVO')
        ->assertSee('01/01/2025');
});

test('la búsqueda filtra por motivo', function () {
    DiaExcepcional::factory()->create(['fecha' => '2025-03-04', 'motivoInasistencia' => 'FERIADO POR CARNAVAL']);
    DiaExcepcional::factory()->create(['fecha' => '2025-12-25', 'motivoInasistencia' => 'NAVIDAD']);

    $this->get(route('dias-excepcionales.list', ['q' => 'carnaval']))
        ->assertOk()
        ->assertSee('FERIADO POR CARNAVAL')
        ->assertDontSee('NAVIDAD');
});

test('la búsqueda filtra por fecha', function () {
    DiaExcepcional::factory()->create(['fecha' => '2025-05-01', 'motivoInasistencia' => 'DIA DEL TRABAJO']);
    DiaExcepcional::factory()->create(['fecha' => '2024-11-18', 'motivoInasistencia' => 'ANIVERSARIO DEL BENI']);

    $this->get(route('dias-excepcionales.list', ['q' => '01/05/2025']))
        ->assertOk()
        ->assertSee('DIA DEL TRABAJO')
        ->assertDontSee('ANIVERSARIO DEL BENI');

    $this->get(route('dias-excepcionales.list', ['q' => '2024']))
        ->assertOk()
        ->assertSee('ANIVERSARIO DEL BENI')
        ->assertDontSee('DIA DEL TRABAJO');
});

test('registra un día excepcional nuevo', function () {
    $this->post(route('dias-excepcionales.store'), [
        'fecha' => '2025-03-04',
        'motivoInasistencia' => 'FERIADO POR CARNAVAL',
    ])
        ->assertRedirect(route('dias-excepcionales.index'))
        ->assertSessionHas('estado');

    $dia = DiaExcepcional::query()->first();

    expect($dia)->not->toBeNull()
        ->and($dia->motivoInasistencia)->toBe('FERIADO POR CARNAVAL')
        ->and($dia->fecha->format('Y-m-d'))->toBe('2025-03-04');
});

test('el alta valida fecha y motivo obligatorios', function () {
    $this->post(route('dias-excepcionales.store'), [
        'fecha' => '',
        'motivoInasistencia' => '',
    ])->assertSessionHasErrors(['fecha', 'motivoInasistencia']);

    expect(DiaExcepcional::query()->count())->toBe(0);
});

test('el alta rechaza una fecha repetida', function () {
    DiaExcepcional::factory()->create(['fecha' => '2025-05-01']);

    $this->post(route('dias-excepcionales.store'), [
        'fecha' => '2025-05-01',
        'motivoInasistencia' => 'DÍA DEL TRABAJO',
    ])->assertSessionHasErrors('fecha');

    expect(DiaExcepcional::query()->count())->toBe(1);
});

test('muestra el formulario de edición con los datos actuales', function () {
    $dia = DiaExcepcional::factory()->create([
        'fecha' => '2024-12-25',
        'motivoInasistencia' => 'NAVIDAD',
    ]);

    $this->get(route('dias-excepcionales.edit', $dia))
        ->assertOk()
        ->assertSee('NAVIDAD')
        ->assertSee('2024-12-25');
});

test('actualiza un día excepcional', function () {
    $dia = DiaExcepcional::factory()->create([
        'fecha' => '2024-11-18',
        'motivoInasistencia' => 'ANIVERSARIO',
    ]);

    $this->put(route('dias-excepcionales.update', $dia), [
        'fecha' => '2024-11-18',
        'motivoInasistencia' => 'ANIVERSARIO DEL BENI',
    ])
        ->assertRedirect(route('dias-excepcionales.index'))
        ->assertSessionHas('estado');

    expect($dia->refresh()->motivoInasistencia)->toBe('ANIVERSARIO DEL BENI');
});

test('elimina un día excepcional de forma lógica y registra quién lo borró', function () {
    $admin = asSuperAdmin();
    $this->actingAs($admin);

    $dia = DiaExcepcional::factory()->create();

    $this->delete(route('dias-excepcionales.destroy', $dia), ['deleteObservacion' => 'Cargado por error.'])
        ->assertRedirect(route('dias-excepcionales.index'));

    expect(DiaExcepcional::query()->whereKey($dia->getKey())->exists())->toBeFalse();

    $borrado = DiaExcepcional::onlyTrashed()->find($dia->getKey());

    expect($borrado)->not->toBeNull()
        ->and($borrado->deleteUser_id)->toBe($admin->id)
        ->and($borrado->deleteObservacion)->toBe('Cargado por error.');
});

test('un invitado no puede ver los días excepcionales', function () {
    auth()->logout();

    $this->get(route('dias-excepcionales.index'))->assertRedirect();
});

test('un usuario sin permiso no puede entrar al listado', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('dias-excepcionales.index'))->assertForbidden();
});

test('se registra un día excepcional sin respaldo', function () {
    // El respaldo es opcional: hay tolerancias que se cargan por instrucción
    // verbal y el decreto llega después.
    $this->post(route('dias-excepcionales.store'), [
        'fecha' => '2025-03-04',
        'motivoInasistencia' => 'FERIADO POR CARNAVAL',
    ])->assertSessionHasNoErrors();

    $dia = DiaExcepcional::query()->sole();

    expect($dia->adjunto)->toBeNull()
        ->and($dia->adjuntoNombre)->toBeNull();
});

test('sube el respaldo al bucket y guarda la ruta y el nombre original', function () {
    $this->post(route('dias-excepcionales.store'), [
        'fecha' => '2025-03-04',
        'motivoInasistencia' => 'FERIADO POR CARNAVAL',
        'respaldo' => respaldoDelDia('decreto departamental.pdf'),
    ])->assertSessionHasNoErrors();

    $dia = DiaExcepcional::query()->sole();

    // La ruta se ordena por año y el nombre en el bucket es aleatorio: los
    // archivos que sube la gente traen tildes y espacios.
    expect($dia->adjunto)->toStartWith('dias-excepcionales/'.now()->format('Y').'/')
        ->and($dia->adjunto)->not->toContain(' ')
        ->and($dia->adjuntoNombre)->toBe('decreto departamental.pdf');

    Storage::disk(RespaldoDocumento::DISCO)->assertExists($dia->adjunto);
});

test('rechaza un respaldo que no sea imagen ni PDF', function () {
    $this->post(route('dias-excepcionales.store'), [
        'fecha' => '2025-03-04',
        'motivoInasistencia' => 'FERIADO POR CARNAVAL',
        'respaldo' => UploadedFile::fake()->create('planilla.xlsx', 50),
    ])->assertSessionHasErrors('respaldo');

    expect(DiaExcepcional::query()->count())->toBe(0);
});

test('rechaza un respaldo de más de 5 MB', function () {
    $this->post(route('dias-excepcionales.store'), [
        'fecha' => '2025-03-04',
        'motivoInasistencia' => 'FERIADO POR CARNAVAL',
        'respaldo' => UploadedFile::fake()->create('escaneo.pdf', 6000, 'application/pdf'),
    ])->assertSessionHasErrors('respaldo');

    expect(DiaExcepcional::query()->count())->toBe(0);
});

test('editar sin elegir archivo deja el respaldo que ya estaba', function () {
    $dia = DiaExcepcional::factory()->create([
        'fecha' => '2025-03-04',
        'adjunto' => 'dias-excepcionales/2025/original.pdf',
        'adjuntoNombre' => 'decreto.pdf',
    ]);

    $this->put(route('dias-excepcionales.update', $dia), [
        'fecha' => '2025-03-04',
        'motivoInasistencia' => 'FERIADO POR CARNAVAL (CORREGIDO)',
    ])->assertSessionHasNoErrors();

    expect($dia->fresh()->adjunto)->toBe('dias-excepcionales/2025/original.pdf')
        ->and($dia->fresh()->adjuntoNombre)->toBe('decreto.pdf')
        ->and($dia->fresh()->motivoInasistencia)->toBe('FERIADO POR CARNAVAL (CORREGIDO)');
});

test('editar con archivo nuevo reemplaza el anterior y lo borra del bucket', function () {
    Storage::disk(RespaldoDocumento::DISCO)->put('dias-excepcionales/2025/viejo.pdf', 'contenido');

    $dia = DiaExcepcional::factory()->create([
        'fecha' => '2025-03-04',
        'adjunto' => 'dias-excepcionales/2025/viejo.pdf',
        'adjuntoNombre' => 'decreto.pdf',
    ]);

    $this->put(route('dias-excepcionales.update', $dia), [
        'fecha' => '2025-03-04',
        'motivoInasistencia' => 'FERIADO POR CARNAVAL',
        'respaldo' => respaldoDelDia('resolución nueva.pdf'),
    ])->assertSessionHasNoErrors();

    $dia->refresh();

    // A diferencia de las licencias, el archivo es de una sola fila: nadie más
    // queda apuntando a él, así que el viejo se borra.
    expect($dia->adjunto)->not->toBe('dias-excepcionales/2025/viejo.pdf')
        ->and($dia->adjuntoNombre)->toBe('resolución nueva.pdf');

    Storage::disk(RespaldoDocumento::DISCO)->assertExists($dia->adjunto);
    Storage::disk(RespaldoDocumento::DISCO)->assertMissing('dias-excepcionales/2025/viejo.pdf');
});

test('la baja lógica no borra el respaldo del bucket', function () {
    Storage::disk(RespaldoDocumento::DISCO)->put('dias-excepcionales/2025/decreto.pdf', 'contenido');

    $dia = DiaExcepcional::factory()->create(['adjunto' => 'dias-excepcionales/2025/decreto.pdf']);

    $this->delete(route('dias-excepcionales.destroy', $dia))->assertRedirect();

    // La fila se puede restaurar, con su documento incluido.
    Storage::disk(RespaldoDocumento::DISCO)->assertExists('dias-excepcionales/2025/decreto.pdf');
});

test('el listado enlaza el respaldo solo cuando el día lo tiene', function () {
    $conRespaldo = DiaExcepcional::factory()->create(['adjunto' => 'dias-excepcionales/2025/decreto.pdf']);
    $sinRespaldo = DiaExcepcional::factory()->create(['adjunto' => null]);

    $this->get(route('dias-excepcionales.list'))
        ->assertOk()
        ->assertSee(route('dias-excepcionales.respaldo', $conRespaldo), escape: false)
        ->assertDontSee(route('dias-excepcionales.respaldo', $sinRespaldo), escape: false);
});

test('la descarga del respaldo redirige al enlace firmado del bucket', function () {
    Storage::disk(RespaldoDocumento::DISCO)->put('dias-excepcionales/2025/decreto.pdf', 'contenido');

    $dia = DiaExcepcional::factory()->create(['adjunto' => 'dias-excepcionales/2025/decreto.pdf']);

    // El archivo no se sirve por el sistema ni es público: se comprueba el
    // permiso y recién ahí se pide al bucket una URL de vida corta.
    $this->get(route('dias-excepcionales.respaldo', $dia))->assertRedirect();
});

test('la descarga del respaldo avisa cuando el día no tiene archivo', function () {
    $dia = DiaExcepcional::factory()->create(['adjunto' => null]);

    $this->get(route('dias-excepcionales.respaldo', $dia))
        ->assertRedirect()
        ->assertSessionHas('error');
});

test('un usuario sin permiso de ver no puede descargar el respaldo', function () {
    $dia = DiaExcepcional::factory()->create(['adjunto' => 'dias-excepcionales/2025/decreto.pdf']);

    $this->actingAs(User::factory()->create());

    $this->get(route('dias-excepcionales.respaldo', $dia))->assertForbidden();
});
