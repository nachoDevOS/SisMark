<?php

namespace App\Services;

use App\Models\AsignacionTurno;
use App\Models\Licencia;
use App\Models\Turno;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Anota licencias expandiendo un rango de fechas contra los turnos asignados de
 * cada funcionario: una fila de `licencias` por cada día del rango cuyo día de
 * la semana coincida con el turno y caiga dentro de su vigencia.
 *
 * Trabaja siempre en lote (uno o muchos funcionarios): los feriados alcanzan a
 * más de 400 personas, así que la existencia se consulta de una sola vez y las
 * altas van con `insert()` por bloques, en vez de una consulta por fila.
 *
 * Un día con una licencia vigente no se vuelve a licenciar: se informa y se
 * saltea. Lo **rechazado** y lo **dado de baja** no ocupan el día —se puede
 * volver a pedir— y **nunca se reescriben**: quedan como historial de qué se
 * pidió y cómo terminó. Por eso el pedido nuevo siempre es una fila nueva, y el
 * índice único incluye `solicitud`.
 *
 * El código de turno del SIA (`idTurno`) no se escribe: el horario va por la FK.
 */
class RegistroLicencia
{
    /**
     * Tope de días del rango, para que un error de tipeo en las fechas no genere
     * miles de filas.
     */
    public const MAX_DIAS = 366;

    /**
     * Filas por bloque de inserción.
     */
    private const LOTE = 500;

    /**
     * Turnos asignados que caen dentro del rango, agrupados por carnet: es lo
     * que hace licenciable a un funcionario en esas fechas.
     *
     * Vive acá y no en el controlador porque la usan la pantalla de Recursos
     * Humanos y la API de solicitudes; la regla de solapamiento es sutil y dos
     * copias que se despeguen licenciarían turnos distintos.
     *
     * Con `$cis` vacío no acota por carnet: el rango define a quiénes alcanza,
     * que es como se anota un feriado para todo el personal.
     *
     * @param  list<string>  $cis
     * @param  list<int>  $elegidas  asignaciones puntuales; sin ellas se toman todas las del rango
     * @return Collection<string, Collection<int, AsignacionTurno>>
     */
    public function turnosDelRango(array $cis, Carbon $desde, Carbon $hasta, array $elegidas = []): Collection
    {
        return AsignacionTurno::query()
            ->with('turno')
            ->whereHas('turno')
            ->when($cis !== [], fn (Builder $query) => $query->whereIn('ci', $cis))
            ->when($elegidas !== [], fn (Builder $query) => $query->whereIn('id', $elegidas))
            // Solapamiento de rangos: la asignación sirve si empieza antes de
            // que termine el pedido y termina después de que empiece. Con
            // selección manual no se aplica, para permitir altas retroactivas.
            ->when($elegidas === [], fn (Builder $query) => $query
                ->where('desde', '<=', $hasta->copy()->endOfDay())
                ->where('hasta', '>=', $desde))
            ->get()
            ->groupBy(fn (AsignacionTurno $asignacion): string => trim((string) $asignacion->ci));
    }

