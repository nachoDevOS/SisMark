<?php

namespace Database\Factories;

use App\Models\DiaExcepcional;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DiaExcepcional>
 */
class DiaExcepcionalFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fecha' => fake()->unique()->dateTimeBetween('-2 years', '+1 year')->format('Y-m-d'),
            'motivoInasistencia' => fake()->randomElement([
                'FERIADO POR CARNAVAL', 'AÑO NUEVO', 'NAVIDAD', 'ANIVERSARIO DEL BENI',
                'DÍA DEL TRABAJO', 'ESTADO PLURINACIONAL',
            ]),
            'observacion' => null,
            'estado' => 1,
        ];
    }
}
