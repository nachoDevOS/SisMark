<?php

namespace Database\Factories;

use App\Models\ConfiguracionPlazoLicencia;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConfiguracionPlazoLicencia>
 */
class ConfiguracionPlazoLicenciaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dias_anticipacion' => 7,
            'dias_atraso' => 3,
            'dias_habiles' => false,
            'vigente_desde' => today()->subMonth(),
            'vigente_hasta' => null,
            'motivo' => fake()->sentence(),
        ];
    }

    /**
     * Los límites se cuentan en días hábiles (lunes a viernes).
     */
    public function habiles(): static
    {
        return $this->state(fn (array $attributes): array => ['dias_habiles' => true]);
    }
}
