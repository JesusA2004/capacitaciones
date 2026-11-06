<?php

namespace App\Services\Expedientes;

use App\Models\DocumentType;
use App\Services\DocumentosMaestros\Pdf\NormalizadorPdf;
use GdImage;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
use Throwable;

/**
 * Documentos de dos caras (hoy la identificación oficial / INE,
 * config('expedientes.dos_caras')): la persona puede subir UN archivo (un PDF
 * con frente y reverso, o solo el frente) o DOS (frente y reverso, cada uno
 * PDF o foto). El reverso es opcional.
 *
 * Cuando llegan los dos, aquí se unen en UN solo PDF (frente primero; cada
 * foto en su propia hoja, derecha según su EXIF y rotulada «Frente» /
 * «Reverso»; cada PDF con todas sus páginas). Ese PDF es el que sigue el
 * flujo normal: una sola versión del documento, en revisión de RH, con
 * vista previa de las dos caras y una sola ruta en el NAS — aprobación,
 * extracción de datos, organización del NAS y descargas no cambian.
 *
 * Misma regla para la web de RH (DocumentoParService::subir), la app
 * (IncorporacionService::subirDocumento → DocumentoParService) y el alta
 * digital pública.
 */
class DocumentoDosCarasService
{
    /** Lado mayor máximo (px) de una foto dentro del PDF: suficiente para leer la credencial sin inflar el archivo. */
    private const LADO_MAXIMO_FOTO = 2400;

    private const MARGEN_MM = 12.0;

    private const ALTO_ROTULO_MM = 8.0;

    public function __construct(private readonly NormalizadorPdf $normalizador) {}

    /** Si el tipo de documento acepta un segundo archivo (reverso). */
    public static function admiteReverso(DocumentType|string|null $tipo): bool
    {
        $clave = $tipo instanceof DocumentType ? $tipo->clave : $tipo;

        return $clave !== null && in_array($clave, (array) config('expedientes.dos_caras', []), true);
    }

    /**
     * Ejecuta `$subir` con el archivo final del documento: el mismo
     * `$frente` si no hay reverso (compatibilidad total con la subida de un
     * solo archivo), o el PDF que une frente y reverso. El PDF temporal se
     * borra siempre al terminar, haya salido bien o no la subida.
     *
     * @template T
     *
     * @param  callable(UploadedFile): T  $subir
     * @return T
     *
     * @throws RuntimeException si el tipo no admite reverso o no se pudieron unir los archivos
     */
    public function conArchivoFinal(DocumentType $tipo, UploadedFile $frente, ?UploadedFile $reverso, callable $subir): mixed
    {
        if ($reverso === null) {
            return $subir($frente);
        }

        if (! self::admiteReverso($tipo)) {
            throw new RuntimeException(sprintf('«%s» se sube en un solo archivo; no lleva reverso.', $tipo->nombre));
        }

        $ruta = $this->unirEnPdf($frente, $reverso);

        try {
            return $subir(new UploadedFile($ruta, sprintf('%s (frente y reverso).pdf', $tipo->nombre), 'application/pdf', null, true));
        } finally {
            $this->borrar($ruta);
        }
    }

    /**
     * Une frente y reverso (PDF o imagen cada uno) en un PDF temporal y
     * regresa su ruta local. Quien llama la borra.
     *
     * @throws RuntimeException
     */
    public function unirEnPdf(UploadedFile $frente, UploadedFile $reverso): string
    {
        $temporales = [];

        try {
            $pdf = new Fpdi;
            $pdf->SetAutoPageBreak(false);
            $pdf->SetTitle('Identificacion oficial - frente y reverso');

            foreach (['Frente' => $frente, 'Reverso' => $reverso] as $cara => $archivo) {
                if ($this->esPdf($archivo)) {
                    $this->agregarPdf($pdf, $archivo, $cara);
                } else {
                    $this->agregarImagen($pdf, $archivo, $cara, $temporales);
                }
            }

            $ruta = $this->nuevoTemporal('pdf');
            file_put_contents($ruta, $pdf->Output('S'));

            if (! is_file($ruta) || filesize($ruta) === 0) {
                $this->borrar($ruta);

                throw new RuntimeException('No se pudo generar el PDF con frente y reverso.');
            }

            return $ruta;
        } finally {
            foreach ($temporales as $temporal) {
                $this->borrar($temporal);
            }
        }
    }

