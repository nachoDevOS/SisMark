<?php

namespace App\Http\Resources;

use App\Models\AsignacionTurno;
use App\Models\Turno;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * El turno asignado a un funcionario durante un período de vigencia.
 *
 * **Por qué esto no es una asignación.** `asignacion_turnos` guarda una fila por
 * día de la semana: el horario de lunes a viernes de una persona son cinco
 * filas con las mismas fechas repetidas cinco veces. Entregarlas sueltas le
 * mostraría al funcionario cinco «turnos» donde tiene uno solo, y lo obligaría
 * a reconstruir del otro lado qué filas van juntas. Acá se devuelven agrupadas
 * por vigencia: el período arriba y los días adentro, que es también como lo
 * lee Recursos Humanos en la pantalla de Turnos asignados.
 *
 * Un período puede traer más de una fila para el mismo día —el turno partido,
 * mañana y tarde—, así que `dias` es una lista de tramos y no un día por
 * elemento.
 *
 * @property-read Collection<int, AsignacionTurno> $resource
 */
class TurnoAsignadoResource extends JsonResource
{
    /**
     * Agrupa las asignaciones en los períodos de vigencia que forman,
     * respetando el orden en que vienen.
     *
     * @param  Collection<int, AsignacionTurno>  $asignaciones
     * @return Collection<int, self>
     */
    public static function agrupar(Collection $asignaciones): Collection
    {
        return $asignaciones
            ->groupBy(fn (AsignacionTurno $asignacion): string => $asignacion->clave_periodo)
            ->values()
            ->map(fn (Collection $grupo): self => new self($grupo));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var AsignacionTurno $muestra */
        $muestra = $this->resource->first();

        return [
            'desde' => $muestra->desde?->toDateString(),
            'hasta' => $muestra->hasta?->toDateString(),
            // Respecto de hoy: `vigente`, `futura` o `vencida`. Se resuelve acá
            // y no del otro lado para que los dos sistemas no discrepen por
            // tener el reloj corrido o por comparar en otra zona horaria.
            'situacion' => $muestra->situacion,

            'dias' => $this->resource
                ->map(fn (AsignacionTurno $asignacion): array => $this->tramo($asignacion))
                ->values()
                ->all(),
        ];
    }

    /**
     * Un día del horario, con su jornada y las ventanas en las que la marca
     * cuenta.
     *
     * Las ventanas van porque son la pregunta que se hace todo el mundo —«¿desde
     * qué hora puedo marcar?»—: fuera de ellas el reloj igual registra, pero
     * `ProcesadorAsistencia` no toma esa marca ni como entrada ni como salida.
     *
     * @return array<string, mixed>
     */
    private function tramo(AsignacionTurno $asignacion): array
    {
        $turno = $asignacion->turno;

        return [
            'dia' => (int) $turno->dia,
            'diaNombre' => Turno::DIAS[(int) $turno->dia] ?? null,
            'nombreTurno' => trim((string) $turno->nombreTurno),
            'hEntrada' => $turno->hEntrada?->format('H:i'),
            'hSalida' => $turno->hSalida?->format('H:i'),
            // Hasta qué hora se puede llegar sin que el día cuente como atraso.
            'hTolerancia' => $turno->hTolerancia?->format('H:i'),
            'hTrabajadas' => (float) $turno->hTrabajadas,
            // El turno nocturno sale al día siguiente: sin esto, «22:00 - 06:00»
            // se lee como una jornada de dieciséis horas al revés.
            'siguienteDia' => (bool) $turno->siguienteDia,
            'eMinima' => $turno->eMinima?->format('H:i'),
            'eMaxima' => $turno->eMaxima?->format('H:i'),
            'sMinima' => $turno->sMinima?->format('H:i'),
            'sMaxima' => $turno->sMaxima?->format('H:i'),
        ];
    }
}
