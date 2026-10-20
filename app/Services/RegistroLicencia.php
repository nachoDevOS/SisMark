<?php

namespace App\Services;

use App\Models\AsignacionHorario;
use App\Models\Horario;
use App\Models\Licencia;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Anota licencias expandiendo un rango de fechas contra los horarios asignados de
 * cada funcionario: una fila de `licencias` por cada día del rango cuyo día de
 * la semana coincida con el horario y caiga dentro de su vigencia.
 *
 * Trabaja siempre en lote (uno o muchos funcionarios): los feriados alcanzan a
 * más de 400 personas, así que la existencia se consulta de una sola vez y las
 * altas van con `insert()` por bloques, en vez de una consulta por fila.
 *
 * Un día y horario se puede licenciar más de una vez **mientras los tramos no se
 * pisen**: 08:00–12:00 y 12:00–16:00 conviven, igual que 08:00–09:00 y
 * 14:00–16:00. Lo que choca —un tramo que se superpone, o cualquier cosa contra
 * una licencia de horario completo— se informa y se saltea. Lo **rechazado** y
 * lo **dado de baja** no ocupan el día —se puede
 * volver a pedir— y **nunca se reescriben**: quedan como historial de qué se
 * pidió y cómo terminó. Por eso el pedido nuevo siempre es una fila nueva, y el
 * índice único incluye `solicitud`.
 *
 * Con los contratos de cada funcionario, solo se licencian los días que algún
 * contrato cubre —firmado o ya concluido—: quien se fue el 15 no recibe licencia
 * del 16 en adelante aunque el rango llegue a fin de mes. Los días que quedan
 * afuera se cuentan y se informan.
 *
 * El código de horario del SIA (`idHorario`) no se escribe: el horario va por la FK.
 *
 * @phpstan-import-type Tramo from ContratosFuncionario
 */
class RegistroLicencia
{
    public function __construct(private ContratosFuncionario $contratos) {}

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
     * Horarios asignados que caen dentro del rango, agrupados por carnet: es lo
     * que hace licenciable a un funcionario en esas fechas.
     *
     * Vive acá y no en el controlador porque la usan la pantalla de Recursos
     * Humanos y la API de solicitudes; la regla de solapamiento es sutil y dos
     * copias que se despeguen licenciarían horarios distintos.
     *
     * Con `$cis` vacío no acota por carnet: el rango define a quiénes alcanza,
     * que es como se anota un feriado para todo el personal.
     *
     * @param  list<string>  $cis
     * @param  list<int>  $elegidas  asignaciones puntuales; sin ellas se toman todas las del rango
     * @return Collection<string, Collection<int, AsignacionHorario>>
     */
    public function horariosDelRango(array $cis, Carbon $desde, Carbon $hasta, array $elegidas = []): Collection
    {
        return AsignacionHorario::query()
            ->with('horario')
            ->whereHas('horario')
            ->when($cis !== [], fn (Builder $query) => $query->whereIn('ci', $cis))
            ->when($elegidas !== [], fn (Builder $query) => $query->whereIn('id', $elegidas))
            // Solapamiento de rangos: la asignación sirve si empieza antes de
            // que termine el pedido y termina después de que empiece. Con
            // selección manual no se aplica, para permitir altas retroactivas.
            ->when($elegidas === [], fn (Builder $query) => $query
                ->where('desde', '<=', $hasta->toDateString())
                ->where('hasta', '>=', $desde->toDateString()))
            ->get()
            ->groupBy(fn (AsignacionHorario $asignacion): string => trim((string) $asignacion->ci));
    }