    /**
     * Anota las licencias y devuelve el conteo por resultado.
     *
     * @param  Collection<string, Collection<int, AsignacionTurno>>  $asignacionesPorCi  turnos a licenciar, agrupados por carnet
     * @param  array{tCompleto: bool, goceHaberes: bool, motivo: string, lEntra: ?string, lSale: ?string, adjunto?: ?string, adjuntoNombre?: ?string, usuario: string, usuarioId: ?int, estado?: string, origen?: string}  $datos
     * @return array{creadas: int, existentes: int, fueraDeVigencia: int, sinTurno: int, funcionarios: int}
     */
    public function anotar(Collection $asignacionesPorCi, Carbon $desde, Carbon $hasta, array $datos): array
    {
        $conteo = ['creadas' => 0, 'existentes' => 0, 'fueraDeVigencia' => 0, 'sinTurno' => 0, 'funcionarios' => 0];

        if ($asignacionesPorCi->isEmpty()) {
            return $conteo;
        }

        $completo = (bool) $datos['tCompleto'];
        $ahora = now();
        $comunes = [
            'fechaPedido' => $ahora,
            'usuario' => mb_substr($datos['usuario'], 0, 50),
            'lEntra' => $completo ? null : self::sobreFechaBase($datos['lEntra'] ?? null),
            'lSale' => $completo ? null : self::sobreFechaBase($datos['lSale'] ?? null),
            'tCompleto' => $completo,
            'motivo' => $datos['motivo'],
            'goceHaberes' => (bool) $datos['goceHaberes'],
            // El respaldo es uno solo para todo el alta: la misma ruta se copia
            // a cada fila del rango, así se llega al archivo desde cualquiera
            // de ellas sin un join en el listado.
            'adjunto' => $datos['adjunto'] ?? null,
            'adjuntoNombre' => $datos['adjuntoNombre'] ?? null,
            // `Licencia::insert()` no dispara el modelo, así que el default de la
            // columna no se aplica y hay que escribir el estado a mano.
            //
            // «Aprobado» por defecto: lo que anota Recursos Humanos desde el
            // sistema surte efecto en el acto, como siempre. «Pendiente» lo pide
            // explícitamente la API cuando la licencia la solicita el propio
            // funcionario desde Mamoré y todavía nadie la revisó.
            'estado' => $datos['estado'] ?? 'Aprobado',
            // Por dónde entró. «propio» por defecto: es lo que corresponde a la
            // pantalla de Recursos Humanos, que es quien más usa este servicio.
            'origen' => $datos['origen'] ?? Licencia::ORIGEN_PROPIO,
        ];

        $candidatos = $this->candidatos($asignacionesPorCi, $desde, $hasta, $conteo);

        if ($candidatos === []) {
            return $conteo;
        }

        // Una solicitud por funcionario, no una por alta: un feriado alcanza a
        // más de 400 personas y agruparlas todas bajo el mismo identificador
        // dejaría el listado con una fila para 400 legajos, y la ficha —que
        // muestra a una persona— sin saber a cuál.
        $solicitudes = $this->solicitudes($asignacionesPorCi->keys());

        // Solo lo que de verdad ocupa el día: lo vigente y sin rechazar. Lo
        // rechazado y lo dado de baja no bloquean, y **no se tocan**: son el
        // historial de lo que se pidió y de cómo se resolvió.
        $ocupados = $this->ocupados($asignacionesPorCi->keys()->all(), $desde, $hasta);

        $aInsertar = [];
        $alcanzados = [];

        foreach ($candidatos as $clave => $candidato) {
            // Un día ya licenciado no se pisa: o está justificado, o ya hay un
            // pedido esperando decisión.
            if ($ocupados->has($clave)) {
                $conteo['existentes']++;

                continue;
            }

            $alcanzados[$candidato['ci']] = true;

            // Siempre una fila nueva, aunque para ese día haya un pedido
            // rechazado o dado de baja: reescribir aquella fila borraría la
            // constancia de que se pidió y de cómo se resolvió. Por eso el
            // índice único incluye `solicitud` —ver la migración
            // `agregar_solicitud_a_licencias`—, así dos pedidos distintos del
            // mismo día conviven.
            //
            // `insert()` no dispara eventos de modelo, así que el autor y los
            // timestamps que normalmente pone el trait de auditoría van a mano.
            $aInsertar[] = $candidato + $comunes + [
                'solicitud' => $solicitudes[$candidato['ci']],
                'created_at' => $ahora,
                'updated_at' => $ahora,
                'registerUser_id' => $datos['usuarioId'] ?? null,
            ];
        }

        foreach (array_chunk($aInsertar, self::LOTE) as $bloque) {
            Licencia::insert($bloque);
        }

        $conteo['creadas'] = count($aInsertar);
        $conteo['funcionarios'] = count($alcanzados);

        return $conteo;
    }

    /**
     * Un identificador de solicitud por carnet alcanzado.
     *
     * ULID y no autoincremental: se genera en PHP antes del `insert()` masivo,
     * sin ida y vuelta a la base para reservar el número.
     *
     * @param  Collection<int, mixed>  $cis
     * @return array<string, string>
     */
    private function solicitudes(Collection $cis): array
    {
        return $cis
            ->mapWithKeys(fn ($ci): array => [(string) $ci => (string) Str::ulid()])
            ->all();
    }

