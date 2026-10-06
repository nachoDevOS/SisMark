<?php

namespace Database\Factories;

use App\Models\AsignacionHorario;
use App\Models\Horario;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AsignacionHorario>
 */
class AsignacionHorarioFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ci' => (string) fake()->unique()->numberBetween(1000000, 9999999),
            // El código del SIA sale del horario vinculado, para que el par
            // idHorario/horario_id sea coherente como en la migración real.
            'horario_id' => Horario::factory(),
            'idHorario' => fn (array $atributos): string => (string) (Horario::find($atributos['horario_id'])?->idHorario ?? '001'),
            'desde' => now()->subYear()->startOfDay(),
            'hasta' => now()->addYear()->startOfDay(),
            'observacion' => null,
            'estado' => 1,
        ];
    }

    /**
     * Asignación ya vencida (no admite licencias nuevas).
     */
    public function vencida(): self
    {
        return $this->state(fn (): array => [
            'desde' => now()->subYears(2)->startOfDay(),
            'hasta' => now()->subMonth()->startOfDay(),
        ]);
    }
}
