<?php

namespace App\Console\Commands;

use App\Http\Controllers\SistemaExternoController;
use App\Models\SistemaExterno;
use App\Models\SistemaExternoAuditoria;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Emite el token con el que un sistema externo consume la API de asistencia.
 *
 * La emisión de todos los días se hace por pantalla, en «Tokens de API»
 * ({@see SistemaExternoController}), que pide su permiso
 * propio, la contraseña de quien la hace y deja la fila en la bitácora.
 *
 * Este comando queda para cuando la pantalla no sirve: un despliegue nuevo, sin
 * usuarios ni roles cargados todavía, o un servidor al que hay que devolverle el
 * acceso sin poder entrar al sitio. Anota en la misma bitácora, sin usuario:
 * quien lo corre tiene acceso al servidor, no una cuenta del sistema.
 *
 * El token se muestra **una sola vez**: la base guarda solo su hash, así que
 * perderlo obliga a emitir uno nuevo. Es la garantía de que un token filtrado no
 * se pueda leer después desde la base.
 */
#[Signature('sismark:token
    {slug : Nombre corto del sistema, p. ej. «mamore»}
    {--nombre= : Nombre visible, si hay que crearlo}
    {--alcance=* : Alcances del token; sin esto se dan todos}')]
#[Description('Emite el token de un sistema externo para consumir la API de asistencia.')]
class EmitirTokenSistema extends Command
{
    public function handle(): int
    {
        $slug = trim((string) $this->argument('slug'));

        $sistema = SistemaExterno::query()->where('slug', $slug)->first();

        if ($sistema === null) {
            $nombre = (string) ($this->option('nombre') ?: $slug);

            if (! $this->confirm("No existe el sistema «{$slug}». ¿Lo creo como «{$nombre}»?", true)) {
                $this->error('Cancelado. No se emitió ningún token.');

                return self::FAILURE;
            }

            $sistema = SistemaExterno::create(['slug' => $slug, 'nombre' => $nombre]);
            $this->info("Sistema «{$sistema->nombre}» creado.");
        }

        $alcances = $this->alcances();

        if ($alcances === null) {
            return self::FAILURE;
        }

        // Un sistema tiene un solo token vivo: emitir uno nuevo revoca el
        // anterior, para que no queden credenciales sueltas que nadie recuerda
        // haber entregado. El consumidor queda cortado desde este momento hasta
        // que cargue el token nuevo, así que conviene avisarle antes.
        $revocados = $sistema->revocarTokens();

        if ($revocados > 0) {
            $this->warn("Se revocó {$revocados} token(s) anterior(es): el sistema queda cortado hasta que cargue el nuevo.");
        }

        $token = $sistema->createToken("servicio-{$sistema->slug}", $alcances);

        // Sin usuario: lo emitió alguien con acceso al servidor, no una persona
        // del sistema. La fila igual tiene que quedar —si no, un token entregado
        // desde la consola no aparecería en ningún lado—.
        $sistema->anotar(SistemaExternoAuditoria::ACCION_EMITIR, $token->accessToken->getKey(), $alcances);

        $this->newLine();
        $this->info("Token de «{$sistema->nombre}»:");
        $this->line($token->plainTextToken);
        $this->newLine();
        $this->line('Alcances: '.implode(', ', $alcances));
        $this->warn('Anotalo ahora: la base guarda solo su hash y no se vuelve a mostrar.');

        if (! $sistema->activo) {
            $this->warn('OJO: el sistema está inactivo, así que el token no va a autenticar hasta que se lo active.');
        }

        return self::SUCCESS;
    }

    /**
     * Los alcances pedidos, o todos si no se pidió ninguno.
     *
     * Devuelve `null` cuando alguno no existe: mejor cortar que emitir un token
     * con un alcance mal escrito, que autenticaría pero fallaría con 403 en cada
     * pedido y mandaría a buscar el problema al lado equivocado.
     *
     * @return list<string>|null
     */
    private function alcances(): ?array
    {
        /** @var list<string> $pedidos */
        $pedidos = $this->option('alcance');

        if ($pedidos === []) {
            return array_keys(SistemaExterno::ALCANCES);
        }

        $desconocidos = array_diff($pedidos, array_keys(SistemaExterno::ALCANCES));

        if ($desconocidos !== []) {
            $this->error('Alcance inexistente: '.implode(', ', $desconocidos));
            $this->line('Disponibles:');

            foreach (SistemaExterno::ALCANCES as $alcance => $descripcion) {
                $this->line("  {$alcance} — {$descripcion}");
            }

            return null;
        }

        return array_values(array_unique($pedidos));
    }
}
