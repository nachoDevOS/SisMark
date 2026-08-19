<?php

namespace App\Services;

use App\Models\Asistencia;
use App\Models\Equipo;
use App\Models\Persona;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Registra marcaciones en la tabla local `asistencias` (MySQL), desde cualquier
 * fuente (CSV importado o lectura en vivo de un equipo).
 *
 * Guarda **todo lo que el reloj haya podido registrar**, aunque el carnet no
 * cruce con ningún funcionario del padrón. Una marcación es un hecho: alguien
 * puso el dedo. Que `personas` esté desactualizada —un ingreso reciente que
 * todavía no se migró del SIA— es problema del padrón, y descartar la marca por
 * eso la pierde para siempre en cuanto se limpia el buffer del equipo. La tabla
 * ya admite marcaciones huérfanas: `ci` no tiene clave foránea, justamente
 * porque el legado del SIA venía con ellas.
 *
 * Las huérfanas se cuentan aparte (`sinFuncionario`) para que la bitácora
 * siga avisando que alguien marca con un ID desconocido, pero avisa **sin**
 * perder el dato. Cuando ese carnet entre a `personas`, sus marcaciones
 * aparecen solas: el vínculo se resuelve en la consulta, no está grabado.
 *
 * Lo único que no se guarda es lo que no se puede guardar: sin fecha/hora
 * legible, con fecha futura (el RTC con la pila gastada devuelve años tipo
 * 2064) o sin ningún identificador de usuario.
 *
 * Trabaja **por lotes**: una consulta de funcionarios y una de duplicados cada
 * {@see LOTE} filas, más un insert masivo. Antes iba fila por fila, con dos
 * consultas cada una: bajar el historial completo de un reloj con 50.000
 * marcaciones eran 100.000 consultas.
 */
class RegistroAsistencia
{
    /**
     * Cuántas marcaciones se resuelven por vuelta.
     *
     * Acota las dos consultas del lote y el insert masivo. Más grande ahorra
     * viajes pero engorda el `IN (…)` y el paquete que viaja al servidor; 500
     * mantiene las dos cosas en un tamaño que MySQL resuelve por índice.
     */
    private const LOTE = 500;

    /**
     * Procesa las filas y devuelve el conteo por resultado.
     *
     * Los cuatro resultados son excluyentes y suman el total de filas recibidas,
     * que es lo que le permite a la bitácora cerrar la cuenta. `insertadas` y
     * `sinFuncionario` **se guardaron las dos**; se separan por si el carnet
     * está o no en el padrón.
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

        /** @var array<string, true> $vistas */
        $vistas = [];
        $lote = [];

        foreach ($filas as $fila) {
            $normalizada = $this->normalizar($fila);

            if ($normalizada === null) {
                $conteo['invalidas']++;

                continue;
            }

            // Repetida dentro de la misma tanda: el reloj a veces entrega dos
            // veces el mismo registro. Se descuenta acá y no en el insert
            // masivo, que reventaría contra el índice único.
            if (isset($vistas[$normalizada['clave']])) {
                $conteo['existentes']++;

                continue;
            }

            $vistas[$normalizada['clave']] = true;
            $lote[] = $normalizada;

            if (count($lote) >= self::LOTE) {
                $this->registrarLote($lote, $equipo, $conteo);
                $lote = [];
            }
        }

        if ($lote !== []) {
            $this->registrarLote($lote, $equipo, $conteo);
        }

