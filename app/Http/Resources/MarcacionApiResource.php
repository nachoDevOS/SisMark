<?php

namespace App\Http\Resources;

use App\Models\Asistencia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una marcación cruda para la API.
 *
 * `fecha` y `hora` son columnas separadas: la hora se guarda sobre la fecha
 * base 1899-12-30, así que del lado del consumidor solo sirve su parte horaria.
 * Acá se entregan ya separadas y formateadas para que nadie tenga que saber eso.
 *
 * @mixin Asistencia
 */
class MarcacionApiResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'fecha' => $this->fecha?->toDateString(),
            'hora' => $this->hora?->format('H:i:s'),
            'tipo' => trim((string) $this->tipo),
            // La letra sola no le dice nada a nadie: R = reloj, M = manual,
            // A = origen sin documentar en el sistema viejo.
            'tipoEtiqueta' => Asistencia::TIPOS[trim((string) $this->tipo)] ?? 'Sin identificar',
            'observacion' => $this->observacion,
        ];
    }
}