    private function esPdf(UploadedFile $archivo): bool
    {
        return ($archivo->getMimeType() ?? $archivo->getClientMimeType()) === 'application/pdf';
    }

    /** Copia todas las páginas del PDF con su tamaño y orientación originales. */
    private function agregarPdf(Fpdi $pdf, UploadedFile $archivo, string $cara): void
    {
        try {
            $contenido = (string) file_get_contents((string) $archivo->getRealPath());
            $contenido = $this->normalizador->necesitaNormalizar($contenido) ? $this->normalizador->normalizar($contenido) : $contenido;
            $paginas = $pdf->setSourceFile(StreamReader::createByString($contenido));

            for ($numero = 1; $numero <= $paginas; $numero++) {
                $plantilla = $pdf->importPage($numero);
                $tamano = $pdf->getTemplateSize($plantilla);

                if (! is_array($tamano)) {
                    throw new RuntimeException(sprintf('no se pudo leer el tamaño de la página %d', $numero));
                }

                $pdf->AddPage($tamano['orientation'], [$tamano['width'], $tamano['height']]);
                $pdf->useTemplate($plantilla);
            }
        } catch (Throwable $e) {
            throw new RuntimeException(sprintf('No se pudo leer el PDF del %s (¿está protegido con contraseña?). Súbelo como foto o en otro PDF.', mb_strtolower($cara)), 0, $e);
        }
    }

    /**
     * Una hoja A4 (vertical u horizontal según la foto) con la imagen
     * centrada y el rótulo de la cara arriba.
     *
     * @param  list<string>  $temporales
     */
    private function agregarImagen(Fpdi $pdf, UploadedFile $archivo, string $cara, array &$temporales): void
    {
        $ruta = $this->imagenNormalizada($archivo, $cara);

        if ($ruta !== $archivo->getRealPath()) {
            $temporales[] = $ruta;
        }

        $medidas = getimagesize($ruta);

        if ($medidas === false || $medidas[0] < 1 || $medidas[1] < 1) {
            throw new RuntimeException(sprintf('No se pudo leer la foto del %s.', mb_strtolower($cara)));
        }

        [$anchoPx, $altoPx] = $medidas;
        $horizontal = $anchoPx > $altoPx;
        $anchoHoja = $horizontal ? 297.0 : 210.0;
        $altoHoja = $horizontal ? 210.0 : 297.0;
        $anchoUtil = $anchoHoja - 2 * self::MARGEN_MM;
        $altoUtil = $altoHoja - 2 * self::MARGEN_MM - self::ALTO_ROTULO_MM;
        $escala = min($anchoUtil / $anchoPx, $altoUtil / $altoPx);
        $ancho = $anchoPx * $escala;
        $alto = $altoPx * $escala;

        try {
            $pdf->AddPage($horizontal ? 'L' : 'P', 'A4');
            $pdf->SetFont('Helvetica', 'B', 10);
            $pdf->SetTextColor(90, 90, 90);
            $pdf->SetXY(self::MARGEN_MM, self::MARGEN_MM);
            $pdf->Cell($anchoUtil, self::ALTO_ROTULO_MM - 2, $cara, 0, 0, 'L');
            $pdf->Image(
                $ruta,
                self::MARGEN_MM + ($anchoUtil - $ancho) / 2,
                self::MARGEN_MM + self::ALTO_ROTULO_MM + ($altoUtil - $alto) / 2,
                $ancho,
                $alto,
                $medidas[2] === IMAGETYPE_PNG ? 'PNG' : 'JPG',
            );
        } catch (Throwable $e) {
            throw new RuntimeException(sprintf('No se pudo agregar la foto del %s al PDF.', mb_strtolower($cara)), 0, $e);
        }
    }