    /**
     * Anota las licencias y devuelve el conteo por resultado.
     *
     * `$contratos` son los tramos de contrato de cada carnet en el rango; con
     * ellos se saltean los días sin contrato. En `null` —no se conocen— se
     * licencia todo el rango.
     *
     * @param  Collection<string, Collection<int, AsignacionHorario>>  $asignacionesPorCi  horarios a licenciar, agrupados por carnet
     * @param  array<string, list<Tramo>>|null  $contratos
     * @param  array{tCompleto: bool, goceHaberes: bool, motivo: string, lEntra: ?string, lSale: ?string, adjunto?: ?string, adjuntoNombre?: ?string, usuario: string, usuarioId: ?int, origen?: string, tipo: string}  $datos
     * @return array{creadas: int, existentes: int, fueraDeVigencia: int, fueraDeContrato: int, sinHorario: int, diasSinHorario: list<string>, funcionarios: int}
     */
    public function anotar(Collection $asignacionesPorCi, Carbon $desde, Carbon $hasta, array $datos, ?array $contratos = null): array
    {
        $conteo = ['creadas' => 0, 'existentes' => 0, 'fueraDeVigencia' => 0, 'fueraDeContrato' => 0, 'sinHorario' => 0, 'diasSinHorario' => [], 'funcionarios' => 0];

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
            // «Pendiente» siempre: venga de Recursos Humanos desde SisMark o
            // del propio funcionario desde Mamoré, la licencia no justifica
            // nada hasta que alguien la apruebe desde su ficha.
            'estado' => Licencia::PENDIENTE,
            // Por dónde entró. «propio» por defecto: es lo que corresponde a la
            // pantalla de Recursos Humanos, que es quien más usa este servicio.
            'origen' => $datos['origen'] ?? Licencia::ORIGEN_PROPIO,
            // Qué es: permiso personal o licencia institucional. Lo dice quien
            // anota; la API de Mamoré lo deriva del alcance.
            'tipo' => $datos['tipo'],
        ];

        $candidatos = $this->candidatos($asignacionesPorCi, $desde, $hasta, $conteo, $contratos);

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
            // Un tramo ya licenciado no se pisa: o está justificado, o ya hay
            // un pedido esperando decisión. Otro tramo del mismo día sí entra.
            if (self::choca($ocupados->get($clave, []), $completo, $datos['lEntra'] ?? null, $datos['lSale'] ?? null)) {
                $conteo['existentes']++;

                continue;
            }

            $alcanzados[$candidato['ci']] = true;

            // Siempre una fila nueva, aunque para ese día haya un pedido
            // rechazado o dado de baja: reescribir aquella fila borraría la
            // constancia de que se pidió y de cómo se resolvió. Por eso el
            // índice único incluye `solicitud` —ver la migración
            // `create_licencias_table`—, así dos pedidos distintos del
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
     * Los días que {@see anotar()} crearía para cada carnet: los que tienen horario
     * en el rango y donde el tramo pedido no choca con lo ya licenciado. Es lo
     * que se mide contra el tope mensual de permisos ({@see TopePermisos}) antes
     * de anotar.
     *
     * Sin horas, el pedido se toma como de horario completo: choca con
     * cualquier licencia del día.
     *
     * @param  Collection<string, Collection<int, AsignacionHorario>>  $asignacionesPorCi
     * @param  array<string, list<Tramo>>|null  $contratos  los mismos de {@see anotar()}
     * @return array<string, list<string>> fechas `Y-m-d` por carnet
     */
    public function fechasNuevas(Collection $asignacionesPorCi, Carbon $desde, Carbon $hasta, ?string $lEntra = null, ?string $lSale = null, ?array $contratos = null): array
    {
        $conteo = ['creadas' => 0, 'existentes' => 0, 'fueraDeVigencia' => 0, 'fueraDeContrato' => 0, 'sinHorario' => 0, 'diasSinHorario' => [], 'funcionarios' => 0];
        $ocupados = $this->ocupados($asignacionesPorCi->keys()->all(), $desde, $hasta);
        $fechas = [];

        foreach ($this->candidatos($asignacionesPorCi, $desde, $hasta, $conteo, $contratos) as $clave => $candidato) {
            if (! self::choca($ocupados->get($clave, []), false, $lEntra, $lSale)) {
                $fechas[$candidato['ci']][$candidato['fecha']] = true;
            }
        }

        return array_map(fn (array $dias): array => array_keys($dias), $fechas);
    }

