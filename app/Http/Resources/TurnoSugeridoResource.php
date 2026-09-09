<?php

namespace App\Http\Resources;

use App\Models\Turno;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Un horario semanal sugerido, armado a partir de los turnos que lo componen.
 *
 * **Por qué esto no es un turno.** `turnos` guarda una fila por día de la
 * semana: el horario general de la Gobernación —lunes a viernes, 08:00 a
 * 16:00— son cinco filas con cinco códigos distintos, idénticas salvo el día.
 * Entregarlas sueltas le daría al consumidor cinco opciones casi iguales para
 * elegir, cuando en realidad es **una** sugerencia. Acá se devuelven juntas: la
 * jornada arriba y los días adentro.
 *
 * `turnoIds` viaja aparte, plano, porque es lo único que necesita mandar de
 * vuelta quien acepte la sugerencia.
 *
 * @property-read Collection<int, Turno> $resource
 */
class TurnoSugeridoResource extends JsonResource
{
    /**
     * Agrupa una colección de turnos en los horarios semanales que forman.
     *
     * @param  Collection<int, Turno>  $turnos
     * @return Collection<int, self>
     */
    public static function agrupar(Collection $turnos): Collection
    {
        return $turnos
            ->groupBy(fn (Turno $turno): string => $turno->clave_horario)
            ->values()
            ->map(fn (Collection $grupo): self => new self($grupo));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Turno $muestra */
        $muestra = $this->resource->first();

        return [
            // El nombre del turno trae el día pegado adelante («LUN: 08:00 -
            // 16:00»), que acá no significa nada porque el horario cubre varios
            // días. Se arma uno propio con las horas, que es lo que distingue
            // una sugerencia de otra.
            'nombre' => $muestra->hEntrada?->format('H:i').' - '.$muestra->hSalida?->format('H:i'),
            'hEntrada' => $muestra->hEntrada?->format('H:i'),
            'hSalida' => $muestra->hSalida?->format('H:i'),
            'hTolerancia' => $muestra->hTolerancia?->format('H:i'),
            'hTrabajadas' => (float) $muestra->hTrabajadas,
            'siguienteDia' => (bool) $muestra->siguienteDia,

            // Las ventanas en las que la marca se acepta. Fuera de ellas el reloj
            // igual registra, pero el procesador no la toma como entrada ni como
            // salida: entre `eMaxima` y `sMinima` hay una zona muerta. Es la
            // pregunta que se hace todo el mundo —«¿desde qué hora puedo
            // marcar?»— y sin esto el consumidor no la puede contestar.
            'eMinima' => $muestra->eMinima?->format('H:i'),
            'eMaxima' => $muestra->eMaxima?->format('H:i'),
            'sMinima' => $muestra->sMinima?->format('H:i'),
            'sMaxima' => $muestra->sMaxima?->format('H:i'),

            'dias' => $this->resource
                ->map(fn (Turno $turno): array => [
                    'dia' => (int) $turno->dia,
                    'diaNombre' => Turno::DIAS[(int) $turno->dia] ?? null,
                    'turnoId' => $turno->id,
                    'idTurno' => trim((string) $turno->idTurno),
                    'nombreTurno' => trim((string) $turno->nombreTurno),
                ])
                ->values()
                ->all(),

            // Lo único que hace falta devolver para aceptar la sugerencia.
            'turnoIds' => $this->resource->pluck('id')->values()->all(),
        ];
    }
}
