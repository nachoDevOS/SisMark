<?php

namespace App\Services;

use App\Models\Actividad;
use App\Traits\InsertaLogoEnHoja;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * La actividad de usuarios filtrada, como .xlsx: encabezado institucional,
 * los filtros en palabras y una fila por evento o pedido.
 */
class ExcelActividad
{
    use InsertaLogoEnHoja;

    private const GRIS = 'D9D9D9';

    private const BORDE = '808080';

    /**
     * @var list<array{0: string, 1: int}>
     */
    private const COLUMNAS = [
        ['Fecha y hora', 18],
        ['Usuario', 26],
        ['Acción', 18],
        ['Qué hizo', 60],
        ['Módulo', 16],
        ['CI', 12],
        ['IP', 16],
        ['Estado', 9],
        ['Duración (ms)', 12],
    ];

    /**
     * Genera el archivo y devuelve su ruta temporal (se borra al enviarlo).
     *
     * @param  Collection<int, Actividad>  $filas
     * @param  list<string>  $filtros
     */
    public function generar(Collection $filas, int $total, array $filtros): string
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle('Actividad');

        $ultima = Coordinate::stringFromColumnIndex(count(self::COLUMNAS));

        foreach ([1 => ['GOBIERNO AUTONOMO DEPARTAMENTAL DEL BENI', 14], 2 => ['ACTIVIDAD DE USUARIOS DEL SISTEMA DE CONTROL DE ASISTENCIA', 12]] as $numero => [$texto, $tamano]) {
            $hoja->mergeCells("C{$numero}:G{$numero}");
            $hoja->setCellValue("C{$numero}", $texto);
            $hoja->getStyle("C{$numero}")->getFont()->setSize($tamano)->setBold(true);
            $hoja->getStyle("C{$numero}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        $hoja->mergeCells("H1:{$ultima}2");
        $hoja->setCellValue('H1', 'Impreso por: '.(auth()->user()?->name ?? '')."\n".now()->format('d/m/Y H:i:s'));
        $hoja->getStyle('H1')->getFont()->setSize(9);
        $hoja->getStyle('H1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT)->setWrapText(true);

        $this->insertarLogo($hoja);

        $fila = 4;
        $lineas = [...$filtros, "Registros: {$total}".($filas->count() < $total ? ' (se exportan los primeros '.$filas->count().'; acotá el filtro para ver el resto)' : '')];

        foreach ($lineas as $linea) {
            $hoja->mergeCells("A{$fila}:{$ultima}{$fila}");
            $hoja->setCellValue("A{$fila}", $linea);
            $fila++;
        }

        $fila++;
        $filaCabecera = $fila;

        foreach (self::COLUMNAS as $indice => [$titulo, $ancho]) {
            $hoja->setCellValue([$indice + 1, $fila], $titulo);
            $hoja->getColumnDimension(Coordinate::stringFromColumnIndex($indice + 1))->setWidth($ancho);
        }

        $hoja->getStyle("A{$fila}:{$ultima}{$fila}")->applyFromArray([
            'font' => ['bold' => true],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::GRIS]],
        ]);

        foreach ($filas as $actividad) {
            $fila++;
            $valores = [
                $actividad->created_at->format('d/m/Y H:i:s'),
                $actividad->quien(),
                $actividad->etiquetaAccion(),
                $actividad->descripcion ?? $actividad->metodo.' '.$actividad->url,
                $actividad->modulo,
                (string) $actividad->ci,
                (string) $actividad->ip,
                $actividad->estado ? (string) $actividad->estado : '',
                $actividad->estado ? (string) $actividad->duracion_ms : '',
            ];

            foreach ($valores as $indice => $valor) {
                $hoja->setCellValueExplicit([$indice + 1, $fila], $valor, DataType::TYPE_STRING);
            }
        }

        $hoja->getStyle("A{$filaCabecera}:{$ultima}{$fila}")->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::BORDE);
        $hoja->getStyle("A{$filaCabecera}:{$ultima}{$fila}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        $hoja->freezePane('A'.($filaCabecera + 1));
        $hoja->setAutoFilter("A{$filaCabecera}:{$ultima}{$fila}");

        $hoja->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_LETTER)
            ->setFitToWidth(1)
            ->setFitToHeight(0)
            ->setRowsToRepeatAtTopByStartAndEnd($filaCabecera, $filaCabecera);

        $ruta = (string) tempnam(sys_get_temp_dir(), 'sismark-xlsx-');
        (new Xlsx($libro))->save($ruta);
        $libro->disconnectWorksheets();

        return $ruta;
    }

    public function nombreArchivo(): string
    {
        return 'actividad-de-usuarios-'.now()->format('Y-m-d-His').'.xlsx';
    }
}