    /**
     * Pares (ci, fecha, turno) que corresponde licenciar, ya deduplicados por la
     * clave natural: dos asignaciones al mismo turno y día son una sola licencia.
     *
     * @param  Collection<string, Collection<int, AsignacionTurno>>  $asignacionesPorCi
     * @param  array{creadas: int, existentes: int, fueraDeVigencia: int, sinTurno: int, funcionarios: int}  $conteo
     * @return array<string, array{ci: string, fecha: string, turno_id: int}>
     */
    private function candidatos(Collection $asignacionesPorCi, Carbon $desde, Carbon $hasta, array &$conteo): array
    {
        $candidatos = [];
        $fin = $hasta->copy()->startOfDay();

        foreach ($asignacionesPorCi as $ci => $asignaciones) {
            $fecha = $desde->copy()->startOfDay();

            while ($fecha->lessThanOrEqualTo($fin)) {
                foreach ($asignaciones as $asignacion) {
                    $turno = $asignacion->turno;

                    if (! $turno instanceof Turno) {
                        $conteo['sinTurno']++;

                        continue;
                    }

                    if ((int) $turno->dia !== self::diaSia($fecha)) {
                        continue;
                    }

                    if (! self::dentroDeVigencia($asignacion, $fecha)) {
                        $conteo['fueraDeVigencia']++;

                        continue;
                    }

                    $candidatos[self::clave((string) $ci, $fecha->toDateString(), (int) $turno->id)] = [
                        'ci' => (string) $ci,
                        'fecha' => $fecha->toDateString(),
                        'turno_id' => (int) $turno->id,
                    ];
                }

                $fecha->addDay();
            }
        }

        return $candidatos;
    }

    /**
     * Días que ya están ocupados para esos funcionarios dentro del rango,
     * indexados por la clave natural.
     *
     * Solo cuenta lo que **de verdad ocupa** el día: lo vigente y sin rechazar.
     *
     * - Lo **rechazado** no ocupa nada: el pedido se resolvió que no, y el día
     *   quedó libre para volver a pedirlo.
     * - Lo **dado de baja** tampoco: la fila sobrevive como historial, pero no
     *   licencia nada.
     *
     * Ninguna de las dos se reescribe. Son la constancia de qué se pidió y de
     * cómo terminó, y el funcionario las ve en su perfil.
     *
     * @param  list<string>  $cis
     * @return Collection<string, Licencia>
     */
    private function ocupados(array $cis, Carbon $desde, Carbon $hasta): Collection
    {
        return Licencia::query()
            ->whereIn('ci', $cis)
            ->where('estado', '!=', Licencia::RECHAZADO)
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->get(['id', 'ci', 'fecha', 'turno_id'])
            ->keyBy(fn (Licencia $licencia): string => self::clave(
                trim((string) $licencia->ci),
                $licencia->fecha->toDateString(),
                (int) $licencia->turno_id,
            ));
    }

    /**
     * Arma el mensaje de resultado a partir del conteo.
     *
     * @param  array{creadas: int, existentes: int, fueraDeVigencia: int, sinTurno: int, funcionarios: int}  $conteo
     */
    public function mensaje(array $conteo): string
    {
        $partes = ["{$conteo['creadas']} licencia(s) anotada(s)"];

        if ($conteo['funcionarios'] > 1) {
            $partes[0] .= " para {$conteo['funcionarios']} funcionario(s)";
        }

        if ($conteo['existentes'] > 0) {
            $partes[] = "{$conteo['existentes']} ya existían";
        }

        if ($conteo['fueraDeVigencia'] > 0) {
            $partes[] = "{$conteo['fueraDeVigencia']} fuera de la vigencia del turno";
        }

        if ($conteo['sinTurno'] > 0) {
            $partes[] = "{$conteo['sinTurno']} sin horario vinculado";
        }

        return implode(', ', $partes).'.';
    }

    /**
     * Día de la semana en la convención del SIA (1 = Domingo … 7 = Sábado,
     * igual que DATEPART(dw)). Carbon numera el domingo como 0.
     */
    public static function diaSia(Carbon $fecha): int
    {
        return $fecha->dayOfWeek + 1;
    }

    /**
     * Clave natural de una licencia: ci + fecha + turno.
     */
    private static function clave(string $ci, string $fecha, int $turnoId): string
    {
        return $ci.'|'.$fecha.'|'.$turnoId;
    }

    /**
     * La fecha debe caer dentro del rango de vigencia de la asignación de turno.
     */
    private static function dentroDeVigencia(AsignacionTurno $asignacion, Carbon $fecha): bool
    {
        if ($asignacion->desde && $fecha->lessThan($asignacion->desde->copy()->startOfDay())) {
            return false;
        }

        return ! ($asignacion->hasta && $fecha->greaterThan($asignacion->hasta->copy()->endOfDay()));
    }

    /**
     * Convierte una hora «HH:MM» en un datetime sobre la fecha base 1899-12-30,
     * como guarda las horas el SIA (y el resto del sistema).
     */
    private static function sobreFechaBase(?string $hora): ?string
    {
        $hora = trim((string) $hora);

        return $hora === '' ? null : '1899-12-30 '.substr($hora, 0, 5).':00';
    }
}
