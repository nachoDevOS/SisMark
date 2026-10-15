<?php

namespace App\Services;

use App\Services\ProcesadorAsistencia as P;
use App\Services\ReporteDireccion as R;
use App\Traits\InsertaLogoEnHoja;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Arma el reporte de marcaciones por dirección como .xlsx nativo, con la misma
 * maqueta que su imprimible (`reportes/marcaciones/procesado/direccion-print`):
 * logo, encabezado institucional, una fila por funcionario, el total y firmas.
 *
 * A diferencia del Excel individual, las cantidades van como números: son
 * conteos y minutos que quien lo baja quiere poder sumar u ordenar. El CI y los
 * contratos sí van como texto, para que Excel no los reinterprete.
 */
class ExcelReporteDireccion
{
    use InsertaLogoEnHoja;

    private const GRIS = 'D9D9D9';

    private const BORDE = '808080';

    /**
     * Genera el archivo y devuelve la ruta temporal donde quedó escrito. El
     * llamador es responsable de borrarlo (con `deleteFileAfterSend()`).
     *
     * @param  Collection<int, array<string, mixed>>  $filas  Las de {@see R::filas()}.
     * @param  array<string, mixed>  $totales  Los de {@see R::totales()}.
     */
    public function generar(Collection $filas, array $totales, string $nombreDireccion, string $nombreUnidad, string $desde, string $hasta, bool $cruzaMeses, string $alcance): string
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle('Por dirección');

        $columnas = $this->columnas($cruzaMeses);
        $ultima = Coordinate::stringFromColumnIndex(count($columnas));

        $fila = $this->escribirEncabezado($hoja, $ultima, $alcance, $nombreDireccion, $nombreUnidad, $desde, $hasta);
        $fila = $this->escribirTabla($hoja, $columnas, $ultima, $filas, $cruzaMeses, $fila);
        $fila = $this->escribirTotal($hoja, $ultima, $totales, $cruzaMeses, $fila);
        $this->escribirFirmas($hoja, $ultima, $fila);

        $this->aplicarFormatoHoja($hoja, $columnas);

        $ruta = (string) tempnam(sys_get_temp_dir(), 'sismark-xlsx-');

        (new Xlsx($libro))->save($ruta);

        $libro->disconnectWorksheets();