    /**
     * Los carnets que tienen turno que licenciar en el rango: algún horario
     * asignado cuyo día de la semana cae en el rango y dentro de su vigencia.
     * Sin eso, {@see anotar()} no genera ninguna fila para ellos.
     *
     * No mira contratos ni licencias ya anotadas: responde solo si hay turno.
     *
     * @param  list<string>  $cis
     * @return list<string>
     */
    public function conTurno(array $cis, Carbon $desde, Carbon $hasta): array
    {
        if ($cis === []) {
            return [];
        }

        $conteo = ['creadas' => 0, 'existentes' => 0, 'fueraDeVigencia' => 0, 'fueraDeContrato' => 0, 'sinHorario' => 0, 'diasSinHorario' => [], 'funcionarios' => 0];
        $candidatos = $this->candidatos($this->horariosDelRango($cis, $desde, $hasta), $desde, $hasta, $conteo);

        return array_values(array_unique(array_column($candidatos, 'ci')));
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
     * Pares (ci, fecha, horario) que corresponde licenciar, ya deduplicados por la
     * clave natural: dos asignaciones al mismo horario y día son una sola licencia.
     *
     * @param  Collection<string, Collection<int, AsignacionHorario>>  $asignacionesPorCi
     * @param  array{creadas: int, existentes: int, fueraDeVigencia: int, fueraDeContrato: int, sinHorario: int, diasSinHorario: list<string>, funcionarios: int}  $conteo
     * @param  array<string, list<Tramo>>|null  $contratos
     * @return array<string, array{ci: string, fecha: string, horario_id: int}>
     */
    private function candidatos(Collection $asignacionesPorCi, Carbon $desde, Carbon $hasta, array &$conteo, ?array $contratos = null): array
    {
        $candidatos = [];
        $fin = $hasta->copy()->startOfDay();

        foreach ($asignacionesPorCi as $ci => $asignaciones) {
            $fecha = $desde->copy()->startOfDay();

            while ($fecha->lessThanOrEqualTo($fin)) {
                $tieneHorario = false;
                $enContrato = $contratos === null || $this->contratos->cubierto($contratos[(string) $ci] ?? [], $fecha);
                $fueraDeContrato = false;

                foreach ($asignaciones as $asignacion) {
                    $horario = $asignacion->horario;

                    if (! $horario instanceof Horario) {
                        $conteo['sinHorario']++;

                        continue;
                    }

                    if ((int) $horario->dia !== self::diaSia($fecha)) {
                        continue;
                    }

                    $tieneHorario = true;

                    // Tenía horario, pero ese día ya no —o todavía no— tenía
                    // contrato: no hay jornada que licenciar.
                    if (! $enContrato) {
                        $fueraDeContrato = true;

                        continue;
                    }

                    if (! self::dentroDeVigencia($asignacion, $fecha)) {
                        $conteo['fueraDeVigencia']++;

                        continue;
                    }

                    $candidatos[self::clave((string) $ci, $fecha->toDateString(), (int) $horario->id)] = [
                        'ci' => (string) $ci,
                        'fecha' => $fecha->toDateString(),
                        'horario_id' => (int) $horario->id,
                    ];
                }

                // Sin horario ese día de la semana —un sábado, un domingo— no hay
                // nada que licenciar. Se anota para poder decir por qué.
                if (! $tieneHorario) {
                    $conteo['diasSinHorario'][] = $fecha->toDateString();
                }

                if ($fueraDeContrato) {
                    $conteo['fueraDeContrato']++;
                }

                $fecha->addDay();
            }
        }

        return $candidatos;
    }

    /**
     * Tramos ya licenciados para esos funcionarios dentro del rango, agrupados
     * por la clave natural: un mismo día y horario puede tener varios.
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
     * @return Collection<string, list<array{completo: bool, entra: ?string, sale: ?string}>>
     */
    private function ocupados(array $cis, Carbon $desde, Carbon $hasta): Collection
    {
        return Licencia::query()
            ->whereIn('ci', $cis)
            ->where('estado', '!=', Licencia::RECHAZADO)
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->get(['id', 'ci', 'fecha', 'horario_id', 'tCompleto', 'lEntra', 'lSale'])
            ->groupBy(fn (Licencia $licencia): string => self::clave(
                trim((string) $licencia->ci),
                $licencia->fecha->toDateString(),
                (int) $licencia->horario_id,
            ))
            ->map(fn (Collection $licencias): array => $licencias
                ->map(fn (Licencia $licencia): array => [
                    'completo' => (bool) $licencia->tCompleto,
                    'entra' => $licencia->lEntra?->format('H:i'),
                    'sale' => $licencia->lSale?->format('H:i'),
                ])
                ->values()
                ->all());
    }

    /**
     * ¿El tramo pedido se pisa con lo ya licenciado ese día y horario?
     *
     * Choca si alguno de los dos es de horario completo —o no tiene horas, que
     * es lo mismo: no se sabe qué tramo ocupa— o si los tramos se superponen.
     * Tocarse en el borde no es pisarse: 08:00–12:00 y 12:00–16:00 conviven.
     *
     * @param  list<array{completo: bool, entra: ?string, sale: ?string}>  $existentes
     */
    private static function choca(array $existentes, bool $completo, ?string $lEntra, ?string $lSale): bool
    {
        if ($existentes === []) {
            return false;
        }

        $entra = substr(trim((string) $lEntra), 0, 5);
        $sale = substr(trim((string) $lSale), 0, 5);

        if ($completo || $entra === '' || $sale === '') {
            return true;
        }

        foreach ($existentes as $existente) {
            if ($existente['completo'] || $existente['entra'] === null || $existente['sale'] === null) {
                return true;
            }

            // «HH:MM» se compara bien como texto.
            if ($entra < $existente['sale'] && $existente['entra'] < $sale) {
                return true;
            }
        }

        return false;
    }

    /**
     * Arma el mensaje de resultado a partir del conteo.
     *
     * @param  array{creadas: int, existentes: int, fueraDeVigencia: int, fueraDeContrato: int, sinHorario: int, diasSinHorario: list<string>, funcionarios: int}  $conteo
     */
    public function mensaje(array $conteo): string
    {
        // Con nada anotado el «0 licencia(s) anotada(s)» no dice nada: lo que
        // sirve son los motivos.
        $partes = $conteo['creadas'] > 0 ? ["{$conteo['creadas']} licencia(s) anotada(s)"] : [];

        if ($partes !== [] && $conteo['funcionarios'] > 1) {
            $partes[0] .= " para {$conteo['funcionarios']} funcionario(s)";
        }

        // Todo nace pendiente: que quien anota sepa que todavía no cuenta.
        if ($partes !== []) {
            $partes[0] .= ', pendiente(s) de aprobación';
        }

        if ($conteo['diasSinHorario'] !== []) {
            $dias = array_values(array_unique($conteo['diasSinHorario']));
            sort($dias);
            // Pocos días se nombran; muchos —un feriado para cientos de
            // personas— se cuentan.
            $partes[] = count($dias) <= 3
                ? 'sin horario asignado el '.implode(', ', array_map(
                    fn (string $dia): string => Carbon::parse($dia)->translatedFormat('l d/m'),
                    $dias,
                ))
                : count($conteo['diasSinHorario']).' día(s) sin horario asignado';
        }

        if ($conteo['existentes'] > 0) {
            $partes[] = "{$conteo['existentes']} se pisan con una licencia ya anotada en ese horario";
        }

        if ($conteo['fueraDeContrato'] > 0) {
            $partes[] = "{$conteo['fueraDeContrato']} día(s) fuera del contrato no se anotaron";
        }

        if ($conteo['fueraDeVigencia'] > 0) {
            $partes[] = "{$conteo['fueraDeVigencia']} fuera de la vigencia del horario";
        }

        if ($conteo['sinHorario'] > 0) {
            $partes[] = "{$conteo['sinHorario']} sin horario vinculado";
        }

        return ($partes === [] ? 'no había días para anotar' : implode(', ', $partes)).'.';
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
     * Clave natural de una licencia: ci + fecha + horario.
     */
    private static function clave(string $ci, string $fecha, int $horarioId): string
    {
        return $ci.'|'.$fecha.'|'.$horarioId;
    }

    /**
     * La fecha debe caer dentro del rango de vigencia de la asignación de horario.
     */
    private static function dentroDeVigencia(AsignacionHorario $asignacion, Carbon $fecha): bool
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
