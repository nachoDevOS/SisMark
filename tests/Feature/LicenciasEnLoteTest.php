<?php

use App\Models\Configuracion;
use App\Models\Licencia;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-20');
});

/**
 * Una solicitud de un día para el carnet dado.
 *
 * @param  array<string, mixed>  $datos
 */
function solicitudDe(string $ci, string $estado = Licencia::PENDIENTE, array $datos = []): Licencia
{
    return Licencia::factory()->create($datos + [
        'ci' => $ci, 'fecha' => '2026-10-12', 'estado' => $estado, 'tipo' => Licencia::TIPO_INSTITUCIONAL,
    ]);
}

/**
 * Los filtros con que se arma la tabla, como los manda la pantalla.
 *
 * @return array<string, string>
 */
function filtrosDeOctubre(): array
{
    return ['estado' => Licencia::PENDIENTE, 'desde' => '2026-10-01', 'hasta' => '2026-10-31'];
}

test('la tabla trae casillas solo en lo pendiente y cuenta las pendientes de todo el filtro', function (): void {
    $pendiente = solicitudDe('111');
    $aprobada = solicitudDe('222', Licencia::APROBADO);
    // Otra pendiente que no entra en la primera página.
    solicitudDe('333');

    $this->actingAs(superAdmin())
        ->get(route('licencias.list', ['desde' => '2026-10-01', 'hasta' => '2026-10-31', 'por_pagina' => 10]))
        ->assertOk()
        ->assertSee('id="lote-datos"', false)
        ->assertSee('data-solicitudes="2"', false)
        ->assertSee('class="lote-check" value="'.$pendiente->id.'"', false)
        ->assertDontSee('class="lote-check" value="'.$aprobada->id.'"', false);
});

test('sin permiso de aprobar no hay casillas ni se puede resolver en lote', function (): void {
    $pendiente = solicitudDe('111');
    $this->actingAs(usuarioCon('ViewAny:Licencia'));

    $this->get(route('licencias.list'))->assertOk()->assertDontSee('lote-check');
    $this->get(route('licencias.index'))->assertOk()->assertDontSee(route('licencias.lote.aprobar'));

    $this->patch(route('licencias.lote.aprobar'), ['solicitudes' => [$pendiente->id]])->assertForbidden();
    expect($pendiente->fresh()->estado)->toBe(Licencia::PENDIENTE);
});

test('aprueba las marcadas, deja el resto y vuelve al listado con el mismo filtro', function (): void {
    $primera = solicitudDe('111');
    $segunda = solicitudDe('222');
    $sinMarcar = solicitudDe('333');

    $this->actingAs(superAdmin())
        ->patch(route('licencias.lote.aprobar'), ['solicitudes' => [$primera->id, $segunda->id]] + filtrosDeOctubre())
        ->assertRedirect(route('licencias.index', filtrosDeOctubre()))
        ->assertSessionHas('estado', 'Se aprobaron 2 solicitud(es) (2 día(s)).');

    expect($primera->fresh()->estado)->toBe(Licencia::APROBADO)
        ->and($segunda->fresh()->estado)->toBe(Licencia::APROBADO)
        ->and($primera->fresh()->revisadoPor_id)->not->toBeNull()
        ->and($sinMarcar->fresh()->estado)->toBe(Licencia::PENDIENTE);
});

test('una solicitud de varios días se resuelve entera', function (): void {
    $dias = collect(['2026-10-12', '2026-10-13', '2026-10-14'])
        ->map(fn (string $fecha): Licencia => solicitudDe('111', datos: ['fecha' => $fecha, 'solicitud' => 'PEDIDO-1']));

    $this->actingAs(superAdmin())
        ->patch(route('licencias.lote.aprobar'), ['solicitudes' => [$dias->first()->id]])
        ->assertSessionHas('estado', 'Se aprobaron 1 solicitud(es) (3 día(s)).');

    expect(Licencia::where('solicitud', 'PEDIDO-1')->pluck('estado')->unique()->all())->toBe([Licencia::APROBADO]);
});

