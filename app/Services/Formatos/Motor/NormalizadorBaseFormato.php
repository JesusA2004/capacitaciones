<?php

namespace App\Services\Formatos\Motor;

use App\Enums\TipoArchivoFormato;
use GdImage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use setasign\Fpdi\Fpdi;
use Throwable;

/**
 * Convierte el archivo fuente de una versión en su PDF BASE: el fondo
 * exacto sobre el que se pintan los datos y que el editor muestra.
 *  - PDF: se usa tal cual (mismo tamaño de página, orientación y páginas).
 *  - Imagen: orientación EXIF corregida y colocada a página completa en un
 *    PDF del mismo aspecto (ancho carta).
 *  - DOCX: convertido a PDF (ConversorDocxPdf).
 */
class NormalizadorBaseFormato
{
    private const MM_CARTA_VERTICAL = 215.9;

    private const MM_CARTA_HORIZONTAL = 279.4;

    public function __construct(private readonly ConversorDocxPdf $docx) {}

    /**
     * @return array{pdf: string, paginas: list<array{numero: int, ancho: float, alto: float}>, fidelidad: string}
     */
    public function normalizar(string $rutaLocal, TipoArchivoFormato $tipo): array
    {
        return match ($tipo) {
            TipoArchivoFormato::Pdf => $this->desdePdf((string) file_get_contents($rutaLocal), 'exacta'),
            TipoArchivoFormato::Imagen => $this->desdeImagen($rutaLocal),
            TipoArchivoFormato::Docx => $this->desdeDocx($rutaLocal),
        };
    }

    /**
     * @return list<array{numero: int, ancho: float, alto: float}>
     */
    public function paginas(string $contenidoPdf): array
    {
        $temporal = $this->temporal('pdf', $contenidoPdf);

        try {
            $pdf = new Fpdi;
            $total = $pdf->setSourceFile($temporal);
            $paginas = [];

            for ($i = 1; $i <= $total; $i++) {
                $tamano = $pdf->getTemplateSize($pdf->importPage($i));

                if (! is_array($tamano)) {
                    continue;
                }

                $paginas[] = ['numero' => $i, 'ancho' => round((float) $tamano['width'], 2), 'alto' => round((float) $tamano['height'], 2)];
            }

            return $paginas;
        } finally {
            @unlink($temporal);
        }
    }

    /**
     * @return array{pdf: string, paginas: list<array{numero: int, ancho: float, alto: float}>, fidelidad: string}
     */
    private function desdePdf(string $contenido, string $fidelidad): array
    {
        return ['pdf' => $contenido, 'paginas' => $this->paginas($contenido), 'fidelidad' => $fidelidad];
    }

    /**
     * @return array{pdf: string, paginas: list<array{numero: int, ancho: float, alto: float}>, fidelidad: string}
     */
    private function desdeDocx(string $rutaLocal): array
    {
        $convertido = $this->docx->convertir((string) file_get_contents($rutaLocal));

        if ($convertido === null) {
            throw ValidationException::withMessages([
                'archivo' => 'No se pudo convertir el Word a PDF. Guárdalo como PDF desde Word y súbelo así.',
            ]);
        }

        return $this->desdePdf($convertido['pdf'], $convertido['fidelidad']);
    }

    /**
     * @return array{pdf: string, paginas: list<array{numero: int, ancho: float, alto: float}>, fidelidad: string}
     */
    private function desdeImagen(string $rutaLocal): array
    {
        $jpeg = $this->jpegOrientado($rutaLocal);
        $info = getimagesizefromstring($jpeg);

        if ($info === false) {
            throw ValidationException::withMessages(['archivo' => 'No se pudo leer la imagen.']);
        }

        [$anchoPx, $altoPx] = [$info[0], $info[1]];
        $anchoMm = $anchoPx >= $altoPx ? self::MM_CARTA_HORIZONTAL : self::MM_CARTA_VERTICAL;
        $altoMm = round($anchoMm * $altoPx / $anchoPx, 2);
        $temporal = $this->temporal('jpg', $jpeg);

        try {
            $pdf = new Fpdi;
            $pdf->SetAutoPageBreak(false);
            $pdf->SetMargins(0, 0, 0);
            $pdf->AddPage($anchoMm > $altoMm ? 'L' : 'P', [$anchoMm, $altoMm]);
            $pdf->Image($temporal, 0, 0, $anchoMm, $altoMm, 'JPG');

            return [
                'pdf' => (string) $pdf->Output('S'),
                'paginas' => [['numero' => 1, 'ancho' => $anchoMm, 'alto' => $altoMm]],
                'fidelidad' => 'exacta',
            ];
        } finally {
            @unlink($temporal);
        }
    }

    /**
     * JPEG re-codificado (sin metadatos) con la orientación EXIF aplicada:
     * una foto de celular tomada de lado queda derecha.
     */
    public function jpegOrientado(string $rutaLocal): string
    {
        $imagen = @imagecreatefromstring((string) file_get_contents($rutaLocal));

        if (! $imagen instanceof GdImage) {
            throw ValidationException::withMessages(['archivo' => 'No se pudo leer la imagen.']);
        }

        $orientacion = 1;

        try {
            if (function_exists('exif_read_data') && @exif_imagetype($rutaLocal) === IMAGETYPE_JPEG) {
                $exif = @exif_read_data($rutaLocal);
                $orientacion = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
            }
        } catch (Throwable) {
            $orientacion = 1;
        }

        $rotada = match ($orientacion) {
            3 => imagerotate($imagen, 180, 0),
            6 => imagerotate($imagen, -90, 0),
            8 => imagerotate($imagen, 90, 0),
            default => $imagen,
        };
        $imagen = $rotada instanceof GdImage ? $rotada : $imagen;

        // Fondo blanco para PNG/WEBP con transparencia.
        $lienzo = imagecreatetruecolor(imagesx($imagen), imagesy($imagen));
        imagefill($lienzo, 0, 0, (int) imagecolorallocate($lienzo, 255, 255, 255));
        imagecopy($lienzo, $imagen, 0, 0, 0, 0, imagesx($imagen), imagesy($imagen));

        ob_start();
        imagejpeg($lienzo, null, 92);

        return (string) ob_get_clean();
    }

    private function temporal(string $extension, string $contenido): string
    {
        $ruta = sys_get_temp_dir().DIRECTORY_SEPARATOR.Str::uuid().'.'.$extension;
        file_put_contents($ruta, $contenido);

        return $ruta;
    }
}
