<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Lee un archivo de marcaciones y lo deja en las filas que espera
 * {@see RegistroAsistencia::registrar()}. Lo usan las dos importaciones: la de
 * Marcaciones y la de Biométricos. Entiende dos formatos:
 *
 * - **CSV**: el que genera EquipoController::exportarMarcaciones() (columnas
 *   CI/ID, Nombre, Fecha, Hora), o uno reguardado desde Excel.
 * - **`.dat` del reloj** (`attlog.dat`, el que baja el ZKTeco por USB): una
 *   marcación por línea, separada por tabulaciones, con el CI y la fecha y hora
 *   juntas: «  7633685⇥2026-10-14 08:02:11⇥1⇥0⇥1⇥0». Las columnas de después
 *   (estado, verificación) no se usan.
 *
 * El formato se reconoce por el contenido y no por la extensión: la tabulación
 * no aparece en el CSV.
 */
class LectorArchivoMarcaciones
{
    /**
     * Devuelve una fila por marcación del archivo.
     *
     * La primera fila que no parsea como fecha/hora es el encabezado y se
     * descarta sin contarla. El resto de filas ilegibles salen con `momento`
     * nulo, para que {@see RegistroAsistencia} las cuente como inválidas.
     *
     * @return list<array{ci: ?string, momento: ?Carbon}>
     */
    public function leer(string $ruta): array
    {
        $separador = $this->detectarSeparador($ruta);
        $manejador = fopen($ruta, 'r');

        $filas = [];
        $esPrimeraFila = true;

        while (($columnas = fgetcsv($manejador, 0, $separador)) !== false) {
            // Salta las líneas en blanco que suele dejar Excel al final.
            if (count(array_filter($columnas, fn ($celda): bool => trim((string) $celda) !== '')) === 0) {
                continue;
            }

            if ($separador === "\t") {
                [$ci, $momentoDat] = array_pad($columnas, 2, null);
                [$fechaTexto, $horaTexto] = array_pad(explode(' ', trim((string) $momentoDat), 2), 2, '');
            } else {
                [$ci, , $fechaTexto, $horaTexto] = array_pad($columnas, 4, null);
            }

            $ci = trim((string) $ci);
            $fecha = $this->parsearFecha(trim((string) $fechaTexto));
            $hora = $this->parsearHora(trim((string) $horaTexto));

            if ((! $fecha || ! $hora) && $esPrimeraFila) {
                $esPrimeraFila = false;

                continue;
            }

            $esPrimeraFila = false;

            $filas[] = [
                'ci' => $ci,
                'momento' => $fecha && $hora ? $fecha->copy()->setTime($hora->hour, $hora->minute, $hora->second) : null,
            ];
        }

        fclose($manejador);

        return $filas;
    }

    /**
     * Detecta el separador del CSV. Excel en español guarda con ';', mientras
     * que el que exporta el sistema usa ','. Se decide por el que más aparece
     * en la primera línea.
     */
    private function detectarSeparador(string $ruta): string
    {
        $manejador = fopen($ruta, 'r');
        $primeraLinea = (string) fgets($manejador);
        fclose($manejador);

        if (str_contains($primeraLinea, "\t")) {
            return "\t";
        }

        return substr_count($primeraLinea, ';') > substr_count($primeraLinea, ',') ? ';' : ',';
    }

    /**
     * Parsea la fecha probando los formatos que puede dejar el export propio
     * (d/m/Y) o un reguardado desde Excel (d-m-Y, ISO). Devuelve la fecha a
     * medianoche, o null si ninguno encaja.
     */
    private function parsearFecha(string $valor): ?Carbon
    {
        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d'] as $formato) {
            try {
                return Carbon::createFromFormat('!'.$formato, $valor);
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * Parsea la hora con o sin segundos. Devuelve null si no encaja.
     */
    private function parsearHora(string $valor): ?Carbon
    {
        foreach (['H:i:s', 'H:i'] as $formato) {
            try {
                return Carbon::createFromFormat('!'.$formato, $valor);
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }
}
