<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Lee el CSV de marcaciones y lo deja en las filas que espera
 * {@see RegistroAsistencia::registrar()}.
 *
 * Es el mismo archivo que genera EquipoController::exportarMarcaciones()
 * (columnas CI/ID, Nombre, Fecha, Hora), o uno reguardado desde Excel. Lo usan
 * las dos importaciones: la de Marcaciones y la de Biométricos.
 */
class LectorCsvMarcaciones
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

            [$ci, , $fechaCsv, $horaCsv] = array_pad($columnas, 4, null);

            $fecha = $this->parsearFecha(trim((string) $fechaCsv));
            $hora = $this->parsearHora(trim((string) $horaCsv));

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
