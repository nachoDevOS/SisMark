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

            return [
                'accion' => EquipoAuditoria::ACCION_SINCRONIZAR,
                'total_marcaciones' => $nuevas + $repetidas + $sinFuncionario + $fallidas,
                'nuevas' => $nuevas,
                'repetidas' => $repetidas,
                'sin_funcionario' => $sinFuncionario,
                'fallidas' => $fallidas,
                'fuera_de_rango' => 0,
            ];
        });
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
            'total_marcaciones' => null,
            'nuevas' => null,
            'repetidas' => null,
            'sin_funcionario' => null,
            'fallidas' => null,
            'fuera_de_rango' => null,
        ]);
    }
}
