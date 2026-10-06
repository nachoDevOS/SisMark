<?php

namespace App\Services;

use App\Models\Horario;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Arma el reporte de marcaciones procesadas como .xlsx nativo, con la misma
 * maqueta que la versión imprimible (`reportes/marcaciones/procesado/print`):
 * logo, encabezado institucional, las 13 columnas con la fecha y el día
 * combinados por jornada, totales y firmas. El resumen de horas, los días por
 * estado y las referencias quedan solo en el imprimible.
 *
 * Todas las celdas de datos se escriben como texto explícito: si se dejaran
 * como números, Excel convertiría «08:25:00» en una hora y «1/7/2026» en una
 * fecha, y el reporte dejaría de leerse igual que el imprimible.
 *
 * El QR del imprimible no viaja: generarlo en PNG requiere la extensión
 * imagick, que no está instalada, y un SVG no se puede incrustar en una hoja.
 */
class ExcelMarcacionesProcesadas
{
    /**
     * Última columna de la maqueta (13 columnas: Fecha … Motivo licencia).
     */
    private const ULTIMA_COLUMNA = 'M';

    /**
     * Ancho de cada columna, en caracteres.
     *
     * @var array<string, int>
     */
    private const ANCHOS = [
        'A' => 11, 'B' => 11, 'C' => 22, 'D' => 10, 'E' => 10, 'F' => 9,
        'G' => 11, 'H' => 13, 'I' => 12, 'J' => 12, 'K' => 7, 'L' => 8, 'M' => 30,
    ];

    /**
     * Encabezados de la tabla, en orden.
     *
     * @var list<string>
     */
    private const CABECERAS = [
        'Fecha', 'Día', 'Horario', 'Entró', 'Salió', 'Atraso', 'Abandono', 'Falta',
        'Entrada lic.', 'Salida lic.', 'T.C.', 'C.G.H.', 'Motivo licencia',
    ];

    /**
     * Genera el archivo y devuelve la ruta temporal donde quedó escrito. El
     * llamador es responsable de borrarlo (con `deleteFileAfterSend()`).
     *
     * @param  array<string, mixed>  $persona  Ficha resuelta (Mamoré o base local).
     * @param  Collection<int, array<string, mixed>>  $dias
     * @param  array<string, mixed>  $totales
     */
    public function generar(array $persona, Collection $dias, array $totales, string $desde, string $hasta): string
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle('Marcaciones');

        $fila = $this->escribirEncabezado($hoja, $persona, $desde, $hasta);
        $fila = $this->escribirTabla($hoja, $dias, $fila);
        $fila = $this->escribirTotales($hoja, $totales, $fila);
        $this->escribirFirmas($hoja, $fila);

        $this->aplicarFormatoHoja($hoja);

        $ruta = (string) tempnam(sys_get_temp_dir(), 'sismark-xlsx-');

        (new Xlsx($libro))->save($ruta);

        $libro->disconnectWorksheets();

