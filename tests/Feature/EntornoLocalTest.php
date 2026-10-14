<?php

test('en local la pestaña lleva [LOCAL] y el sidebar cambia de color', function (): void {
    app()['env'] = 'local';

    $this->get(route('login'))
        ->assertOk()
        ->assertSee('<title>[LOCAL] Acceso', false);

    $this->actingAs(superAdmin())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('<title>[LOCAL] ', false)
        ->assertSee('class="entorno-local"', false);
});

test('en producción no aparece el distintivo', function (): void {
    app()['env'] = 'production';

    $this->get(route('login'))
        ->assertOk()
        ->assertDontSee('[LOCAL]');

    $this->actingAs(superAdmin())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('[LOCAL]')
        ->assertDontSee('entorno-local');
});
