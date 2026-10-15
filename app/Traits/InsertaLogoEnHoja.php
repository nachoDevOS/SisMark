<?php

namespace App\Traits;

use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * El logo de la Gobernación en la esquina de los reportes en Excel.
 */
trait InsertaLogoEnHoja
{
    /**
     * Inserta el logo escalado al alto del encabezado. Se usa una miniatura
     * cacheada: el PNG original ronda los 2 MB y quedaría incrustado entero en
     * cada archivo descargado.
     */
    private function insertarLogo(Worksheet $hoja): void
    {
        $miniatura = $this->miniaturaLogo();

        if ($miniatura === null) {
            return;
        }

        $dibujo = new Drawing;
        $dibujo->setName('GADBENI');
        $dibujo->setDescription('Gobierno Autónomo Departamental del Beni');
        $dibujo->setPath($miniatura);
        $dibujo->setHeight(72);
        $dibujo->setOffsetX(4);
        $dibujo->setOffsetY(2);
        $dibujo->setCoordinates('A1');
        $dibujo->setWorksheet($hoja);
    }

    /**
     * Ruta de la miniatura del logo, generándola con GD la primera vez. Si el
     * original no existe o GD falla, devuelve null y la hoja sale sin logo.
     */
    private function miniaturaLogo(): ?string
    {
        $original = public_path('image/icon.png');

        if (! is_file($original)) {
            return null;
        }

        $miniatura = storage_path('app/private/reporte-logo-120.png');

        if (is_file($miniatura) && filemtime($miniatura) >= filemtime($original)) {
            return $miniatura;
        }

        $fuente = @imagecreatefrompng($original);

        if ($fuente === false) {
            return null;
        }

        $ancho = 120;
        $alto = max(1, (int) round(imagesy($fuente) * ($ancho / imagesx($fuente))));
        $destino = imagecreatetruecolor($ancho, $alto);

        // Conserva la transparencia del PNG original.
        imagealphablending($destino, false);
        imagesavealpha($destino, true);
        imagecopyresampled($destino, $fuente, 0, 0, 0, 0, $ancho, $alto, imagesx($fuente), imagesy($fuente));

        if (! is_dir(dirname($miniatura))) {
            mkdir(dirname($miniatura), 0755, true);
        }

        imagepng($destino, $miniatura);
        imagedestroy($destino);
        imagedestroy($fuente);

        return $miniatura;
    }
}
