<?php

namespace App\Http\Resources;

use App\Models\AsignacionHorario;
use App\Models\Horario;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * El horario asignado a un funcionario durante un período de vigencia.
 *
 * **Por qué esto no es una asignación.** `asignacion_horarios` guarda una fila por
 * día de la semana: el horario de lunes a viernes de una persona son cinco
 * filas con las mismas fechas repetidas cinco veces. Entregarlas sueltas le
 * mostraría al funcionario cinco «horarios» donde tiene uno solo, y lo obligaría
 * a reconstruir del otro lado qué filas van juntas. Acá se devuelven agrupadas
 * por vigencia: el período arriba y los días adentro, que es también como lo
 * lee Recursos Humanos en la pantalla de Horarios asignados.
 *
 * Un período puede traer más de una fila para el mismo día —el horario partido,
 * mañana y tarde—, así que `dias` es una lista de tramos y no un día por
 * elemento.
 *
 * @property-read Collection<int, AsignacionHorario> $resource
 */
class HorarioAsignadoResource extends JsonResource
{
    /**
     * Agrupa las asignaciones en los períodos de vigencia que forman,
     * respetando el orden en que vienen.
     *
     * @param  Collection<int, AsignacionHorario>  $asignaciones
     * @return Collection<int, self>
     */
    public static function agrupar(Collection $asignaciones): Collection
    {
        return $asignaciones
            ->groupBy(fn (AsignacionHorario $asignacion): string => $asignacion->clave_periodo)
            ->values()
            ->map(fn (Collection $grupo): self => new self($grupo));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var AsignacionHorario $muestra */
        $muestra = $this->resource->first();

        return [
            'desde' => $muestra->desde?->toDateString(),
            'hasta' => $muestra->hasta?->toDateString(),
            // Respecto de hoy: `vigente`, `futura` o `vencida`. Se resuelve acá
            // y no del otro lado para que los dos sistemas no discrepen por
            // tener el reloj corrido o por comparar en otra zona horaria.
            'situacion' => $muestra->situacion,
            // El turno del que salen estos horarios; null en lo heredado del
            // sistema anterior, que se asignaba horario por horario.
            'turno' => $muestra->asignacionTurno?->turno?->nombre,

            'dias' => $this->resource
                ->map(fn (AsignacionHorario $asignacion): array => $this->tramo($asignacion))
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
    private function tramo(AsignacionHorario $asignacion): array
    {
        $horario = $asignacion->horario;

        return [
            'dia' => (int) $horario->dia,
            'diaNombre' => Horario::DIAS[(int) $horario->dia] ?? null,
            'nombreHorario' => trim((string) $horario->nombreHorario),
            'hEntrada' => $horario->hEntrada?->format('H:i'),
            'hSalida' => $horario->hSalida?->format('H:i'),
            // Hasta qué hora se puede llegar sin que el día cuente como atraso.
            'hTolerancia' => $horario->hTolerancia?->format('H:i'),
            'hTrabajadas' => (float) $horario->hTrabajadas,
            // El horario nocturno sale al día siguiente: sin esto, «22:00 - 06:00»
            // se lee como una jornada de dieciséis horas al revés.
            'siguienteDia' => (bool) $horario->siguienteDia,
            'eMinima' => $horario->eMinima?->format('H:i'),
            'eMaxima' => $horario->eMaxima?->format('H:i'),
            'sMinima' => $horario->sMinima?->format('H:i'),
            'sMaxima' => $horario->sMaxima?->format('H:i'),
        ];
    }
}
