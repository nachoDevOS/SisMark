<?php

namespace App\Http\Resources;

use App\Models\Licencia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una licencia del funcionario para la API.
 *
 * `alcance` resume en una frase lo que en la tabla son tres columnas
 * (`tCompleto`, `lEntra`, `lSale`), para que el consumidor lo muestre tal cual.
 *
 * @mixin Licencia
 */
class LicenciaApiResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $completo = (bool) $this->tCompleto;
        $entra = $this->lEntra?->format('H:i');
        $sale = $this->lSale?->format('H:i');

        return [
            'fecha' => $this->fecha?->toDateString(),
            'turno' => $this->resumen_turno,
            'turnoCompleto' => $completo,
            'desdeHora' => $completo ? null : $entra,
            'hastaHora' => $completo ? null : $sale,
            'alcance' => $completo
                ? 'Turno completo'
                : trim(($entra ?? '—').' – '.($sale ?? '—')),
            'conGoceDeHaberes' => (bool) $this->goceHaberes,
            'motivo' => $this->motivo ?: null,
        ];
    }
}