        return $ruta;
    }

    /**
     * Nombre del archivo que ve el usuario: la sigla de la dirección («SDAF —
     * Secretaría…» → `sdaf`) y la fecha.
     */
    public function nombreArchivo(string $nombreDireccion): string
    {
        $sigla = Str::slug(Str::before($nombreDireccion, '—')) ?: 'direccion';

        return "marcaciones-direccion-{$sigla}-".now()->format('Y-m-d').'.xlsx';
    }

    /**
     * Título y ancho de cada columna, en orden. «Mes» solo cuando el rango
     * cruza meses, como en la pantalla.
     *
     * @return list<array{0: string, 1: int}>
     */
    private function columnas(bool $cruzaMeses): array
    {
        return [
            ['N°', 5],
            ['Funcionario', 34],
            ['CI', 12],
            ['Contrato(s) en el rango', 30],
            ...($cruzaMeses ? [['Mes', 15]] : []),
            ['Minutos acumulados', 12],
            ['Atrasos', 9],
            ['Abandonos', 11],
            ['Faltas', 8],
            ['Licencia', 9],
        ];
    }

    /**
     * Logo, títulos institucionales y alcance del reporte. Devuelve la fila
     * donde arranca la tabla.
     */
    private function escribirEncabezado(Worksheet $hoja, string $ultima, string $alcance, string $nombreDireccion, string $nombreUnidad, string $desde, string $hasta): int
    {
        $titulos = [
            1 => ['GOBIERNO AUTONOMO DEPARTAMENTAL DEL BENI', 14],
            2 => ['REPORTE DE MARCACIONES', 12],
            3 => ['TRINIDAD', 12],
            4 => ['Resumen por dirección', 10],
        ];

        foreach ($titulos as $numero => [$texto, $tamano]) {
            $hoja->mergeCells("B{$numero}:D{$numero}");
            $hoja->setCellValue("B{$numero}", $texto);
            $hoja->getStyle("B{$numero}")->getFont()->setSize($tamano)->setBold($tamano > 10);
            $hoja->getStyle("B{$numero}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        // «Impreso por» a la derecha, donde el imprimible pone el QR.
        $impreso = Coordinate::stringFromColumnIndex(Coordinate::columnIndexFromString($ultima) - 2);
        $hoja->mergeCells("{$impreso}1:{$ultima}2");
        $hoja->setCellValue("{$impreso}1", 'Impreso por: '.(auth()->user()?->name ?? '')."\n".now()->format('d/m/Y H:i:s'));
        $hoja->getStyle("{$impreso}1")->getFont()->setSize(9);
        $hoja->getStyle("{$impreso}1")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_RIGHT)
            ->setVertical(Alignment::VERTICAL_TOP)
            ->setWrapText(true);

        $this->insertarLogo($hoja);

        $desdeFmt = Carbon::parse($desde)->format('j/n/Y');
        $hastaFmt = Carbon::parse($hasta)->format('j/n/Y');

        $lineas = [
            6 => "Dirección: {$nombreDireccion}, desde el {$desdeFmt} hasta el {$hastaFmt}",
            7 => "Unidad: {$nombreUnidad}",
            8 => Str::ucfirst($alcance),
        ];

        foreach ($lineas as $numero => $texto) {
            $hoja->mergeCells("A{$numero}:{$ultima}{$numero}");
            $hoja->setCellValue("A{$numero}", $texto);
        }

        $hoja->getStyle('A6')->getFont()->setBold(true);

        return 10;
    }

    /**
     * Cabecera y una fila por funcionario (más una por mes si el rango cruza
     * meses). Devuelve la fila del total.
     *
     * @param  list<array{0: string, 1: int}>  $columnas
     * @param  Collection<int, array<string, mixed>>  $filas
     */
    private function escribirTabla(Worksheet $hoja, array $columnas, string $ultima, Collection $filas, bool $cruzaMeses, int $fila): int
    {
        $filaCabecera = $fila;

        foreach ($columnas as $indice => [$titulo]) {
            $hoja->setCellValue([$indice + 1, $fila], $titulo);
        }

        $hoja->getStyle("A{$fila}:{$ultima}{$fila}")->applyFromArray([
            'font' => ['bold' => true],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::GRIS]],
        ]);

        $fila++;
        $primeraFilaDatos = $fila;

        foreach ($filas->values() as $numero => $datos) {
            $persona = $datos['persona'];

            $hoja->setCellValue("A{$fila}", $numero + 1);
            $hoja->setCellValueExplicit("B{$fila}", (string) ($persona['nombreFormal'] ?: $persona['nombre']), DataType::TYPE_STRING);
            $hoja->setCellValueExplicit("C{$fila}", (string) $persona['ci'], DataType::TYPE_STRING);
            $hoja->setCellValueExplicit("D{$fila}", $this->contratos($datos['tramos']), DataType::TYPE_STRING);

            // Cruzando meses, el subtotal de la persona va con raya en los
            // minutos: se acumulan por mes y no se suman entre meses.
            $this->escribirConteos($hoja, $fila, $cruzaMeses ? 'Total' : null, $datos['totales'], ! $cruzaMeses);
            $fila++;

            if ($cruzaMeses) {
                foreach ($datos['meses'] as $mes) {
                    $this->escribirConteos($hoja, $fila, $mes['etiqueta'], $mes['totales'], true);
                    $hoja->getStyle("A{$fila}:{$ultima}{$fila}")->getFill()
                        ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F3F4F6');
                    $fila++;
                }
            }
        }

        if ($fila === $primeraFilaDatos) {
            $hoja->mergeCells("A{$fila}:{$ultima}{$fila}");
            $hoja->setCellValue("A{$fila}", 'Nadie de esta dirección tuvo contrato dentro del rango.');
            $hoja->getStyle("A{$fila}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $fila++;
        }

        $hoja->getStyle("A{$primeraFilaDatos}:{$ultima}".($fila - 1))->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $hoja->getStyle("D{$primeraFilaDatos}:D".($fila - 1))->getAlignment()->setWrapText(true);
        $hoja->getStyle("A{$primeraFilaDatos}:A".($fila - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $hoja->getStyle("C{$primeraFilaDatos}:C".($fila - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $hoja->getStyle("E{$primeraFilaDatos}:{$ultima}".($fila - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $hoja->getStyle("A{$filaCabecera}:{$ultima}".($fila - 1))
            ->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)
            ->getColor()->setRGB(self::BORDE);

        // El encabezado se repite en cada hoja impresa y queda fijo al scrollear.
        $hoja->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($filaCabecera, $filaCabecera);
        $hoja->freezePane("A{$primeraFilaDatos}");
        $hoja->setAutoFilter("A{$filaCabecera}:{$ultima}".($fila - 1));

        return $fila;
    }

    /**
     * Mes (si va), minutos, atrasos, abandonos, faltas y licencia de una fila.
     * Los ceros quedan en blanco, como en la pantalla.
     *
     * @param  array<string, mixed>  $totales
     */
    private function escribirConteos(Worksheet $hoja, int $fila, ?string $mes, array $totales, bool $conMinutos): void
    {
        $porEstado = $totales['porEstado'];
        $columna = 5;

        if ($mes !== null) {
            $hoja->setCellValueExplicit([$columna++, $fila], $mes, DataType::TYPE_STRING);
        }

        $valores = [
            $conMinutos ? intdiv((int) $totales['atraso'], 60) : '—',
            R::contar($porEstado, [P::ATRASO]),
            R::contar($porEstado, [P::ABANDONO]),
            R::contar($porEstado, R::COMO_FALTA),
            R::contar($porEstado, [P::LICENCIA]),
        ];

        foreach ($valores as $valor) {
            if ($valor !== 0) {
                $hoja->setCellValue([$columna, $fila], $valor);
            }

            $columna++;
        }
    }

    /**
     * Los tramos de contrato del rango, uno por línea, con su dirección.
     *
     * @param  list<array<string, mixed>>  $tramos
     */
    private function contratos(array $tramos): string
    {
        return collect($tramos)
            ->map(fn (array $tramo): string => $tramo['desde']->format('d/m/Y').' – '
                .($tramo['hasta']?->format('d/m/Y') ?? 'vigente')
                .($tramo['direccion'] ? " ({$tramo['direccion']})" : ''))
            ->implode("\n");
    }

    /**
     * Fila «Total:» al pie, con los mismos conteos que la pantalla.
     *
     * @param  array<string, mixed>  $totales
     */
    private function escribirTotal(Worksheet $hoja, string $ultima, array $totales, bool $cruzaMeses, int $fila): int
    {
        $antesDeConteos = $cruzaMeses ? 'E' : 'D';

        $hoja->mergeCells("A{$fila}:{$antesDeConteos}{$fila}");
        $hoja->setCellValue("A{$fila}", 'Total:');
        $hoja->getStyle("A{$fila}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $columna = $cruzaMeses ? 6 : 5;
        $porEstado = $totales['porEstado'];

        foreach ([
            $cruzaMeses ? '—' : intdiv((int) $totales['atraso'], 60),
            R::contar($porEstado, [P::ATRASO]),
            R::contar($porEstado, [P::ABANDONO]),
            R::contar($porEstado, R::COMO_FALTA),
            R::contar($porEstado, [P::LICENCIA]),
        ] as $valor) {
            $hoja->setCellValue([$columna++, $fila], $valor);
        }

        $hoja->getStyle("A{$fila}:{$ultima}{$fila}")->applyFromArray([
            'font' => ['bold' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::BORDE]]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::GRIS]],
        ]);
        $hoja->getStyle(Coordinate::stringFromColumnIndex($cruzaMeses ? 6 : 5).$fila.":{$ultima}{$fila}")
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        return $fila + 1;
    }

    /**
     * Las dos líneas de firma del pie.
     */
    private function escribirFirmas(Worksheet $hoja, string $ultima, int $fila): void
    {
        $fila += 3;

        foreach ([['______________________', '______________________'], ['Firma Responsable', 'Firma RR. HH.']] as $indice => $par) {
            $hoja->mergeCells("A{$fila}:C{$fila}");
            $hoja->mergeCells("E{$fila}:{$ultima}{$fila}");
            $hoja->setCellValue("A{$fila}", $par[0]);
            $hoja->setCellValue("E{$fila}", $par[1]);
            $hoja->getStyle("A{$fila}:{$ultima}{$fila}")->applyFromArray([
                'font' => ['bold' => $indice === 1],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);
            $fila++;
        }
    }

    /**
     * Anchos de columna, alto del encabezado y configuración de impresión.
     *
     * @param  list<array{0: string, 1: int}>  $columnas
     */
    private function aplicarFormatoHoja(Worksheet $hoja, array $columnas): void
    {
        foreach ($columnas as $indice => [, $ancho]) {
            $hoja->getColumnDimension(Coordinate::stringFromColumnIndex($indice + 1))->setWidth($ancho);
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
