<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Corta la corrida si la base no es SQLite en memoria, **antes** de que
     * `RefreshDatabase` la borre y la vuelva a crear.
     *
     * Tiene que ir acá y no en un `beforeEach`: los traits se arman primero, y
     * para cuando corre el `beforeEach` la base ya se rehízo. Si alguna vez
     * queda la configuración cacheada (`php artisan config:cache`), los valores
     * de `phpunit.xml` no llegan a mandar y las pruebas apuntarían a la base de
     * desarrollo.
     *
     * @return array<class-string, class-string>
     */
    protected function setUpTraits(): array
    {
        $conexion = config('database.default');

        if ($conexion !== 'sqlite' || config("database.connections.{$conexion}.database") !== ':memory:') {
            throw new RuntimeException(
                'Las pruebas solo corren sobre SQLite en memoria, y la conexión es «'.$conexion.'». '
                .'¿Quedó la configuración cacheada? Corré `php artisan config:clear`.'
            );
        }

        return parent::setUpTraits();
    }
}