        return $ruta;
    }

    /**
     * Nombre del archivo que ve el usuario.
     *
     * @param  array<string, mixed>  $persona
     */
    public function nombreArchivo(array $persona): string
    {
        $ci = preg_replace('/[^A-Za-z0-9\-]/', '', (string) $persona['ci']);

        return "marcaciones-procesadas-{$ci}-".now()->format('Y-m-d').'.xlsx';
    }

    /**
     * Logo, títulos institucionales y datos del funcionario. Devuelve la fila
     * donde arranca la tabla.
     *
     * @param  array<string, mixed>  $persona
     */
    private function escribirEncabezado(Worksheet $hoja, array $persona, string $desde, string $hasta): int
    {
        $nombreEmpleado = $persona['nombreFormal'] ?: $persona['nombre'];
        $pin = $persona['pinReloj'] ?: '—';
        $cargo = $persona['cargo'] ?? null;
        $direccionAdmin = $persona['direccion'] ?? null;
        $desdeFmt = $desde !== '' ? Carbon::parse($desde)->format('j/n/Y') : '—';
        $hastaFmt = $hasta !== '' ? Carbon::parse($hasta)->format('j/n/Y') : '—';

        $titulos = [
            1 => ['GOBIERNO AUTONOMO DEPARTAMENTAL DEL BENI', 14],
            2 => ['REPORTE DE MARCACIONES', 12],
            3 => ['TRINIDAD', 12],
            4 => ['Marcaciones procesadas', 10],
        ];

        foreach ($titulos as $numero => [$texto, $tamano]) {
            $hoja->mergeCells("C{$numero}:J{$numero}");
            $hoja->setCellValue("C{$numero}", $texto);
            $hoja->getStyle("C{$numero}")->getFont()->setSize($tamano)->setBold($tamano > 10);
            $hoja->getStyle("C{$numero}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        // «Impreso por» a la derecha, donde el imprimible pone el QR.
        $hoja->mergeCells('K1:M2');
        $hoja->setCellValue('K1', 'Impreso por: '.(auth()->user()?->name ?? '')."\n".now()->format('d/m/Y H:i:s'));
        $hoja->getStyle('K1')->getFont()->setSize(9);
        $hoja->getStyle('K1')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_RIGHT)
            ->setVertical(Alignment::VERTICAL_TOP)
            ->setWrapText(true);

        $this->insertarLogo($hoja);

        $hoja->mergeCells('A6:J6');
        $hoja->setCellValue('A6', "Empleado: {$nombreEmpleado}, PIN Reloj: {$pin}, desde el {$desdeFmt} hasta el {$hastaFmt}");
        $hoja->getStyle('A6')->getFont()->setBold(true);

        $fila = 7;

        if (filled($cargo)) {
            $hoja->mergeCells("A{$fila}:J{$fila}");
            $hoja->setCellValue("A{$fila}", "Cargo: {$cargo}".(filled($direccionAdmin) ? ", Dirección: {$direccionAdmin}" : ''));
            $fila++;
        }

        return $fila + 1;
    }

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

    /**
     * Cabecera y cuerpo de la tabla. Devuelve la fila de los totales.
     *
     * @param  Collection<int, array<string, mixed>>  $dias
     */
    private function escribirTabla(Worksheet $hoja, Collection $dias, int $fila): int
    {
        $filaCabecera = $fila;

        foreach (self::CABECERAS as $indice => $titulo) {
            $hoja->setCellValue([$indice + 1, $fila], $titulo);
        }

        $hoja->getStyle("A{$fila}:".self::ULTIMA_COLUMNA.$fila)->applyFromArray([
            'font' => ['bold' => true],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D9D9D9']],
        ]);

        $fila++;
        $primeraFilaDatos = $fila;

        // Solo los días con horario asignado, igual que la pantalla y el
        // imprimible: los «no laborable» no se controlan.
        foreach ($dias->reject(fn (array $dia): bool => $dia['estado'] === ProcesadorAsistencia::NO_LABORABLE) as $dia) {
            $fila = $this->escribirDia($hoja, $dia, $fila);
        }

        // Sin días en el rango: la tabla igual queda cerrada con una fila.
        if ($fila === $primeraFilaDatos) {
            $hoja->mergeCells("A{$fila}:".self::ULTIMA_COLUMNA.$fila);
            $hoja->setCellValue("A{$fila}", 'No se encontraron días en el rango.');
            $hoja->getStyle("A{$fila}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $fila++;
        }

        // Bordes de toda la tabla, encabezado incluido.
        $hoja->getStyle("A{$filaCabecera}:".self::ULTIMA_COLUMNA.($fila - 1))
            ->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)
            ->getColor()->setRGB('808080');

        // El encabezado se repite en cada hoja impresa y queda fijo al scrollear.
        $hoja->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($filaCabecera, $filaCabecera);
        $hoja->freezePane("A{$primeraFilaDatos}");

        return $fila;
    }

    /**
     * Escribe un día: una fila por horario, o una sola fila cuando el día se
     * resolvió sin mirar horarios (no laborable o día excepcional).
     *
     * @param  array<string, mixed>  $dia
     */
    private function escribirDia(Worksheet $hoja, array $dia, int $fila): int
    {
        $fecha = $dia['fecha']->format('j/n/Y');
        $nombreDia = Horario::DIAS[$dia['fecha']->dayOfWeek + 1] ?? '—';

        if ($dia['bloques'] === []) {
            $this->escribirFilaTexto($hoja, $fila, [
                $fecha,
                $nombreDia,
                ProcesadorAsistencia::ETIQUETAS[$dia['estado']] ?? $dia['estado'],
                '', '', '', '', '', '', '', '', '',
                (string) ($dia['motivo'] ?? ''),
            ]);

            // El estado ocupa el lugar del horario y sus columnas de horas, igual
            // que el colspan del imprimible.
            $hoja->mergeCells("C{$fila}:L{$fila}");
            $hoja->getStyle("A{$fila}:B{$fila}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            return $fila + 1;
        }

        $primera = $fila;

        foreach ($dia['bloques'] as $indice => $bloque) {
            $licencia = $bloque['licencia'];

            $this->escribirFilaTexto($hoja, $fila, [
                $indice === 0 ? $fecha : '',
                $indice === 0 ? $nombreDia : '',
                trim((string) $bloque['horario']->nombreHorario),
                ! $bloque['entradaExigida'] ? 'licencia' : ($bloque['entrada'] === null ? '' : ProcesadorAsistencia::hora($bloque['entrada'])),
                ! $bloque['salidaExigida'] ? 'licencia' : ($bloque['salida'] === null ? '' : ProcesadorAsistencia::hora($bloque['salida'])),
                $bloque['atraso'] > 0 ? ProcesadorAsistencia::desvio($bloque['atraso']) : '',
                $bloque['estado'] === ProcesadorAsistencia::ABANDONO ? 'ABANDONO' : '',
                ProcesadorAsistencia::FALTAS[$bloque['estado']] ?? '',
                $licencia?->lEntra?->format('H:i') ?? '',
                $licencia?->lSale?->format('H:i') ?? '',
                $licencia === null ? '' : ($licencia->tCompleto ? 'Sí' : 'No'),
                $licencia === null ? '' : ($licencia->goceHaberes ? 'Sí' : 'No'),
                (string) ($licencia?->motivo ?? ''),
            ]);

            // Abandono y Falta van en negrita, como en el imprimible.
            $hoja->getStyle("G{$fila}:H{$fila}")->getFont()->setBold(true);
            $hoja->getStyle("A{$fila}:B{$fila}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $hoja->getStyle("D{$fila}:L{$fila}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            $fila++;
        }

        // Fecha y día combinados en vertical cuando el día tiene varios horarios.
        if ($fila - $primera > 1) {
            $hoja->mergeCells("A{$primera}:A".($fila - 1));
            $hoja->mergeCells("B{$primera}:B".($fila - 1));
            $hoja->getStyle("A{$primera}:B".($fila - 1))->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        }

        return $fila;
    }

    /**
     * Escribe una fila entera como texto explícito, para que Excel no
     * reinterprete horas ni fechas.
     *
     * @param  list<string>  $valores
     */
    private function escribirFilaTexto(Worksheet $hoja, int $fila, array $valores): void
    {
        foreach ($valores as $indice => $valor) {
            $hoja->setCellValueExplicit([$indice + 1, $fila], $valor, DataType::TYPE_STRING);
        }
    }

    /**
     * Fila de totales del rango, al pie de la tabla.
     *
     * @param  array<string, mixed>  $totales
     */
    private function escribirTotales(Worksheet $hoja, array $totales, int $fila): int
    {
        $hoja->mergeCells("A{$fila}:E{$fila}");
        $hoja->setCellValue("A{$fila}", 'Totales del rango:');
        $hoja->getStyle("A{$fila}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $hoja->setCellValueExplicit("F{$fila}", ProcesadorAsistencia::desvio($totales['atraso']), DataType::TYPE_STRING);
        $hoja->getStyle("F{$fila}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $hoja->getStyle("A{$fila}:".self::ULTIMA_COLUMNA.$fila)->applyFromArray([
            'font' => ['bold' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '808080']]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D9D9D9']],
        ]);

        return $fila + 1;
    }

    /**
     * Las dos líneas de firma del pie.
     */
    private function escribirFirmas(Worksheet $hoja, int $fila): void
    {
        $fila += 3;

        foreach ([['______________________', '______________________'], ['Firma Responsable', 'Firma RR. HH.']] as $indice => $par) {
            $hoja->mergeCells("A{$fila}:F{$fila}");
            $hoja->mergeCells("H{$fila}:".self::ULTIMA_COLUMNA.$fila);
            $hoja->setCellValue("A{$fila}", $par[0]);
            $hoja->setCellValue("H{$fila}", $par[1]);
            $hoja->getStyle("A{$fila}:".self::ULTIMA_COLUMNA.$fila)->applyFromArray([
                'font' => ['bold' => $indice === 1],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);
            $fila++;
        }
    }

    /**
     * Anchos de columna, alto del encabezado y configuración de impresión.
     */
    private function aplicarFormatoHoja(Worksheet $hoja): void
    {
        foreach (self::ANCHOS as $columna => $ancho) {
            $hoja->getColumnDimension($columna)->setWidth($ancho);
        }

        // Alto para que el logo entre en las primeras filas.
        foreach ([1, 2, 3, 4] as $numero) {
            $hoja->getRowDimension($numero)->setRowHeight(19);
        }

        $hoja->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_LETTER)
            ->setFitToWidth(1)
            ->setFitToHeight(0);

        $hoja->getPageMargins()->setTop(0.4)->setBottom(0.4)->setLeft(0.4)->setRight(0.4);
        $hoja->setSelectedCell('A1');
    }
}
