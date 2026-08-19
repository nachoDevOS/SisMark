<?php

namespace Database\Factories;

use App\Models\Equipo;
use App\Models\EquipoAuditoria;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EquipoAuditoria>
 */
class EquipoAuditoriaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $equipo = Equipo::factory()->create();

        return [
            'equipo_id' => $equipo->id,
            'accion' => EquipoAuditoria::ACCION_EXPORTAR,
            'motivo' => null,
            'datos_equipo' => [
                'id' => $equipo->id,
                'nombre' => $equipo->nombre,
                'ip' => $equipo->ip,
                'puerto' => $equipo->puerto,
                'ubicacion' => $equipo->ubicacion,
                'algoritmo' => $equipo->algoritmo,
                'en_linea' => false,
                'activo' => true,
                'ultima_sync' => null,
            ],
            // El contador del reloj solo lo trae la sincronización: exportar no
            // lo consulta.
            'en_equipo' => null,
            'total_marcaciones' => fake()->numberBetween(0, 500),
            // El desglose solo lo escriben las sincronizaciones; el estado por
            // defecto es una exportación, que no reparte nada.
            'nuevas' => null,
            'repetidas' => null,
            'sin_funcionario' => null,
            'fallidas' => null,
            'fuera_de_rango' => null,
            'desde' => null,
            'hasta' => null,
            'detalle' => null,
            'exito' => true,
            'ip_usuario' => fake()->localIpv4(),
        ];
    }

    /**
     * Estado: sincronización, con el desglose que cierra contra el total.
     */
    public function sincronizacion(): static
    {
        return $this->state(function (): array {
            $nuevas = fake()->numberBetween(0, 80);
            $repetidas = fake()->numberBetween(0, 300);
            $sinFuncionario = fake()->numberBetween(0, 5);
            $fallidas = fake()->numberBetween(0, 3);

            $llegaron = $nuevas + $repetidas + $sinFuncionario + $fallidas;

            return [
                'accion' => EquipoAuditoria::ACCION_SINCRONIZAR,
                // Transferencia completa: el reloj declara lo mismo que llegó.
                // Para el caso contrario está el estado `incompleta()`.
                'en_equipo' => $llegaron,
                'total_marcaciones' => $llegaron,
                'nuevas' => $nuevas,
                'repetidas' => $repetidas,
                'sin_funcionario' => $sinFuncionario,
                'fallidas' => $fallidas,
                'fuera_de_rango' => 0,
            ];
        });
    }

    /**
     * Estado: sincronización cuya lectura se cortó por el medio. El reloj
     * declara más marcaciones de las que llegaron.
     */
    public function incompleta(int $perdidas = 1): static
    {
        return $this->sincronizacion()->state(fn (array $atributos): array => [
            'en_equipo' => $atributos['total_marcaciones'] + $perdidas,
            'exito' => false,
        ]);
    }

    /**
     * Estado: limpieza del reloj (acción destructiva, siempre con motivo).
     */
    public function limpieza(): static
    {
        return $this->state(fn (): array => [
            'accion' => EquipoAuditoria::ACCION_LIMPIAR,
            'motivo' => 'Memoria del equipo llena.',
        ]);
    }

    /**
     * Estado: baja del equipo (acción destructiva, siempre con motivo).
     */
    public function baja(): static
    {
        return $this->state(fn (): array => [
            'accion' => EquipoAuditoria::ACCION_ELIMINAR,
            'motivo' => 'Equipo dado de baja por falla de hardware.',
            'en_equipo' => null,
            'total_marcaciones' => null,
            'nuevas' => null,
            'repetidas' => null,
            'sin_funcionario' => null,
            'fallidas' => null,
            'fuera_de_rango' => null,
        ]);
    }
}
