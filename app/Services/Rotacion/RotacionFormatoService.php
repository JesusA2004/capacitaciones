<?php

namespace App\Services\Rotacion;

use App\Models\RotacionPeriodo;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Permisos\AlcanceModuloService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * «Formato actualizado» de rotación de personal: la misma estructura que
 * la hoja «SUCURSALES 2026» del Excel real (bloque por sucursal con
 * ALTAS / BAJAS / PLANTILLA / plantilla inicial en A / ROTACIÓN, meses en
 * B..M, TOTALES en N), con tres cambios:
 *
 *  - agrega las filas opcionales BAJAS VOLUNTARIAS y BAJAS INVOLUNTARIAS;
 *  - la ROTACIÓN usa SI() para no mostrar #DIV/0! en meses sin captura y
 *    PROMEDIO MENSUAL / ROTACIÓN ANUAL son fórmulas (en el original eran
 *    números escritos a mano);
 *  - sale con las sucursales del catálogo (nombres exactos) y, si ya hay
 *    meses cargados en PEOPLE, prellenado con ellos: RH solo agrega el mes
 *    nuevo y vuelve a subir el archivo.
 *
 * El resumen al pie del original no se incluye: PEOPLE lo calcula.
 */
class RotacionFormatoService
{
    private const MESES_ENCABEZADO = ['ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO', 'JULIO', 'AGOSTO', 'SEPT', 'OCT', 'NOV', 'DIC'];

    private const FONDO_ENCABEZADO = 'FFF3EBDD';

    private const FONDO_ETIQUETA = 'FFE6EFE9';

    public function __construct(
        private readonly AlcanceModuloService $alcance,
        private readonly RotacionConsultaService $consulta,
    ) {}

    /**
     * Genera el .xlsx en un archivo temporal y devuelve su ruta.
     */
    public function generar(User $usuario, int $anio): string
    {
        $sucursales = $this->alcance->limitarPorSucursal(Sucursal::query()->where('activo', true), $usuario, 'rotacion', 'id')
            ->orderBy('nombre')->get(['id', 'nombre']);
        $periodos = $this->consulta->periodosDelAnio($usuario, $anio)
            ->keyBy(fn (RotacionPeriodo $p): string => sprintf('%d-%d-%d', $p->sucursal_id, $p->anio, $p->mes));

        $libro = new Spreadsheet;
        $libro->getProperties()->setTitle(sprintf('Rotación de personal %d', $anio))->setCreator('MR. LANA PEOPLE');

        $hoja = $libro->getActiveSheet();
        $hoja->setTitle(sprintf('SUCURSALES %d', $anio));
        $this->llenarHoja($hoja, $anio, $sucursales, $periodos);

        $this->instrucciones($libro->createSheet(), $anio);
        $libro->setActiveSheetIndex(0);

        $ruta = (string) tempnam(sys_get_temp_dir(), 'rotacion');
        (new Xlsx($libro))->save($ruta);
        $libro->disconnectWorksheets();

        return $ruta;
    }

    public function nombreArchivo(int $anio): string
    {
        return sprintf('INDICE DE ROTACION DE PERSONAL %d - formato actualizado.xlsx', $anio);
    }

