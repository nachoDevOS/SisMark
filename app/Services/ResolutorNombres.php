<?php

namespace App\Services;

use App\Exceptions\MamoreException;
use App\Models\Persona;
use Illuminate\Support\Facades\Cache;

/**
 * Resuelve la ficha de un funcionario a partir de su CI: primero en la API
 * externa de Mamoré y, si esa persona no está ahí (o la API no está
 * configurada / falla), en la base local MySQL (`personas`).
 *
 * Lo usan los listados que muestran una columna «Funcionario» a partir de un CI
 * suelto (marcaciones, licencias) y los reportes. Solo consulta los CI distintos
 * de la página y cachea la respuesta de Mamoré por un día, para no golpear la
 * API fila por fila.
 *
 * La ficha tiene la misma forma que las filas de {@see DirectorioMamore}:
 * `ci`, `nombre`, `cargo`, `direccion`, `pinReloj`, `conContrato`, `image`,
 * `imageThumb`, `origen`.
 *
 * @phpstan-type Ficha array{ci: string, nombre: string, nombreFormal: string, cargo: ?string, direccion: ?string, pinReloj: string, conContrato: ?bool, image: ?string, imageThumb: ?string, origen: string}
 */
class ResolutorNombres
{
    public function __construct(private MamoreClient $mamore, private DirectorioMamore $directorio) {}

    /**
     * Nombre de cada CI, con Mamoré primero y la base local como respaldo.
     *
     * @param  iterable<mixed>  $cis
     * @return array<string, ?string> ci => nombre (o null si no está en ninguno)
     */
    public function porCi(iterable $cis): array
    {
        return collect($this->fichasPorCi($cis))
            ->map(fn (?array $ficha): ?string => $ficha['nombre'] ?? null)
            ->all();
    }

    /**
     * Ficha de cada CI (nombre, cargo y dirección), con Mamoré primero y la base
     * local como respaldo.
     *
     * @param  iterable<mixed>  $cis
     * @return array<string, ?array<string, mixed>> ci => ficha (o null si no está en ninguno)
     */
    public function fichasPorCi(iterable $cis): array
    {
        $cis = collect($cis)
            ->map(fn ($ci): string => trim((string) $ci))
            ->filter()
            ->unique()
            ->values();

        if ($cis->isEmpty()) {
            return [];
        }

        $locales = Persona::query()
            ->with('profesion')
            ->whereIn('ci', $cis->all())
            ->get()
            ->keyBy(fn (Persona $persona): string => trim((string) $persona->ci));

        $deMamore = $this->mamore->configurado() ? $this->fichasMamore($cis->all()) : [];
        $fichas = [];

        foreach ($cis as $ci) {
            $ficha = $deMamore[$ci] ?? null;

            if ($ficha === null && $locales->has($ci)) {
                $ficha = $this->fichaLocal($locales->get($ci));
            }

            $fichas[$ci] = $ficha;
        }

        return $fichas;
    }

    /**
     * Ficha de un solo funcionario. `null` si no está ni en Mamoré ni local.
     *
     * @return array<string, mixed>|null
     */
    public function fichaPorCi(string $ci): ?array
    {
        return $this->fichasPorCi([$ci])[trim($ci)] ?? null;
    }

    /**
     * Fichas de Mamoré de varias cédulas, cacheadas por un día. Las que ya están
     * en caché salen de ahí y las que faltan se piden todas juntas, en un solo
     * pool: antes iba una consulta HTTP por fila, una atrás de la otra, y con la
     * caché fría eso era lo que dejaba el listado en «Cargando…».
     *
     * @param  list<string>  $cis
     * @return array<string, ?array<string, mixed>>
     */
    private function fichasMamore(array $cis): array
    {
        $fichas = [];
        $faltantes = [];

        foreach ($cis as $ci) {
            $cacheado = Cache::get($this->claveCache($ci));

            // El '' cacheado es «Mamoré no tiene a esta persona»: se respeta para
            // no repetir la consulta durante el día.
            if ($cacheado !== null) {
                $fichas[$ci] = $cacheado === '' ? null : $cacheado;

                continue;
            }

            $faltantes[] = $ci;
        }

        if ($faltantes === []) {
            return $fichas;
        }

        try {
            $respuestas = $this->mamore->personasPorCi($faltantes);
        } catch (MamoreException) {
            return $fichas;
        }

        foreach ($respuestas as $ci => $respuesta) {
            $ficha = $respuesta['persona'] === null ? null : $this->desdeMamore($respuesta['persona']);
            $fichas[$ci] = $ficha;

            // Un fallo transitorio de la API no se cachea: se reintenta en la
            // próxima carga en vez de dejar la fila sin nombre todo el día.
            if (! $respuesta['error']) {
                Cache::put($this->claveCache($ci), $ficha ?? '', now()->addDay());
            }
        }

        return $fichas;
    }

    /**
     * La clave lleva versión porque lo cacheado es una ficha ya armada, no la
     * respuesta cruda: al agregarle un campo, las entradas viejas siguen vivas
     * con la forma anterior y la pantalla las lee sin el campo nuevo (pasó con
     * `imageThumb`: la foto no salía hasta que vencía la caché). Subir la
     * versión al cambiar la forma deja las viejas huérfanas y las vence solas.
     */
    private function claveCache(string $ci): string
    {
        return 'mamore.ficha.v2.'.$ci;
    }

    /**
     * Normaliza una persona de Mamoré (con su contrato embebido) a la ficha.
     *
     * @param  array<string, mixed>  $persona
     * @return array<string, mixed>
     */
    private function desdeMamore(array $persona): array
    {
        $fila = $this->directorio->normalizarPersona($persona);

        return [
            'ci' => $fila['ci'],
            'nombre' => $fila['nombre'],
            'nombreFormal' => $fila['nombreFormal'] ?: $fila['nombre'],
            'cargo' => $fila['cargo'] ?: null,
            'direccion' => $fila['direccion'] ?: null,
            'pinReloj' => $fila['pinReloj'],
            'conContrato' => $fila['conContrato'],
            // La miniatura es la que se pinta en las tablas; la original queda
            // como respaldo si Mamoré todavía no la generó.
            'image' => $fila['image'] ?? null,
            'imageThumb' => $fila['imageThumb'] ?? null,
            'origen' => 'mamore',
        ];
    }

    /**
     * Ficha de respaldo desde la base local: SIAT no conoce los contratos, así
     * que no hay cargo ni dirección administrativa.
     *
     * @return array<string, mixed>
     */
    private function fichaLocal(Persona $persona): array
    {
        return [
            'ci' => trim((string) $persona->ci),
            'nombre' => $persona->nombre_completo ?: '—',
            'nombreFormal' => collect([$persona->paterno, $persona->materno, $persona->nombres])
                ->map(fn ($parte): string => trim((string) $parte))
                ->filter()
                ->implode(' ') ?: ($persona->nombre_completo ?: '—'),
            'cargo' => null,
            'direccion' => null,
            'pinReloj' => trim((string) $persona->pinReloj),
            'conContrato' => null,
            // SIAT no guarda fotos: solo las tiene Mamoré.
            'image' => null,
            'imageThumb' => null,
            'origen' => 'siat',
        ];
    }
}
