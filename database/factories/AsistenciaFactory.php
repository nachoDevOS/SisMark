<?php

namespace Database\Factories;

use App\Models\Asistencia;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Asistencia>
 */
class AsistenciaFactory extends Factory
{
    protected $model = Asistencia::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ci' => (string) fake()->unique()->numberBetween(1, 9999999),
            'fecha' => today(),
            'hora' => fake()->time('H:i:s'),
            'tipo' => Asistencia::TIPO_RELOJ,
        ];
    }
}
