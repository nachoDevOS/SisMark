<?php

namespace App\Http\Resources;

use App\Models\Licencia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

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

        // `MAX(fecha)` vuelve como texto y con hora según el motor, así que se
        // normaliza acá en vez de mandar «2026-08-17 00:00:00» al consumidor.
        $hasta = filled($this->hastaSolicitud)
            ? Carbon::parse($this->hastaSolicitud)->toDateString()
            : $this->fecha?->toDateString();

        $dias = (int) ($this->diasSolicitud ?? 1);

        return [
            // Necesario para pedir el respaldo: el consumidor no puede bajarlo
            // por su cuenta —el archivo es privado— y lo pide por este id.
            'id' => $this->id,
            // `fecha` es el primer día del pedido. Se mantiene el nombre porque
            // es con el que el consumidor ya ordena y rotula la fila.
            'fecha' => $this->fecha?->toDateString(),
            // Hasta cuándo llega y cuántos días con turno abarca: un pedido del
            // 14 al 15 es **una** licencia de dos días, no dos licencias.
            'hasta' => $hasta,
            'dias' => $dias,
            'unSoloDia' => $dias <= 1,
            'turno' => $this->resumen_turno,
            'turnoCompleto' => $completo,
            'desdeHora' => $completo ? null : $entra,
            'hastaHora' => $completo ? null : $sale,
            'alcance' => $completo
                ? 'Turno completo'
                : trim(($entra ?? '—').' – '.($sale ?? '—')),
            'conGoceDeHaberes' => (bool) $this->goceHaberes,
            // Permiso personal o licencia institucional. Solo el personal
            // cuenta contra el tope.
            'tipo' => $this->tipo,
            'tipoEtiqueta' => $this->tipo_etiqueta,
            'motivo' => $this->motivo ?: null,
            // El estado viaja aunque no esté aprobada: el funcionario tiene que
            // ver en qué quedó lo que pidió. El filtro de «solo aprobadas» es del
            // cálculo de asistencia, no de esta lista.
            'estado' => $this->estado,
            // Días del mismo pedido resueltos de distinta manera. No debería
            // pasar —se aprueba o se rechaza el pedido entero—, pero si pasa el
            // funcionario tiene que ver que su licencia quedó partida.
            'estadoMixto' => (int) ($this->estadosSolicitud ?? 1) > 1,
            // Por qué Recursos Humanos la resolvió así. Es obligatoria al
            // rechazar: sin esto el funcionario ve «Rechazado» y no tiene cómo
            // saber qué le faltó.
            'nota' => $this->observacion ?: null,
            'revisadaEl' => $this->revisadoEn?->format('d/m/Y H:i'),
            // Solo si adjuntó respaldo, y solo el nombre: el archivo no se sirve
            // por esta API. Alcanza para que el funcionario confirme que su
            // certificado llegó, que es lo único que necesita ver de su lado.
            'respaldoNombre' => $this->adjunto ? ($this->adjuntoNombre ?: 'Respaldo adjunto') : null,
        ];
    }
}
