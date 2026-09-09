<?php

namespace App\Services;

use App\Exceptions\MamoreException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Directorio de funcionarios servido únicamente por la API de Mamoré: búsqueda
 * por CI o nombre y ficha por cédula, ya normalizadas a la forma que consumen
 * las vistas (`id`, `ci`, `nombre`, `profesion`, `pinReloj`, `nacimiento`,
 * `edad`, `ver`).
 *
 * No consulta la base local `personas`: para las pantallas que lo usan, Mamoré
 * es la única fuente de datos personales.
 *
 * @phpstan-type FilaFuncionario array{id: mixed, ci: string, nombre: string, profesion: string, pinReloj: string, nacimiento: ?string, edad: ?int, ver: ?string}
 */
class DirectorioMamore
{
    /**
     * Lote que se trae cuando hay que filtrar por varias palabras: la API busca
     * por un solo término, así que el cruce nombre + apellido se hace acá.
     */
    private const LOTE_MULTIPALABRA = 100;

    public function __construct(private MamoreClient $mamore) {}

    /**
     * ¿Está configurada la API? Sin eso el directorio no tiene de dónde leer.
     */
    public function configurado(): bool
    {
        return $this->mamore->configurado();
    }

    /**
     * Funcionarios que coinciden con el término buscado (CI o nombre).
     *
     * Con una sola palabra se delega en el `search` de la API. Con varias se
     * trae un lote por la palabra más larga y se filtra por todas, porque la API
     * no cruza nombre + apellido.
     *
     * @return Collection<int, array<string, mixed>>
     *
     * @throws MamoreException
     */
    public function buscar(string $q, int $limite = 20): Collection
    {
        $q = trim($q);

        if ($q === '') {
            return collect();
        }

        $terminos = preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($terminos) <= 1) {
            $respuesta = $this->mamore->people(1, $limite, $q);

            return collect($this->normalizar($respuesta['data'] ?? []));
        }

        $masLargo = (string) collect($terminos)->sortByDesc(fn (string $t): int => mb_strlen($t))->first();
        $respuesta = $this->mamore->people(1, self::LOTE_MULTIPALABRA, $masLargo);

