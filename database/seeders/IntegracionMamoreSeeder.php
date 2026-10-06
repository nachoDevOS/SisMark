<?php

namespace Database\Seeders;

use App\Models\Horario;
use App\Models\SistemaExterno;
use App\Models\Turno;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Deja la integración con Mamoré lista para usar después de un `migrate:fresh`,
 * **solo fuera de producción**.
 *
 * Un `migrate:fresh` se lleva los sistemas consumidores, sus tokens y el turno
 * sugerido. Sin esto, cada vez hay que volver a emitir el token, ir a pegarlo al
 * `.env` de Mamoré y armar a mano el turno, y hasta que eso pase el alta de
 * contrato de allá contesta 401 primero y 422 después.
 *
 * ---
 * **El token es de texto fijo, a propósito.** Sanctum no guarda el texto sino su
 * hash, así que sembrar el hash de una cadena conocida deja una credencial que
 * sobrevive a todos los `migrate:fresh` y no obliga a tocar el `.env` nunca más
 * en desarrollo.
 *
 * Es exactamente lo que no se debe hacer en producción —una credencial conocida,
 * escrita en el repositorio—, y por eso el seeder se planta si lo corren ahí.
 * En producción el token se emite una vez desde «Tokens de API» o con
 * `php artisan sismark:token`, y no se siembra nunca.
 * ---
 */
class IntegracionMamoreSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * El texto plano del token de desarrollo. Lo que se guarda es su sha256.
     *
     * Se nombra a sí mismo para que nadie lo confunda con una credencial real si
     * aparece en un log o en un `.env` que no corresponde.
     */
    private const TOKEN_PLANO = 'sismark-local-solo-desarrollo-no-usar-en-produccion';

    /**
     * Id fijo del token. Viaja adelante del texto plano (`{id}|{texto}`), así que
     * fijarlo es lo que mantiene estable la cadena completa.
     */
    private const TOKEN_ID = 1;

    private const SLUG = 'mamore';

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('IntegracionMamoreSeeder no corre en producción: sembraría una credencial conocida.');

            return;
        }

        $sistema = $this->sistema();
        $completo = $this->sembrarToken($sistema);
        $turno = $this->sembrarTurnoSugerido();

        $this->informar($completo, $turno);
    }

    private function sistema(): SistemaExterno
    {
        $sistema = SistemaExterno::withTrashed()->firstOrNew(['slug' => self::SLUG]);

        $sistema->fill([
            'nombre' => 'Mamoré',
            'observaciones' => 'Sistema administrativo. Consulta asistencia y licencias, y asigna el horario al dar de alta un contrato.',
            'activo' => true,
        ]);

        // Si quedó dado de baja de una corrida anterior, vuelve: un sistema de
        // desarrollo dado de baja rechaza todo con 401 y manda a buscar el
        // problema al token, que está bien.
        $sistema->deleted_at = null;
        $sistema->save();

        return $sistema;
    }

    /**
     * Siembra el token de texto fijo y devuelve la cadena completa.
     */
    private function sembrarToken(SistemaExterno $sistema): string
    {
        // Se borra por id y no por sistema: si el id fijo quedó ocupado por un
        // token de otro consumidor —emitido a mano desde la pantalla—, insertar
        // encima reventaría con clave duplicada.
        PersonalAccessToken::whereKey(self::TOKEN_ID)->delete();
        $sistema->tokens()->delete();

        DB::table('personal_access_tokens')->insert([
            'id' => self::TOKEN_ID,
            'tokenable_type' => $sistema->getMorphClass(),
            'tokenable_id' => $sistema->getKey(),
            'name' => 'servicio-'.self::SLUG,
            // Sanctum compara contra el sha256 del texto plano; el texto no se
            // guarda en ningún lado.
            'token' => hash('sha256', self::TOKEN_PLANO),
            'abilities' => json_encode(array_keys(SistemaExterno::ALCANCES)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return self::TOKEN_ID.'|'.self::TOKEN_PLANO;
    }

    /**
     * Crea el turno general —lunes a viernes, 08:00 a 16:00— como sugerido.
     *
     * No pisa lo que ya exista: si alguien dejó otro turno como sugerido desde
     * la pantalla, esa decisión vale más que el default de este seeder.
     */
    private function sembrarTurnoSugerido(): ?Turno
    {
        $existente = Turno::query()->sugeridos()->with('horarios')->first();

        if ($existente !== null) {
            return $existente;
        }

        // Un horario por día hábil: el de entrada 08:00 y salida 16:00, que en la
        // tabla del SIA es único por día.
        $ids = collect(['2', '3', '4', '5', '6'])
            ->map(fn (string $dia): ?int => Horario::query()
                ->where('dia', $dia)
                ->whereTime('hEntrada', '08:00:00')
                ->whereTime('hSalida', '16:00:00')
                ->orderBy('id')
                ->value('id'))
            ->filter()
            ->all();

        if ($ids === []) {
            return null;
        }

        $turno = Turno::create([
            'nombre' => 'Horario general L-V 08:00 - 16:00',
            'sugerido' => true,
        ]);
        $turno->horarios()->attach($ids);

        return $turno->load('horarios');
    }

    private function informar(string $token, ?Turno $turno): void
    {
        $this->command?->newLine();
        $this->command?->info('Integración con Mamoré lista (solo desarrollo).');
        $this->command?->line('  SISMARK_API_TOKEN="'.$token.'"');

        if ($turno === null) {
            $this->command?->warn('  Sin turno sugerido: no se encontró el horario 08:00–16:00. Crealo desde Turnos.');

            return;
        }

        $this->command?->line("  Turno sugerido: «{$turno->nombre}» ({$turno->horarios->count()} horarios).");
    }
}
