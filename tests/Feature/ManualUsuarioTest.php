<?php

/*
| Ayuda: el botón de la barra superior y los videos tutoriales. Cada tutorial
| se ofrece y se sirve solo a quien tiene los permisos del trámite que muestra.
*/

test('con los permisos ve el botón del manual y el video responde por rangos', function (): void {
    $usuario = usuarioCon('ViewAny:Persona', 'View:Persona');

    $this->actingAs($usuario)
        ->get(route('funcionarios.index'))
        ->assertOk()
        ->assertSee('title="Ayuda: tutoriales en video"', false)
        ->assertSee('Localizar a un funcionario y ver su ficha');

    // El reproductor salta a un paso pidiendo un rango: tiene que volver 206.
    $this->actingAs($usuario)
        ->get(route('manual.archivo', ['tutorial' => 'localizar-funcionario', 'extension' => 'mp4']), ['Range' => 'bytes=0-99'])
        ->assertStatus(206);
});

test('sin los permisos no ve el botón ni puede bajar el video', function (): void {
    $usuario = usuarioCon('ViewAny:Persona');

    $this->actingAs($usuario)
        ->get(route('funcionarios.index'))
        ->assertOk()
        ->assertDontSee('title="Ayuda: tutoriales en video"', false);

    $this->actingAs($usuario)
        ->get(route('manual.archivo', ['tutorial' => 'localizar-funcionario', 'extension' => 'mp4']))
        ->assertNotFound();
});

test('los videos de turnos se ofrecen solo a quien puede crear y asignar turnos', function (): void {
    $consulta = usuarioCon('ViewAny:Persona', 'View:Persona');
    $asigna = usuarioCon('ViewAny:Persona', 'View:Persona', 'Create:Turno', 'ViewAny:AsignacionTurno', 'Update:AsignacionTurno', 'Create:AsignacionTurno');

    $this->actingAs($consulta)
        ->get(route('funcionarios.index'))
        ->assertSee('Localizar a un funcionario y ver su ficha')
        ->assertDontSee('Crear un turno y asignárselo a quien no tiene')
        ->assertDontSee('Cambiar de turno: concluir el actual y asignar otro');
    $this->actingAs($consulta)
        ->get(route('manual.archivo', ['tutorial' => 'cambiar-turno', 'extension' => 'mp4']))
        ->assertNotFound();

    $this->actingAs($asigna)
        ->get(route('funcionarios.index'))
        ->assertSee('Crear un turno y asignárselo a quien no tiene')
        ->assertSee('Cambiar de turno: concluir el actual y asignar otro');
    $this->actingAs($asigna)
        ->get(route('manual.archivo', ['tutorial' => 'cambiar-turno', 'extension' => 'mp4']), ['Range' => 'bytes=0-99'])
        ->assertStatus(206);
});

test('los videos de reportes y de licencias se ofrecen según su permiso', function (): void {
    $reportes = usuarioCon('ViewAny:Persona', 'ViewAny:Reporte');
    $licencias = usuarioCon('ViewAny:Persona', 'View:Persona', 'ViewAny:Licencia', 'Create:Licencia');

    $this->actingAs($reportes)
        ->get(route('funcionarios.index'))
        ->assertSee('Reporte procesado de un funcionario')
        ->assertSee('Reporte por dirección')
        ->assertSee('Reporte de funcionarios sin turno')
        ->assertDontSee('Registrar una licencia desde la ficha');

    $this->actingAs($licencias)
        ->get(route('funcionarios.index'))
        ->assertSee('Registrar una licencia desde la ficha')
        ->assertSee('Licenciar desde la pantalla Licencias')
        ->assertDontSee('Reporte procesado de un funcionario');
});

test('la vista previa de la tarjeta se sirve con los mismos permisos que el video', function (): void {
    $previa = ['tutorial' => 'localizar-funcionario', 'extension' => 'previa.mp4'];

    $this->actingAs(usuarioCon('ViewAny:Persona', 'View:Persona'))
        ->get(route('funcionarios.index'))
        ->assertSee('localizar-funcionario.previa.mp4', false);
    $this->actingAs(usuarioCon('ViewAny:Persona', 'View:Persona'))->get(route('manual.archivo', $previa))->assertOk();
    $this->actingAs(usuarioCon('ViewAny:Persona'))->get(route('manual.archivo', $previa))->assertNotFound();
});

test('un tutorial que no existe da 404', function (): void {
    $this->actingAs(superAdmin())
        ->get('/manual/no-existe.mp4')
        ->assertNotFound();
});

test('sin sesión el video no se entrega', function (): void {
    $this->get(route('manual.archivo', ['tutorial' => 'localizar-funcionario', 'extension' => 'mp4']))
        ->assertRedirect(route('login'));
});
