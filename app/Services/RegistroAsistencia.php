<?php

namespace App\Services;

use App\Models\Asistencia;
use App\Models\Equipo;
use App\Models\Persona;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * Registra marcaciones en la tabla local `asistencias` (MySQL), desde cualquier
 * fuente (CSV importado o lectura en vivo de un equipo). Aplica siempre las
 * mismas reglas: cruza el ID del reloj contra los funcionarios locales por `ci`,
 * descarta fechas basura del RTC (futuras) y no duplica lo que ya está
 * (mismo ci + fecha + hora).
 */
class RegistroAsistencia
{
    /**
     * Procesa las filas y devuelve el conteo por resultado.
     *
     * `$equipo` es el reloj del que salieron, y queda guardado en cada marcación
     * que se inserte. Va en null cuando la fuente no lo sabe: el CSV no dice de
     * qué equipo se exportó, y el alta manual no viene de ninguno.
     *
     * @param  iterable<array{ci: ?string, momento: ?Carbon}>  $filas
     * @return array{insertadas: int, existentes: int, sinFuncionario: int, invalidas: int}
     */
    public function registrar(iterable $filas, ?Equipo $equipo = null): array
    {
        $conteo = ['insertadas' => 0, 'existentes' => 0, 'sinFuncionario' => 0, 'invalidas' => 0];

        foreach ($filas as $fila) {
            $momento = $fila['momento'] ?? null;

            // Sin fecha/hora parseable: fila inválida (o encabezado ya filtrado
            // por quien arma las filas).
            if (! $momento instanceof Carbon) {
                $conteo['invalidas']++;

                continue;
            }

            $fecha = $momento->copy()->startOfDay();

            // El reloj arrastra fecha basura por la batería del RTC (años tipo
            // 2064/2103): se descartan.
            if ($fecha->isFuture()) {
                $conteo['invalidas']++;

                continue;
            }

            $ci = trim((string) ($fila['ci'] ?? ''));
            $persona = $ci !== '' ? Persona::query()->where('ci', $ci)->first() : null;

            if (! $persona) {
                $conteo['sinFuncionario']++;

                continue;
            }

            // Igual que en MarcacionController::store(): `fecha` entera para caer
            // sobre el índice (ci, fecha, hora) —siempre está a medianoche— y
            // `hora` por `whereTime()`, porque hay filas viejas del SIA con una
            // fecha base distinta de 1899-12-30. Acá pesa más: corre una vez por
            // cada fila del CSV.
            $yaExiste = Asistencia::query()
                ->where('ci', $persona->ci)
                ->where('fecha', $fecha)
                ->whereTime('hora', $momento->format('H:i:s'))
                ->exists();

            if ($yaExiste) {
                $conteo['existentes']++;

                continue;
            }

            try {
                Asistencia::create([
                    'ci' => $persona->ci,
                    'fecha' => $fecha,
                    // La hora se guarda sobre la fecha base 1899-12-30, como el SIA real.
                    'hora' => '1899-12-30 '.$momento->format('H:i:s'),
                    'tipo' => Asistencia::TIPO_RELOJ,
                    'equipo_id' => $equipo?->id,
                ]);

                $conteo['insertadas']++;
            } catch (QueryException) {
                $conteo['invalidas']++;
            }
        }

        return $conteo;
    }

    /**
     * Arma el mensaje de resultado a partir del conteo.
     *
     * @param  array{insertadas: int, existentes: int, sinFuncionario: int, invalidas: int}  $conteo
     */
    public function mensaje(array $conteo, string $prefijo = 'Importación completa'): string
    {
        return "{$prefijo}: {$conteo['insertadas']} marcación(es) nueva(s), {$conteo['existentes']} ya existían, "
            ."{$conteo['sinFuncionario']} sin funcionario vinculado, {$conteo['invalidas']} fila(s) inválida(s).";
    }
}
