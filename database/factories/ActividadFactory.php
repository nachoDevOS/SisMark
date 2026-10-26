<?php

namespace Database\Factories;

use App\Models\Actividad;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Actividad>
 */
class ActividadFactory extends Factory
{
    /**
     * Un evento importante: alguien miró una ficha.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $ci = (string) fake()->numberBetween(1000000, 12000000);

        return [
            'user_id' => User::factory(),
            'importante' => true,
            'accion' => Actividad::VER,
            'modulo' => 'Funcionarios',
            'descripcion' => "Miró la ficha del funcionario CI {$ci}",
            'ci' => $ci,
            'ruta' => 'funcionarios.mamore',
            'url' => "/funcionarios/mamore/{$ci}",
            'metodo' => 'GET',
            'estado' => 200,
            'duracion_ms' => fake()->numberBetween(20, 900),
            'ip' => fake()->localIpv4(),
            'navegador' => 'Mozilla/5.0',
        ];
    }

    /**
     * Un pedido cualquiera, sin frase.
     */
    public function detalle(): static
    {
        return $this->state(fn (): array => [
            'importante' => false,
            'accion' => Actividad::PEDIDO,
            'descripcion' => null,
            'ci' => null,
            'ruta' => 'funcionarios.list',
            'url' => '/funcionarios/ajax/list',
        ]);
    }
}
