<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Clave del administrador fuera de producción cuando no se fijó
     * `SEED_ADMIN_PASSWORD` en el `.env`.
     *
     * Existe para que una base recién sembrada se pueda usar sin buscar en el
     * historial de la terminal la cadena aleatoria que se imprimió una vez.
     */
    private const CLAVE_DESARROLLO = 'password';

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        $email = (string) config('auth.seed_admin.email');
        $fija = $this->claveFija();
        $clave = $fija ?? Str::password(16);

        // Sin `User::factory()`: las factories usan `fake()`, que viene de
        // fakerphp/faker —dependencia de desarrollo—, y la imagen de producción
        // se arma con `composer install --no-dev`. Ahí esto moría con
        // «Call to undefined function Database\Factories\fake()», justo en
        // medio del `migrate:fresh --seed` de MigrarSiaSeeder.
        $usuario = User::firstOrCreate(
            ['email' => $email],
            ['name' => 'Admin', 'password' => Hash::make($clave)],
        );

        $usuario->assignRole('super_admin');

        // Con clave fija se reescribe en cada corrida: `firstOrCreate` no toca
        // al usuario que ya existe, y sin esto la segunda corrida dejaba
        // vigente la clave vieja —el motivo por el que «admin/password» no
        // entraba aunque el seeder dijera que había terminado bien—.
        if ($fija !== null && ! $usuario->wasRecentlyCreated) {
            $usuario->forceFill(['password' => Hash::make($fija)])->save();
        }

        $this->informar($usuario, $email, $fija, $clave);
    }

    /**
     * Clave con la que debe quedar el administrador, o `null` si toca generar
     * una aleatoria.
     *
     * En producción manda `SEED_ADMIN_PASSWORD` y nada más: sin esa variable la
     * clave es aleatoria y de un solo uso, así nunca queda un «admin/password»
     * publicado por olvidar la variable. Fuera de producción, cuando tampoco
     * está, se cae en {@see self::CLAVE_DESARROLLO}.
     */
    private function claveFija(): ?string
    {
        $delEntorno = (string) config('auth.seed_admin.password');

        if ($delEntorno !== '') {
            return $delEntorno;
        }

        return app()->environment('production') ? null : self::CLAVE_DESARROLLO;
    }

    /**
     * Avisa por consola con qué credenciales quedó el administrador.
     *
     * La clave aleatoria solo se puede mostrar recién creada: en una corrida
     * repetida la vigente es la que ya tenía y no hay forma de recuperarla.
     */
    private function informar(User $usuario, string $email, ?string $fija, string $clave): void
    {
        if ($fija === null && ! $usuario->wasRecentlyCreated) {
            return;
        }

        $this->command?->newLine();

        if ($fija === null) {
            $this->command?->warn("Usuario «{$email}» creado con la clave: {$clave}");
            $this->command?->warn('Anotala y cambiala apenas entres: no se vuelve a mostrar.');

            return;
        }

        $this->command?->warn("Usuario «{$email}» listo con la clave: {$fija}");
        $this->command?->warn('Es una clave de desarrollo: cambiala antes de exponer el sistema.');
    }
}