test('«todas las que coinciden» vuelve a aplicar el filtro en el servidor', function (): void {
    $institucional = solicitudDe('111');
    $otraInstitucional = solicitudDe('222');
    $personal = solicitudDe('333', datos: ['tipo' => Licencia::TIPO_PERSONAL]);
    $deNoviembre = solicitudDe('444', datos: ['fecha' => '2026-11-03']);

    $this->actingAs(superAdmin())
        ->patch(route('licencias.lote.aprobar'), ['todas' => 1, 'tipo' => Licencia::TIPO_INSTITUCIONAL] + filtrosDeOctubre())
        ->assertSessionHas('estado', 'Se aprobaron 2 solicitud(es) (2 día(s)).');

    expect($institucional->fresh()->estado)->toBe(Licencia::APROBADO)
        ->and($otraInstitucional->fresh()->estado)->toBe(Licencia::APROBADO)
        ->and($personal->fresh()->estado)->toBe(Licencia::PENDIENTE)
        ->and($deNoviembre->fresh()->estado)->toBe(Licencia::PENDIENTE);
});

test('rechazar en lote exige motivo y lo deja en todas', function (): void {
    $primera = solicitudDe('111');
    $segunda = solicitudDe('222');
    $this->actingAs(superAdmin());

    $this->patch(route('licencias.lote.rechazar'), ['solicitudes' => [$primera->id, $segunda->id]])
        ->assertSessionHasErrors('observacion');
    expect($primera->fresh()->estado)->toBe(Licencia::PENDIENTE);

    $this->patch(route('licencias.lote.rechazar'), ['solicitudes' => [$primera->id, $segunda->id], 'observacion' => 'Fuera de plazo.'])
        ->assertSessionHas('estado', 'Se rechazaron 2 solicitud(es) (2 día(s)).');

    expect([$primera->fresh(), $segunda->fresh()])->each(
        fn ($licencia) => $licencia->estado->toBe(Licencia::RECHAZADO)->observacion->toBe('Fuera de plazo.')
    );
});

test('lo ya resuelto no se toca aunque venga marcado', function (): void {
    $aprobada = solicitudDe('111', Licencia::APROBADO);

    $this->actingAs(superAdmin())
        ->patch(route('licencias.lote.rechazar'), ['solicitudes' => [$aprobada->id], 'observacion' => 'No corresponde.'])
        ->assertSessionHas('error');

    expect($aprobada->fresh()->estado)->toBe(Licencia::APROBADO);
});

test('la que pasa el tope de permisos queda pendiente y el resto se aprueba', function (): void {
    // Tope de 1 h por mes; ya tiene 45 min aprobados.
    Configuracion::guardar(Configuracion::TOPE_PERMISO_MENSUAL, '60', Carbon::parse('2026-10-01'), 'inicial');
    Configuracion::guardar(Configuracion::TOPE_PERMISO_ALCANCE, Configuracion::TOPE_POR_MES, Carbon::parse('2026-10-01'), 'inicial');
    Licencia::factory()->porHoras('08:00', '08:45')->create([
        'ci' => '111', 'fecha' => '2026-10-05', 'estado' => Licencia::APROBADO, 'tipo' => Licencia::TIPO_PERSONAL,
    ]);

    $sePasa = Licencia::factory()->porHoras('10:00', '11:00')->create([
        'ci' => '111', 'fecha' => '2026-10-12', 'estado' => Licencia::PENDIENTE, 'tipo' => Licencia::TIPO_PERSONAL,
    ]);
    $entra = solicitudDe('222');

    $this->actingAs(superAdmin())
        ->patch(route('licencias.lote.aprobar'), ['solicitudes' => [$sePasa->id, $entra->id]])
        ->assertSessionHas('estado', 'Se aprobaron 1 solicitud(es) (1 día(s)).')
        ->assertSessionHas('error', '1 solicitud(es) quedaron pendientes porque pasan el tope mensual de permisos: CI 111.');

    expect($sePasa->fresh()->estado)->toBe(Licencia::PENDIENTE)
        ->and($entra->fresh()->estado)->toBe(Licencia::APROBADO);
});