    /**
     * La foto lista para el PDF: derecha según su orientación EXIF (las
     * fotos del celular suelen venir «acostadas»), con transparencia
     * aplanada sobre blanco y reducida a un tamaño razonable, en JPEG. Sin
     * GD se usa el archivo tal cual (FPDF lee JPG/PNG por su cuenta).
     */
    private function imagenNormalizada(UploadedFile $archivo, string $cara): string
    {
        $original = (string) $archivo->getRealPath();

        if (! function_exists('imagecreatefromstring')) {
            return $original;
        }

        $bytes = file_get_contents($original);
        $imagen = $bytes !== false && $bytes !== '' ? imagecreatefromstring($bytes) : false;

        if ($imagen === false) {
            throw new RuntimeException(sprintf('No se pudo leer la foto del %s. Sube un JPG, PNG o PDF.', mb_strtolower($cara)));
        }

        $imagen = $this->enderezar($imagen, $original);
        $imagen = $this->reducir($imagen);

        $lienzo = imagecreatetruecolor(imagesx($imagen), imagesy($imagen));

        if ($lienzo === false) {
            throw new RuntimeException(sprintf('No se pudo procesar la foto del %s.', mb_strtolower($cara)));
        }

        $blanco = imagecolorallocate($lienzo, 255, 255, 255);

        if ($blanco !== false) {
            imagefill($lienzo, 0, 0, $blanco);
        }

        imagecopy($lienzo, $imagen, 0, 0, 0, 0, imagesx($imagen), imagesy($imagen));

        $ruta = $this->nuevoTemporal('jpg');

        if (! imagejpeg($lienzo, $ruta, 85)) {
            $this->borrar($ruta);

            throw new RuntimeException(sprintf('No se pudo procesar la foto del %s.', mb_strtolower($cara)));
        }

        return $ruta;
    }

    private function enderezar(GdImage $imagen, string $ruta): GdImage
    {
        if (! function_exists('exif_read_data') || exif_imagetype($ruta) !== IMAGETYPE_JPEG) {
            return $imagen;
        }

        try {
            $exif = exif_read_data($ruta);
        } catch (Throwable) {
            return $imagen;
        }

        $grados = match (is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1) {
            3 => 180,
            6 => 270,
            8 => 90,
            default => 0,
        };

        if ($grados === 0) {
            return $imagen;
        }

        $rotada = imagerotate($imagen, $grados, 0);

        return $rotada !== false ? $rotada : $imagen;
    }

    private function reducir(GdImage $imagen): GdImage
    {
        $ancho = imagesx($imagen);
        $alto = imagesy($imagen);
        $lado = max($ancho, $alto);

        if ($lado <= self::LADO_MAXIMO_FOTO) {
            return $imagen;
        }

        $factor = self::LADO_MAXIMO_FOTO / $lado;
        $reducida = imagescale($imagen, max(1, (int) round($ancho * $factor)), max(1, (int) round($alto * $factor)));

        return $reducida !== false ? $reducida : $imagen;
    }

    private function nuevoTemporal(string $extension): string
    {
        $base = tempnam(sys_get_temp_dir(), 'dos_caras_');

        if ($base === false) {
            throw new RuntimeException('No se pudo crear un archivo temporal para unir frente y reverso.');
        }

        $ruta = sprintf('%s.%s', $base, $extension);
        rename($base, $ruta);

        return $ruta;
    }

    private function borrar(string $ruta): void
    {
        if (is_file($ruta)) {
            unlink($ruta);
        }
    }
}