    /**
     * @param  Collection<int, Sucursal>  $sucursales
     * @param  Collection<string, RotacionPeriodo>  $periodos
     */
    private function llenarHoja(Worksheet $hoja, int $anio, Collection $sucursales, Collection $periodos): void
    {
        $hoja->setCellValue('C2', sprintf('ROTACIÓN POR SUCURSALES %d', $anio));
        $hoja->mergeCells('C2:M3');
        $hoja->getStyle('C2')->getFont()->setBold(true)->setSize(16);
        $hoja->getStyle('C2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $hoja->setCellValue('C5', 'Captura ALTAS y BAJAS de cada mes; la PLANTILLA y la ROTACIÓN se calculan solas. Ver la hoja INSTRUCCIONES.');
        $hoja->getStyle('C5')->getFont()->setItalic(true)->setSize(9);

        $hoja->getColumnDimension('A')->setWidth(24);
        foreach (range(2, 14) as $columna) {
            $hoja->getColumnDimension(Coordinate::stringFromColumnIndex($columna))->setWidth(11);
        }
        $hoja->getColumnDimension('O')->setWidth(36);

        $fila = 9;
        foreach ($sucursales as $sucursal) {
            $this->bloque($hoja, $fila, $anio, $sucursal, $periodos);
            $fila += 11;
        }
    }

    /**
     * @param  Collection<string, RotacionPeriodo>  $periodos
     */
    private function bloque(Worksheet $hoja, int $r, int $anio, Sucursal $sucursal, Collection $periodos): void
    {
        $fAltas = $r + 2;
        $fBajas = $r + 3;
        $fVol = $r + 4;
        $fInv = $r + 5;
        $fPlantilla = $r + 6;
        $fInicial = $r + 7;
        $fRotacion = $r + 8;
        $fAnual = $r + 9;

        $hoja->setCellValue('F'.$r, Str::upper($sucursal->nombre));
        $hoja->mergeCells(sprintf('F%d:J%d', $r, $r));
        $hoja->getStyle('F'.$r)->getFont()->setBold(true)->setSize(12);
        $hoja->getStyle('F'.$r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        foreach (self::MESES_ENCABEZADO as $i => $mes) {
            $hoja->setCellValue($this->c($i + 2, $r + 1), $mes);
        }
        $hoja->setCellValue('N'.($r + 1), 'TOTALES');
        $rangoEncabezado = sprintf('B%d:N%d', $r + 1, $r + 1);
        $hoja->getStyle($rangoEncabezado)->getFont()->setBold(true);
        $hoja->getStyle($rangoEncabezado)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::FONDO_ENCABEZADO);
        $hoja->getStyle($rangoEncabezado)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        foreach ([$fAltas => 'ALTAS', $fBajas => 'BAJAS', $fVol => 'BAJAS VOLUNTARIAS', $fInv => 'BAJAS INVOLUNTARIAS', $fPlantilla => 'PLANTILLA', $fRotacion => 'ROTACIÓN'] as $f => $etiqueta) {
            $hoja->setCellValue('A'.$f, $etiqueta);
            $hoja->getStyle('A'.$f)->getFont()->setBold(true);
            $hoja->getStyle('A'.$f)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::FONDO_ETIQUETA);
        }

        // Plantilla inicial: la de enero ya cargada o el cierre de diciembre anterior.
        $enero = $periodos->get(sprintf('%d-%d-1', $sucursal->id, $anio));
        $diciembre = $periodos->get(sprintf('%d-%d-12', $sucursal->id, $anio - 1));
        $inicial = $enero->plantilla_inicial ?? $diciembre?->plantilla_final;
        if ($inicial !== null) {
            $hoja->setCellValue('A'.$fInicial, $inicial);
        }
        $hoja->setCellValue('O'.$fInicial, '← PLANTILLA INICIAL (cierre del año anterior)');
        $hoja->getStyle('O'.$fInicial)->getFont()->setItalic(true)->setSize(9);

        for ($mes = 1; $mes <= 12; $mes++) {
            $col = $mes + 1;
            $periodo = $periodos->get(sprintf('%d-%d-%d', $sucursal->id, $anio, $mes));

            if ($periodo !== null) {
                $hoja->setCellValue($this->c($col, $fAltas), $periodo->altas);
                $hoja->setCellValue($this->c($col, $fBajas), $periodo->bajas);
                if ($periodo->bajas_voluntarias !== null) {
                    $hoja->setCellValue($this->c($col, $fVol), $periodo->bajas_voluntarias);
                }
                if ($periodo->bajas_involuntarias !== null) {
                    $hoja->setCellValue($this->c($col, $fInv), $periodo->bajas_involuntarias);
                }
            }

            $anterior = $mes === 1 ? 'A'.$fInicial : $this->c($col - 1, $fPlantilla);
            $hoja->setCellValue($this->c($col, $fPlantilla), sprintf('=%s+%s-%s', $anterior, $this->c($col, $fAltas), $this->c($col, $fBajas)));
            $hoja->setCellValue($this->c($col, $fRotacion), sprintf('=IF(OR(%1$s="",%2$s=0),"",%1$s*100/%2$s)', $this->c($col, $fBajas), $this->c($col, $fPlantilla)));
        }

        foreach ([$fAltas, $fBajas, $fVol, $fInv] as $f) {
            $hoja->setCellValue('N'.$f, sprintf('=SUM(B%1$d:M%1$d)', $f));
        }
        $hoja->setCellValue('N'.$fPlantilla, sprintf('=A%d+N%d-N%d', $fInicial, $fAltas, $fBajas));
        $hoja->setCellValue('N'.$fRotacion, sprintf('=IFERROR(AVERAGE(B%1$d:M%1$d),"")', $fRotacion));
        $hoja->setCellValue('O'.$fRotacion, 'PROMEDIO MENSUAL');
        $hoja->setCellValue('N'.$fAnual, sprintf('=IF(N%1$d=0,"",N%2$d*100/N%1$d)', $fPlantilla, $fBajas));
        $hoja->setCellValue('O'.$fAnual, 'ROTACIÓN ANUAL');

        $hoja->getStyle(sprintf('B%d:N%d', $fRotacion, $fAnual))->getNumberFormat()->setFormatCode('0.00');
        $hoja->getStyle(sprintf('B%d:N%d', $fPlantilla, $fPlantilla))->getFont()->setBold(true);
    }

    private function instrucciones(Worksheet $hoja, int $anio): void
    {
        $hoja->setTitle('INSTRUCCIONES');
        $hoja->getColumnDimension('A')->setWidth(110);

        $lineas = [
            sprintf('FORMATO DE ROTACIÓN DE PERSONAL %d — MR. LANA PEOPLE', $anio),
            '',
            '1. Cada sucursal es un bloque: su nombre arriba (no lo cambies: debe coincidir con el catálogo de sucursales de PEOPLE).',
            '2. Captura por mes ALTAS y BAJAS. BAJAS VOLUNTARIAS / INVOLUNTARIAS son opcionales; si las llenas deben sumar las BAJAS.',
            '3. La PLANTILLA INICIAL va en la columna A, debajo de PLANTILLA (cierre de diciembre del año anterior).',
            '4. PLANTILLA = plantilla anterior + altas − bajas. ROTACIÓN = bajas × 100 / plantilla del mes. Se calculan solas.',
            '5. Deja vacíos los meses que todavía no cierran: no se importan.',
            '6. Puedes agregar una hoja por año (p. ej. «SUCURSALES 2025»): el año se toma del nombre de la hoja.',
            '7. Al subir el archivo, PEOPLE muestra una vista previa con todo lo que no cuadró antes de confirmar.',
            '   Volver a subir un mes ya cargado lo reemplaza; el cambio queda registrado.',
        ];

        foreach ($lineas as $i => $texto) {
            $hoja->setCellValue('A'.($i + 1), $texto);
        }
        $hoja->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    }

    private function c(int $columna, int $fila): string
    {
        return Coordinate::stringFromColumnIndex($columna).$fila;
    }
}
