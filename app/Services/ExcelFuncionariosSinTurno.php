<?php

namespace App\Services;

use App\Traits\InsertaLogoEnHoja;
use Illuminate\Support\Carbon;
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
 * Arma el reporte de funcionarios sin turno como .xlsx, con la misma maqueta
 * que su imprimible (`reportes/funcionarios/sin-turno/print`): logo,
 * encabezado institucional, filtros, una fila por funcionario y firmas.
 *
 * El CI y las fechas van como texto, para que Excel no los reinterprete.
 */
class ExcelFuncionariosSinTurno
{
    use InsertaLogoEnHoja;

    private const GRIS = 'D9D9D9';

    private const BORDE = '808080';

    /**
     * Título y ancho de cada columna, en orden.
     *
     * @var list<array{0: string, 1: int}>
     */
    private const COLUMNAS = [
        ['N°', 5],
        ['Funcionario', 34],
        ['CI', 12],
        ['Tipo de contrato', 14],
        ['Cargo', 30],
        ['Dirección', 34],
        ['Unidad', 34],
        ['Inicio contrato', 12],
        ['Fin contrato', 14],
    ];

    /**
     * Genera el archivo y devuelve la ruta temporal donde quedó escrito. El
     * llamador es responsable de borrarlo (con `deleteFileAfterSend()`).
     *
     * @param  list<array<string, mixed>>  $filas  Las de {@see DirectorioMamore::todosSinTurno()}.
     */
    public function generar(array $filas, int $total, string $nombreDireccion, string $nombreUnidad, string $nombreTipo): string
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle('Sin turno');

        $ultima = Coordinate::stringFromColumnIndex(count(self::COLUMNAS));

        $fila = $this->escribirEncabezado($hoja, $ultima, $filas, $total, $nombreDireccion, $nombreUnidad, $nombreTipo);
        $fila = $this->escribirTabla($hoja, $ultima, $filas, $fila);
        $this->escribirFirmas($hoja, $ultima, $fila);

        $this->aplicarFormatoHoja($hoja);

        $ruta = (string) tempnam(sys_get_temp_dir(), 'sismark-xlsx-');

        (new Xlsx($libro))->save($ruta);

        $libro->disconnectWorksheets();