        return collect($this->normalizar($respuesta['data'] ?? []))
            ->filter(fn (array $fila): bool => $this->coincide($fila, $terminos))
            ->take($limite)
            ->values();
    }

    /**
     * Filas por página al recorrer una dirección. 100 es el tope de la API, y
     * conviene usarlo entero: la cuota es de 60 pedidos por minuto, así que
     * pedir de a 20 gastaría cinco veces más cupo para traer lo mismo.
     */
    private const POR_PAGINA = 100;

    /**
     * Tope de páginas que se recorren de una dirección. Ninguna de la
     * Gobernación llega a 1.000 funcionarios; el corte está para que un cambio
     * del otro lado no deje el reporte pidiendo páginas para siempre.
     */
    private const PAGINAS_MAXIMAS = 10;

    /**
     * La estructura administrativa para los combos del reporte: direcciones y
     * unidades, ya ordenadas por Mamoré y con cuánta gente tuvo cada una en el
     * rango.
     *
     * Las dos vienen del mismo pedido a `/catalogos` a propósito: son pocas
     * filas y la unidad se elige recién después de la dirección, así que pedirlas
     * aparte gastaría un segundo viaje —de una cuota de 60 por minuto— para
     * traer algo que ya estaba en el primero.
     *
     * Se piden solo las que tienen a alguien: un reporte de una dirección vacía
     * no tiene filas que mostrar, y el combo con 70 opciones muertas obliga a
     * probar de a una para descubrir cuál sirve.
     *
     * @return array{direcciones: Collection<int, array{id: int, nombre: string, sigla: string, funcionarios: int}>, unidades: Collection<int, array{id: int, direccionId: ?int, nombre: string, sigla: string, funcionarios: int}>}
     *
     * @throws MamoreException
     */
    public function estructura(?string $desde = null, ?string $hasta = null): array
    {
        $catalogos = $this->mamore->catalogos(
            activas: false,
            conContratos: true,
            desde: $desde,
            hasta: $hasta,
        );

        return [
            'direcciones' => collect($catalogos['direcciones'])
                ->map(fn (array $fila): array => $this->filaDeEstructura($fila))
                ->values(),
            'unidades' => collect($catalogos['unidades'])
                ->map(fn (array $fila): array => $this->filaDeEstructura($fila) + [
                    // De qué dirección cuelga, para filtrar el combo sin otro
                    // viaje cuando se elige una.
                    'direccionId' => isset($fila['direccion_administrativa_id'])
                        ? (int) $fila['direccion_administrativa_id']
                        : null,
                ])
                ->values(),
        ];
    }

    /**
     * Una dirección o una unidad reducida a lo que pinta el combo.
     *
     * @param  array<string, mixed>  $fila
     * @return array{id: int, nombre: string, sigla: string, funcionarios: int}
     */
    private function filaDeEstructura(array $fila): array
    {
        return [
            'id' => (int) $fila['id'],
            'nombre' => trim((string) ($fila['nombre'] ?? '')) ?: 'Sin nombre',
            'sigla' => trim((string) ($fila['sigla'] ?? '')),
            // Personas distintas, no contratos: en un rango alguien puede tener
            // dos —una renovación, o un pase de dirección—, y contar contratos
            // ponía «(221)» arriba de una tabla de 220 filas. El conteo de
            // contratos queda de respaldo por si la API es vieja y todavía no
            // manda el de funcionarios.
            'funcionarios' => (int) ($fila['funcionarios_count'] ?? $fila['contratos_count'] ?? 0),
        ];
    }

    /**
     * Las personas que estuvieron en una dirección durante el rango, ya
     * normalizadas.
     *
     * El rango es lo que hace que la lista sea histórica y no una foto de hoy:
     * con fechas, Mamoré cuenta también los contratos concluidos, así que entra
     * quien se fue a mitad del período. Trabajó esos días y marcó; dejarlo
     * afuera sería perderlo en silencio.
     *
     * Recorre la paginación de la API hasta agotarla, porque el reporte necesita
     * la plantilla entera y no una página.
     *
     * Con `$unidad` se acota a una unidad administrativa de esa dirección; en
     * `null` salen todas, que es el caso normal.
     *
     * @return Collection<int, array<string, mixed>>
     *
     * @throws MamoreException
     */
    public function porDireccion(int $direccion, string $desde, string $hasta, ?int $unidad = null): Collection
    {
        $personas = collect();

        for ($pagina = 1; $pagina <= self::PAGINAS_MAXIMAS; $pagina++) {
            $respuesta = $this->mamore->people(
                page: $pagina,
                limit: self::POR_PAGINA,
                direccion: $direccion,
                desde: $desde,
                hasta: $hasta,
                unidad: $unidad,
            );

            $filas = $respuesta['data'] ?? [];

            if ($filas === []) {
                break;
            }

            $personas = $personas->concat($this->normalizar($filas));

            if (count($filas) < self::POR_PAGINA) {
                break;
            }
        }

        // Sin cédula no hay con qué cruzar las marcaciones: la tabla local de
        // asistencia se indexa por CI y nada más.
        return $personas->filter(fn (array $persona): bool => $persona['ci'] !== '')->values();
    }

    /**
     * Ficha de un funcionario por su cédula. `null` si Mamoré no lo tiene.
     *
     * @return array<string, mixed>|null
     *
     * @throws MamoreException
     */
    public function porCi(string $ci): ?array
    {
        $ci = trim($ci);

        if ($ci === '') {
            return null;
        }

        $persona = $this->mamore->personByCi($ci);

        return $persona === null ? null : $this->normalizarPersona($persona);
    }

    /**
     * Normaliza un lote de filas crudas de la API.
     *
     * @param  array<int, array<string, mixed>>  $data
     * @return array<int, array<string, mixed>>
     */
    public function normalizar(array $data): array
    {
        return collect($data)
            ->map(fn (array $persona): array => $this->normalizarPersona($persona))
            ->all();
    }

    /**
     * Normaliza una fila cruda de la API a la forma común de las vistas.
     *
     * @param  array<string, mixed>  $persona
     * @return array<string, mixed>
     */
    public function normalizarPersona(array $persona): array
    {
        $ci = trim((string) ($persona['ci'] ?? ''));
        $nacimiento = filled($persona['birthday'] ?? null) ? Carbon::parse($persona['birthday']) : null;
        // La API embute el contrato firmado en cada persona (null si no tiene).
        $contrato = is_array($persona['contrato'] ?? null) ? $persona['contrato'] : null;

        return [
            'id' => $persona['id'] ?? null,
            'ci' => $ci,
            'nombre' => trim((string) ($persona['full_name'] ?? trim(
                ($persona['first_name'] ?? '').' '.($persona['middle_name'] ?? '').' '
                .($persona['paternal_surname'] ?? '').' '.($persona['maternal_surname'] ?? '')
            ))) ?: '—',
            // «Apellidos Nombres», como los imprime el reporte de marcaciones
            // desde el sistema de escritorio viejo.
            'nombreFormal' => trim(preg_replace('/\s+/', ' ', implode(' ', array_filter([
                trim((string) ($persona['paternal_surname'] ?? '')),
                trim((string) ($persona['maternal_surname'] ?? '')),
                trim((string) ($persona['first_name'] ?? '')),
                trim((string) ($persona['middle_name'] ?? '')),
            ]))) ?? ''),
            // Extensión del carnet: el departamento que lo emitió («SC», «BE»).
            // Es dato de Mamoré y de nadie más — la tabla local `personas` no
            // tiene la columna—, así que en la ficha local se resuelve por API.
            'extension' => trim((string) ($persona['extension'] ?? '')) ?: null,
            // Cédula con su extensión, tal como Mamoré la arma («7633685 SC»).
            'ciCompleto' => trim((string) ($persona['full_ci'] ?? '')) ?: null,
            'profesion' => trim((string) ($persona['profession'] ?? '')),
            // En Mamoré el PIN del reloj es la misma cédula.
            'pinReloj' => $ci,
            'nacimiento' => $nacimiento?->format('d/m/Y'),
            'edad' => $nacimiento?->age,
            'ver' => $ci !== '' ? route('funcionarios.mamore', ['ci' => $ci]) : null,
            // Del contrato firmado, cuando la persona tiene uno.
            'cargo' => $contrato === null ? null : trim((string) (
                $contrato['cargo_completo'] ?? $contrato['cargo'] ?? $contrato['denominacion'] ?? ''
            )),
            'direccion' => $contrato === null ? null : trim((string) (
                $contrato['direccion_administrativa']['sigla']
                ?? $contrato['direccion_administrativa']['nombre']
                ?? ''
            )),
            // `has_contract` lo informa la API; null si no vino en la respuesta.
            'conContrato' => isset($persona['has_contract']) ? (bool) $persona['has_contract'] : null,
            // Haber y vigencia del contrato firmado. Los usa el régimen
            // disciplinario para expresar el descuento en bolivianos: las
            // escalas del RIP hablan de «días de la remuneración mensual», y sin
            // el sueldo eso queda en una cantidad de días que nadie puede pagar.
            //
            // Se leen tal cual los manda Mamoré y no se completan con cero: un
            // contrato sin sueldo cargado tiene que verse como «sin dato», no
            // como un sueldo de 0 Bs que daría un descuento de 0 y parecería
            // correcto.
            'sueldo' => $this->decimal($contrato['salary'] ?? null),
            'bono' => $this->decimal($contrato['bonus'] ?? null),
            'contratoDesde' => $this->fecha($contrato['start'] ?? null),
            'contratoHasta' => $this->fecha($contrato['finish'] ?? null),
            'image' => $persona['image'] ?? null,
            'imageThumb' => $this->miniatura($persona['image'] ?? null),
        ];
    }

    /**
     * Un importe de la API como número, o `null` si no vino o no es numérico.
     *
     * No se cae a cero a propósito: un contrato sin sueldo cargado tiene que
     * distinguirse de uno que gana 0 Bs, porque el primero es un dato faltante
     * y el segundo sería un descuento legítimo de cero.
     */
    private function decimal(mixed $valor): ?float
    {
        return is_numeric($valor) ? (float) $valor : null;
    }

    /**
     * Una fecha de la API en `Y-m-d`, o `null` si no vino o no se puede leer.
     */
    private function fecha(mixed $valor): ?string
    {
        if (blank($valor)) {
            return null;
        }

        try {
            return Carbon::parse((string) $valor)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * URL de la miniatura cuadrada que Mamoré genera junto a cada foto
     * («foo.png» → «foo-cropped.png»): 300 px en vez de 1181 px, así el avatar
     * del listado no baja el original de más de 1 MB por fila.
     *
     * Devuelve la URL original si no se le reconoce una extensión; la vista
     * cae a la original igual si la miniatura no existe.
     */
    private function miniatura(?string $url): ?string
    {
        if (! filled($url)) {
            return null;
        }

        $miniatura = preg_replace('/\.(\w+)$/', '-cropped.$1', $url, 1, $reemplazos);

        return $reemplazos === 1 ? $miniatura : $url;
    }

    /**
     * Texto de una fila para los combos de funcionario: «CI — NOMBRE · cargo
     * (SIGLA)». El cargo y la dirección salen del contrato firmado, así que en
     * quien no tiene contrato queda solo «CI — NOMBRE».
     *
     * @param  array<string, mixed>  $fila
     */
    public function etiqueta(array $fila): string
    {
        $texto = trim((string) ($fila['ci'] ?? '')).' — '.trim((string) ($fila['nombre'] ?? 'Sin nombre'));
        $cargo = trim((string) ($fila['cargo'] ?? ''));
        $direccion = trim((string) ($fila['direccion'] ?? ''));

        if ($cargo !== '') {
            $texto .= ' · '.$cargo;
        }

        if ($direccion !== '') {
            $texto .= ' ('.$direccion.')';
        }

        return $texto;
    }

    /**
     * ¿La fila contiene todas las palabras buscadas (en su nombre o su CI)?
     *
     * @param  array<string, mixed>  $fila
     * @param  list<string>  $terminos
     */
    private function coincide(array $fila, array $terminos): bool
    {
        $heno = mb_strtolower($fila['nombre'].' '.$fila['ci']);

        foreach ($terminos as $termino) {
            if (! str_contains($heno, mb_strtolower($termino))) {
                return false;
            }
        }

        return true;
    }
}
