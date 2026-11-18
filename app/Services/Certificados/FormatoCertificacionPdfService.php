<?php

namespace App\Services\Certificados;

use App\Exceptions\ArchivoGeneradoNoDisponibleException;
use App\Models\Colaborador;
use App\Services\DocumentosMaestros\Pdf\FpdiAjustable;
use RuntimeException;

/**
 * Formato oficial «Programa de capacitación para Certificación Mr Lana»
 * (RH-CC-R-001, docs/certificacion/): importa las DOS hojas originales con
 * FPDI tal cual (mismo diseño exacto) y escribe SOLO el encabezado de la
 * hoja 1 — nombre del gestor, sucursal y fecha de ingreso — después de cada
 * etiqueta. La tabla de temas, fechas, firmas, evaluación por módulo y el
 * promedio se llenan a pluma en papel; la hoja 2 queda intacta.
 *
 * Coordenadas en puntos PDF (hoja 612 × 936 pt), medidas con
 * `pdftotext -bbox` sobre el original: fin de cada etiqueta y borde
 * derecho de su celda.
 */
class FormatoCertificacionPdfService
{
    /** Línea base del renglón «Nombre del gestor:» (etiqueta y: 69.7–83.2). */
    private const NOMBRE = ['x' => 158.0, 'base' => 80.6, 'ancho' => 426.0];

    /** «Sucursal:» termina en x 93.9; la celda cierra en ~355 pt. */
    private const SUCURSAL = ['x' => 99.0, 'base' => 102.1, 'ancho' => 250.0];

    /** «Fecha de Ingreso:» termina en x 462.3; la celda cierra en ~589 pt. */
    private const FECHA_INGRESO = ['x' => 467.5, 'base' => 102.1, 'ancho' => 116.0];

    private const TAMANO_LETRA = 10.5;

    public function ruta(): string
    {
        $ruta = (string) config('capacitacion.certificacion.formato.pdf');

        return preg_match('/^([A-Za-z]:[\\\\\/]|\/)/', $ruta) === 1 ? $ruta : base_path($ruta);
    }

    public function disponible(): bool
    {
        return is_file($this->ruta());
    }

    /**
     * Datos del encabezado tal como se imprimen (también los ve la pantalla).
     *
     * @return array{nombre: string, sucursal: string, fecha_ingreso: string}
     */
    public function encabezado(Colaborador $colaborador): array
    {
        $colaborador->loadMissing('sucursalPrincipal:id,nombre');

        return [
            'nombre' => $colaborador->nombreCompleto(),
            'sucursal' => (string) ($colaborador->sucursalPrincipal->nombre ?? ''),
            'fecha_ingreso' => $colaborador->fecha_ingreso?->format('d/m/Y') ?? '',
        ];
    }

    /**
     * Bytes del PDF con el encabezado lleno.
     *
     * @throws ArchivoGeneradoNoDisponibleException
     */
    public function rellenar(Colaborador $colaborador): string
    {
        if (! $this->disponible()) {
            throw new ArchivoGeneradoNoDisponibleException(
                titulo: 'Formato de Certificación Mr. Lana',
                mensaje: 'El formato oficial RH-CC-R-001 no está cargado en el servidor. Avisa a Sistemas.',
            );
        }

        $datos = $this->encabezado($colaborador);
        $pdf = new FpdiAjustable('P', 'pt');
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(0, 0, 0);
        $paginas = $pdf->setSourceFile($this->ruta());

        for ($numero = 1; $numero <= $paginas; $numero++) {
            $plantilla = $pdf->importPage($numero);
            $tamano = $pdf->getTemplateSize($plantilla);

            if (! is_array($tamano)) {
                throw new RuntimeException(sprintf('No se pudo leer el tamaño de la hoja %d del formato de certificación.', $numero));
            }

            $pdf->AddPage($tamano['orientation'], [$tamano['width'], $tamano['height']]);
            $pdf->useTemplate($plantilla, 0, 0, $tamano['width'], $tamano['height']);

            if ($numero === 1) {
                $pdf->SetTextColor(30, 41, 38);
                $this->escribir($pdf, $datos['nombre'], self::NOMBRE);
                $this->escribir($pdf, $datos['sucursal'], self::SUCURSAL);
                $this->escribir($pdf, $datos['fecha_ingreso'], self::FECHA_INGRESO);
            }
        }

        $pdf->SetTitle($this->latin1('Certificación Mr. Lana - '.$datos['nombre']));

        return $pdf->Output('S');
    }

    /**
     * Escribe el dato en su caja; si no cabe se condensa a lo ancho (nunca
     * se encima sobre la celda vecina) y se recorta como último recurso.
     *
     * @param  array{x: float, base: float, ancho: float}  $caja
     */
    private function escribir(FpdiAjustable $pdf, string $texto, array $caja): void
    {
        $texto = trim($texto);

        if ($texto === '') {
            return;
        }

        $convertido = $this->latin1($texto);
        $pdf->SetFont('Helvetica', '', self::TAMANO_LETRA);
        $ancho = $pdf->GetStringWidth($convertido);
        $escala = $ancho > $caja['ancho'] ? max(60.0, 100 * $caja['ancho'] / $ancho) : 100.0;

        $pdf->recortar($caja['x'], $caja['base'] - 11, $caja['ancho'], 15);

        if ($escala < 100.0) {
            $pdf->escalaHorizontal($escala);
        }

        $pdf->Text($caja['x'], $caja['base'], $convertido);

        if ($escala < 100.0) {
            $pdf->escalaHorizontal(100);
        }

        $pdf->finRecorte();
    }

    private function latin1(string $texto): string
    {
        $convertido = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $texto);

        return $convertido === false ? $texto : $convertido;
    }
}
