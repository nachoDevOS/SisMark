<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Corre TODA la migración de datos del SIA (SQL Server) a la base local, con un
 * solo comando:
 *
 *     php artisan db:seed --class=MigrarSiaSeeder
 *
 * Hace tres cosas, en este orden:
 *
 * 1. `migrate:fresh`: recrea el esquema desde cero.
 * 2. {@see DatabaseSeeder}: permisos, rol `super_admin` y usuario
 *    administrador, con la clave que diga `SEED_ADMIN_PASSWORD` (o
 *    «password» fuera de producción). Sin este paso la base queda con todos
 *    los datos del SIA y sin nadie que pueda entrar a verlos.
 * 3. Los comandos de copia, en orden de dependencia: `asignacion_turnos` y
 *    `licencias` resuelven su FK `turno_id` contra `turnos`, así que los
 *    horarios se migran antes.
 *
 * OJO: `migrate:fresh` BORRA todas las tablas (equipos, usuarios, roles y las del
 * SIA ya copiadas). Es una migración limpia completa cada vez que se corre.
 *
 * Requiere `pdo_sqlsrv` instalado, MySQL arriba y las credenciales `DB_*_SIA`
 * en el `.env`. Ver docs/MIGRACION-SIA-MYSQL.md.
 */
class MigrarSiaSeeder extends Seeder
{
    /**
     * Comandos de copia, en orden de dependencia.
     *
     * @var list<string>
     */
    private const COMANDOS = [
        'sia:migrar-profesiones',
        'sia:migrar-personas',
        'sia:migrar-horarios',        // antes de asignacion-turnos (FK turno_id).
        'sia:migrar-marcaciones',
        'sia:migrar-licencias',
        'sia:migrar-asignacion-turnos',
        'sia:migrar-dias-excepcionales',
    ];

    public function run(): void
    {
        // Base limpia desde cero antes de copiar. En testing la BD ya viene
        // fresca por RefreshDatabase; correr migrate:fresh ahí rompería la
        // transacción de las pruebas.
        if (! app()->environment('testing')) {
            $this->command->getOutput()->writeln('<comment>Limpiando la base: migrate:fresh…</comment>');
            $this->command->call('migrate:fresh', ['--force' => true]);
        }

        // Permisos, rol super_admin y usuario administrador. Va explícito y no
        // como `--seed` del migrate:fresh: así corre en todos los entornos —el
        // de pruebas incluido, donde no hay migrate:fresh que lo arrastre— y
        // queda a la vista que esta migración también deja el sistema con
        // accesos, no solo con datos.
        $this->command->getOutput()->writeln('<info>→ DatabaseSeeder (permisos, roles y administrador)</info>');
        $this->call(DatabaseSeeder::class);

        foreach (self::COMANDOS as $comando) {
            $this->command->getOutput()->writeln("<info>→ {$comando}</info>");
            $this->command->call($comando);
        }

        $this->command->getOutput()->writeln('<info>Migración del SIA completa.</info>');
    }
}
