<?php

namespace App\Http\Resources;

use App\Models\Licencia;
use App\Models\Turno;
use App\Services\ProcesadorAsistencia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un día ya procesado por {@see ProcesadorAsistencia}, para la API.
 *
 * El recurso envuelve un array (la ficha del día), no un modelo, así que se lee
 * de `$this->resource[...]` y no por atributo.
 *
 * Cada magnitud viaja dos veces: en segundos y ya formateada. Los segundos
 * sirven si el consumidor quiere sumar o graficar; el texto es para pintar la
 * fila sin reimplementar el formato de horas, que es justo lo que no queremos
 * que se duplique.
 *
 * **No se exponen los avisos** que arma el procesador («ventana de entrada
 * invertida», «el turno no declara horas trabajadas»): son diagnósticos de
 * configuración para Recursos Humanos, y al funcionario no le dicen nada sobre
 * su asistencia.
 */
class DiaAsistenciaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $dia = $this->resource;

        return [
            'fecha' => $dia['fecha']->toDateString(),
            'diaSemana' => $dia['fecha']->locale('es')->dayName,
            'estado' => $dia['estado'],
            'estadoEtiqueta' => ProcesadorAsistencia::ETIQUETAS[$dia['estado']] ?? $dia['estado'],
            // Por qué el día no se controla: el motivo del feriado o de la
            // licencia. Null en un día común.
            'motivo' => $dia['motivo'],
            'atrasoSegundos' => $dia['atraso'],
            'atraso' => ProcesadorAsistencia::desvio($dia['atraso']),
            'computadoSegundos' => $dia['computado'],
            'computado' => ProcesadorAsistencia::duracion($dia['computado']),
            'esperadoSegundos' => $dia['esperado'],
            'esperado' => ProcesadorAsistencia::duracion($dia['esperado']),
            'marcas' => array_map(ProcesadorAsistencia::hora(...), $dia['marcas']),
            'bloques' => array_map($this->bloque(...), $dia['bloques']),
        ];
    }

    /**
     * Un turno del día con lo que se leyó de él.
     *
     * @param  array<string, mixed>  $bloque
     * @return array<string, mixed>
     */
    private function bloque(array $bloque): array
    {
        $turno = $bloque['turno'];
        $licencia = $bloque['licencia'];

        return [
            'turno' => $turno instanceof Turno ? trim((string) $turno->nombreTurno) : null,
            'horario' => $turno instanceof Turno
                ? $turno->hEntrada?->format('H:i').' - '.$turno->hSalida?->format('H:i')
                : null,
            'entrada' => ProcesadorAsistencia::hora($bloque['entrada']),
            'salida' => ProcesadorAsistencia::hora($bloque['salida']),
            'estado' => $bloque['estado'],
            'estadoEtiqueta' => ProcesadorAsistencia::ETIQUETAS[$bloque['estado']] ?? $bloque['estado'],
            'atrasoSegundos' => $bloque['atraso'],
            'atraso' => ProcesadorAsistencia::desvio($bloque['atraso']),
            'permanencia' => ProcesadorAsistencia::duracion($bloque['permanencia']),
            'computado' => ProcesadorAsistencia::duracion($bloque['computado']),
            'licencia' => $licencia instanceof Licencia ? [
                'motivo' => $licencia->motivo ?: null,
                'turnoCompleto' => (bool) $licencia->tCompleto,
                'conGoceDeHaberes' => (bool) $licencia->goceHaberes,
            ] : null,
        ];
    }
}
