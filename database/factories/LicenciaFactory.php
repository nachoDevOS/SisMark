<?php

namespace Database\Factories;

use App\Models\Licencia;
use App\Models\Turno;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Licencia>
 */
class LicenciaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fechaPedido' => now(),
            // Cada licencia de prueba es su propia solicitud, salvo que el test
            // arme una tanda pasando el mismo valor a varias filas.
            'solicitud' => (string) Str::ulid(),
            'usuario' => fake()->name(),
            'fecha' => fake()->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
            'ci' => (string) fake()->unique()->numberBetween(1000000, 9999999),
            'turno_id' => Turno::factory(),
            // El horario va por la FK; `idTurno` solo existe en lo migrado del SIA.
            'idTurno' => null,
            'lEntra' => null,
            'lSale' => null,
            'tCompleto' => true,
            'motivo' => fake()->randomElement([
                'VACACIÓN', 'COMISIÓN DE VIAJE', 'BAJA MÉDICA', 'PERMISO PERSONAL',
            ]),
            'goceHaberes' => true,
            'observacion' => null,
            'estado' => 'Aprobado',
        ];
    }

    /**
     * Licencia migrada del SIA: no trae `solicitud`, así que se agrupa por su
     * `id`. Es la mitad del padrón y la que no cubría ninguna prueba.
     */
    public function delSia(): self
    {
        return $this->state(fn (): array => ['solicitud' => null]);
    }

    /**
     * Licencia por horas: solo la hora, que es lo que guardan las columnas.
     */
    public function porHoras(string $entrada = '08:00', string $salida = '12:00'): self
    {
        return $this->state(fn (): array => [
            'tCompleto' => false,
            'lEntra' => "{$entrada}:00",
            'lSale' => "{$salida}:00",
        ]);
    }
}