        return $conteo;
    }

    /**
     * Valida una fila y la deja lista para insertar, o devuelve `null` si no hay
     * forma de guardarla.
     *
     * @param  array{ci?: ?string, momento?: ?Carbon}  $fila
     * @return array{ci: string, fecha: Carbon, hora: string, clave: string}|null
     */
    private function normalizar(array $fila): ?array
    {
        $momento = $fila['momento'] ?? null;

        // Sin fecha/hora parseable no hay «cuándo», y sin eso no hay marcación
        // (o es el encabezado del CSV, que ya filtró quien armó las filas).
        if (! $momento instanceof Carbon) {
            return null;
        }

        $fecha = $momento->copy()->startOfDay();

        // El reloj arrastra fecha basura por la batería del RTC (años tipo
        // 2064/2103): son imposibles, se descartan.
        if ($fecha->isFuture()) {
            return null;
        }

        $ci = trim((string) ($fila['ci'] ?? ''));

        // Sin identificador no se guarda: no hay ni siquiera un número al que
        // atribuirla más adelante, y `ci` no admite nulo.
        if ($ci === '') {
            return null;
        }

        $hora = $momento->format('H:i:s');

        return [
            'ci' => $ci,
            'fecha' => $fecha,
            'hora' => $hora,
            'clave' => $this->clave($ci, $fecha->toDateString(), $hora),
        ];
    }

    /**
     * Resuelve un lote: busca de una vez qué carnets están en el padrón y cuáles
     * marcaciones ya estaban, y mete el resto en un solo insert.
     *
     * @param  list<array{ci: string, fecha: Carbon, hora: string, clave: string}>  $lote
     * @param  array{insertadas: int, existentes: int, sinFuncionario: int, invalidas: int}  $conteo
     */
    private function registrarLote(array $lote, ?Equipo $equipo, array &$conteo): void
    {
        $cis = array_values(array_unique(array_column($lote, 'ci')));
        $fechas = array_values(array_unique(array_map(
            fn (array $fila): string => $fila['fecha']->toDateTimeString(),
            $lote,
        )));

        $conocidos = Persona::query()->whereIn('ci', $cis)->pluck('ci')
            ->map(fn (string $ci): string => trim($ci))
            ->flip();

        $existentes = $this->clavesExistentes($cis, $fechas);

        $ahora = now();
        $usuario = Auth::id();

        $conFuncionario = [];
        $huerfanas = [];

        foreach ($lote as $fila) {
            if (isset($existentes[$fila['clave']])) {
                $conteo['existentes']++;

                continue;
            }

            $registro = [
                'ci' => $fila['ci'],
                'fecha' => $fila['fecha']->toDateTimeString(),
                // La hora se guarda sobre la fecha base 1899-12-30, como el SIA real.
                'hora' => '1899-12-30 '.$fila['hora'],
                'tipo' => Asistencia::TIPO_RELOJ,
                'equipo_id' => $equipo?->id,
                'estado' => 1,
                // El insert masivo no dispara los eventos del modelo, así que la
                // columna que llena el trait RegistersUserEvents se pone acá.
                // Queda nula cuando sincroniza la tarea programada, que no tiene
                // sesión: eso mismo es lo que la bitácora muestra como «Sistema».
                'registerUser_id' => $usuario,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ];

            if (isset($conocidos[$fila['ci']])) {
                $conFuncionario[] = $registro;
            } else {
                $huerfanas[] = $registro;
            }
        }

        // Van en dos inserts y no en uno para poder contar cada grupo por
        // separado: cuántas filas aceptó cada uno es lo que distingue una
        // marcación nueva de una que ya estaba.
        $conteo['insertadas'] += $this->insertar($conFuncionario, $conteo);
        $conteo['sinFuncionario'] += $this->insertar($huerfanas, $conteo);
    }

    /**
     * Inserta un grupo y devuelve cuántas entraron de verdad.
     *
     * Va con `insertOrIgnore` como última red: entre la consulta de duplicados y
     * este insert puede haber entrado la misma marcación desde otra corrida
     * (dos equipos sincronizando a la vez, o el botón manual encima de la tarea
     * programada). El índice único `(ci, fecha, hora)` la rechaza y acá se
     * cuenta como existente, en vez de tirar la corrida entera con una
     * excepción.
     *
     * @param  list<array<string, mixed>>  $registros
     * @param  array{insertadas: int, existentes: int, sinFuncionario: int, invalidas: int}  $conteo
     */
    private function insertar(array $registros, array &$conteo): int
    {
        if ($registros === []) {
            return 0;
        }

        $insertadas = Asistencia::query()->insertOrIgnore($registros);

        $conteo['existentes'] += count($registros) - $insertadas;

        return $insertadas;
    }

    /**
     * Qué marcaciones del lote ya están en la base, como conjunto de claves.
     *
     * Acota por los carnets y las fechas del lote —las dos primeras columnas del
     * índice único—, así la consulta no toca el resto de los millones de filas.
     *
     * La hora se compara en PHP y no con `whereTime()` en SQL porque hay filas
     * viejas migradas del SIA cuya `hora` cuelga de una fecha base distinta de
     * 1899-12-30: comparando la columna entera esas nunca cruzarían y se
     * duplicarían.
     *
     * @param  list<string>  $cis
     * @param  list<string>  $fechas
     * @return array<string, true>
     */
    private function clavesExistentes(array $cis, array $fechas): array
    {
        return Asistencia::query()
            ->whereIn('ci', $cis)
            ->whereIn('fecha', $fechas)
            ->get(['ci', 'fecha', 'hora'])
            ->mapWithKeys(fn (Asistencia $marcacion): array => [
                $this->clave(
                    trim((string) $marcacion->ci),
                    $marcacion->fecha->toDateString(),
                    $marcacion->hora->format('H:i:s'),
                ) => true,
            ])
            ->all();
    }

    /**
     * Clave natural de una marcación: la misma terna del índice único.
     */
    private function clave(string $ci, string $fecha, string $hora): string
    {
        return $ci.'|'.$fecha.'|'.$hora;
    }

    /**
     * Arma el mensaje de resultado a partir del conteo.
     *
     * Las «sin funcionario» se nombran como guardadas, que es lo que son: la
     * frase anterior («sin funcionario vinculado») se leía como que se habían
     * descartado.
     *
     * @param  array{insertadas: int, existentes: int, sinFuncionario: int, invalidas: int}  $conteo
     */
    public function mensaje(array $conteo, string $prefijo = 'Importación completa'): string
    {
        return "{$prefijo}: {$conteo['insertadas']} marcación(es) nueva(s), {$conteo['existentes']} ya existían, "
            ."{$conteo['sinFuncionario']} guardada(s) sin funcionario en el padrón, {$conteo['invalidas']} fila(s) inválida(s).";
    }
}
