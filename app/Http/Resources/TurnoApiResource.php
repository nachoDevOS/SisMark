<?php

namespace App\Http\Resources;

use App\Models\Horario;
use App\Models\Turno;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un turno con sus horarios día por día.
 *
 * `turnoId` es lo único que necesita mandar de vuelta quien lo elija; el resto
 * es para mostrarlo.
 *
 * @property-read Turno $resource
 */
class TurnoApiResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'turnoId' => $this->resource->id,
            'nombre' => $this->resource->nombre,
            'horasSemanales' => $this->resource->horas_semanales,
            'dias' => $this->resource->horarios
                ->map(fn (Horario $horario): array => [
                    'dia' => (int) $horario->dia,
                    'diaNombre' => Horario::DIAS[(int) $horario->dia] ?? null,
                    'hEntrada' => $horario->hEntrada?->format('H:i'),
                    'hSalida' => $horario->hSalida?->format('H:i'),
                    'hTolerancia' => $horario->hTolerancia?->format('H:i'),
                    'hTrabajadas' => (float) $horario->hTrabajadas,
                    'siguienteDia' => (bool) $horario->siguienteDia,
                    // Las ventanas en las que la marca se acepta: fuera de ellas
                    // el reloj registra, pero el procesador no la toma. Es la
                    // pregunta de siempre —«¿desde qué hora puedo marcar?»—.
                    'eMinima' => $horario->eMinima?->format('H:i'),
                    'eMaxima' => $horario->eMaxima?->format('H:i'),
                    'sMinima' => $horario->sMinima?->format('H:i'),
                    'sMaxima' => $horario->sMaxima?->format('H:i'),
                ])
                ->values()
                ->all(),
        ];
    }
}
