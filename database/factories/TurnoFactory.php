<?php

namespace Database\Factories;

use App\Models\Turno;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Turno>
 */
class TurnoFactory extends Factory
{
    protected $model = Turno::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre' => 'Turno '.fake()->unique()->numberBetween(1, 999),
            'sugerido' => false,
        ];
    }

    /**
     * Turno que se ofrece al asignar desde Mamoré.
     */
    public function sugerido(): static
    {
        return $this->state(fn (array $atributos): array => [
            'sugerido' => true,
        ]);
    }
}