        return $ruta;
    }

    /**
     * Nombre del archivo: la sigla de la dirección si se eligió una («RRHH —
     * Recursos…» → `rrhh`), o `todas`, y la fecha.
     */
    public function nombreArchivo(?string $nombreDireccion): string
    {
        $sigla = $nombreDireccion === null ? 'todas' : (Str::slug(Str::before($nombreDireccion, '—')) ?: 'direccion');

        return "funcionarios-sin-turno-{$sigla}-".now()->format('Y-m-d').'.xlsx';
    }

    /**
     * Logo, títulos institucionales y filtros. Devuelve la fila donde arranca
     * la tabla.
     *
     * @param  list<array<string, mixed>>  $filas
     */
    private function escribirEncabezado(Worksheet $hoja, string $ultima, array $filas, int $total, string $nombreDireccion, string $nombreUnidad, string $nombreTipo): int
    {
        $titulos = [
            1 => ['GOBIERNO AUTONOMO DEPARTAMENTAL DEL BENI', 14],
            2 => ['FUNCIONARIOS SIN TURNO NI HORARIO ASIGNADO', 12],
            3 => ['TRINIDAD', 12],
            4 => ['Con contrato firmado, al '.now()->format('d/m/Y'), 10],
        ];

        foreach ($titulos as $numero => [$texto, $tamano]) {
            $hoja->mergeCells("C{$numero}:F{$numero}");
            $hoja->setCellValue("C{$numero}", $texto);
            $hoja->getStyle("C{$numero}")->getFont()->setSize($tamano)->setBold($tamano > 10);
            $hoja->getStyle("C{$numero}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        // «Impreso por» a la derecha, donde el imprimible pone el QR.
        $hoja->mergeCells("H1:{$ultima}2");
        $hoja->setCellValue('H1', 'Impreso por: '.(auth()->user()?->name ?? '')."\n".now()->format('d/m/Y H:i:s'));
        $hoja->getStyle('H1')->getFont()->setSize(9);
        $hoja->getStyle('H1')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_RIGHT)
            ->setVertical(Alignment::VERTICAL_TOP)
            ->setWrapText(true);

        $this->insertarLogo($hoja);

        // La hoja tiene tope: si quedó gente afuera tiene que decirlo ella misma.
        $funcionarios = "Funcionarios: {$total}".(count($filas) < $total
            ? ' (se exportan los primeros '.count($filas).'; acotá por dirección para ver el resto)'
            : '');

        $lineas = [
            6 => "Dirección: {$nombreDireccion}",
            7 => "Unidad: {$nombreUnidad}",
            8 => "Tipo de contrato: {$nombreTipo}",
            9 => $funcionarios,
        ];

        foreach ($lineas as $numero => $texto) {
            $hoja->mergeCells("A{$numero}:{$ultima}{$numero}");
            $hoja->setCellValue("A{$numero}", $texto);
        }

        $hoja->getStyle('A6')->getFont()->setBold(true);

        return 11;
    }

    /**
     * Cabecera y una fila por funcionario. Devuelve la fila siguiente a la tabla.
     *
     * @param  list<array<string, mixed>>  $filas
     */
    private function escribirTabla(Worksheet $hoja, string $ultima, array $filas, int $fila): int
    {
        $filaCabecera = $fila;

        foreach (self::COLUMNAS as $indice => [$titulo]) {
            $hoja->setCellValue([$indice + 1, $fila], $titulo);
        }

        $hoja->getStyle("A{$fila}:{$ultima}{$fila}")->applyFromArray([
            'font' => ['bold' => true],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::GRIS]],
        ]);

        $fila++;
        $primeraFilaDatos = $fila;

        foreach ($filas as $numero => $persona) {
            $valores = [
                (string) ($persona['nombreFormal'] ?: $persona['nombre']),
                (string) $persona['ci'],
                (string) ($persona['tipoContrato'] ?: '—'),
                (string) ($persona['cargo'] ?: '—'),
                (string) ($persona['direccionNombre'] ?: '—'),
                (string) ($persona['unidadNombre'] ?: '—'),
                $this->fecha($persona['contratoDesde'], '—'),
                $this->fecha($persona['contratoHasta'], 'Sin fecha de fin'),
            ];

            $hoja->setCellValue("A{$fila}", $numero + 1);

            foreach ($valores as $indice => $valor) {
                $hoja->setCellValueExplicit([$indice + 2, $fila], $valor, DataType::TYPE_STRING);
            }

            $fila++;
        }

        if ($fila === $primeraFilaDatos) {
            $hoja->mergeCells("A{$fila}:{$ultima}{$fila}");
            $hoja->setCellValue("A{$fila}", 'Todos los funcionarios con contrato de este filtro tienen turno.');
            $hoja->getStyle("A{$fila}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $fila++;
        }

        $ultimaDatos = $fila - 1;
        $hoja->getStyle("A{$primeraFilaDatos}:{$ultima}{$ultimaDatos}")->getAlignment()
            ->setVertical(Alignment::VERTICAL_CENTER)
            ->setWrapText(true);

        foreach (['A', 'C', 'D', 'H', 'I'] as $columna) {
            $hoja->getStyle("{$columna}{$primeraFilaDatos}:{$columna}{$ultimaDatos}")
                ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        $hoja->getStyle("A{$filaCabecera}:{$ultima}{$ultimaDatos}")
            ->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)
            ->getColor()->setRGB(self::BORDE);

        // El encabezado se repite en cada hoja impresa y queda fijo al scrollear.
        $hoja->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($filaCabecera, $filaCabecera);
        $hoja->freezePane("A{$primeraFilaDatos}");
        $hoja->setAutoFilter("A{$filaCabecera}:{$ultima}{$ultimaDatos}");

        return $fila;
    }

    /**
     * Una fecha «Y-m-d» de Mamoré como «d/m/Y», o el texto dado si no vino.
     */
    private function fecha(?string $valor, string $sinDato): string
    {
        return $valor ? Carbon::parse($valor)->format('d/m/Y') : $sinDato;
    }

    /**
     * Las dos líneas de firma del pie.
     */
    private function escribirFirmas(Worksheet $hoja, string $ultima, int $fila): void
    {
        $fila += 3;

        foreach ([['______________________', '______________________'], ['Firma Responsable', 'Firma RR. HH.']] as $indice => $par) {
            $hoja->mergeCells("A{$fila}:C{$fila}");
            $hoja->mergeCells("F{$fila}:{$ultima}{$fila}");
            $hoja->setCellValue("A{$fila}", $par[0]);
            $hoja->setCellValue("F{$fila}", $par[1]);
            $hoja->getStyle("A{$fila}:{$ultima}{$fila}")->applyFromArray([
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
        foreach (self::COLUMNAS as $indice => [, $ancho]) {
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
