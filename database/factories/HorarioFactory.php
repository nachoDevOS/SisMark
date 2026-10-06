<?php

namespace Database\Factories;

use App\Models\Horario;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Horario>
 */
class HorarioFactory extends Factory
{
    protected $model = Horario::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Las horas usan la fecha base 1899-12-30, como el SIA real.
        $hora = fn (string $hm): Carbon => Carbon::createFromFormat('Y-m-d H:i', "1899-12-30 {$hm}");

        return [
            'idHorario' => fake()->unique()->regexify('[A-Z0-9]{3}'),
            'dia' => (string) fake()->numberBetween(1, 7),
            'nombreHorario' => 'Horario '.fake()->numberBetween(1, 99),
            'hEntrada' => $hora('08:00'),
            'hTolerancia' => $hora('08:10'),
            'eMinima' => $hora('07:00'),
            'eMaxima' => $hora('10:00'),
            'hSalida' => $hora('16:00'),
            'sTolerancia' => $hora('16:00'),
            'sMinima' => $hora('16:00'),
            'sMaxima' => $hora('23:59'),
            'hTrabajadas' => 8,
            'siguienteDia' => false,
        ];
    }
}
