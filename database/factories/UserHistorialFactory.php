<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserHistorial;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserHistorial>
 */
class UserHistorialFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $nombre = fake()->name();

        return [
            'user_id' => User::factory(),
            'accion' => UserHistorial::ACCION_ACTUALIZADO,
            'antes' => ['Nombre' => fake()->name(), 'Roles' => 'Sin roles'],
            'despues' => ['Nombre' => $nombre, 'Roles' => 'Sin roles'],
        ];
    }
}
