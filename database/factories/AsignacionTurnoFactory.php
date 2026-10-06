<?php

namespace Database\Factories;

use App\Models\AsignacionTurno;
use App\Models\Turno;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Solo la cabecera: el detalle lo arma AsignadorTurnos.
 *
 * @extends Factory<AsignacionTurno>
 */
class AsignacionTurnoFactory extends Factory
{
    protected $model = AsignacionTurno::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ci' => (string) fake()->unique()->numberBetween(1000000, 9999999),
            'turno_id' => Turno::factory(),
            'desde' => today()->startOfYear(),
            'hasta' => today()->endOfYear()->startOfDay(),
        ];
    }
}
