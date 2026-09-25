<?php

namespace App\Services\Formatos\Motor;

use App\Enums\TipoArchivoFormato;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use setasign\Fpdi\Fpdi;
use Throwable;
use ZipArchive;

/**
 * Valida el archivo base de una plantilla oficial por su CONTENIDO real, no
 * por el nombre ni la extensión que manda el navegador: firma %PDF y que
 * FPDI pueda abrirlo; imagen que GD pueda decodificar; DOCX que sea un
 * paquete Word real (sin macros). Nada ejecutable.
 */
class ValidadorArchivoPlantilla
{
    private const MIME_IMAGEN = ['image/png', 'image/jpeg', 'image/webp'];

    private const MIME_DOCX = ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'];

    /**
     * @return array{tipo: TipoArchivoFormato, extension: string, mime: string}
     */
    public function validar(UploadedFile $archivo): array
    {
        if (! $archivo->isValid()) {
            $this->error('El archivo no se subió completo. Intenta de nuevo.');
        }

        $maxBytes = (int) config('formatos_oficiales.max_kb', 20480) * 1024;

        if ($archivo->getSize() === false || $archivo->getSize() === 0 || $archivo->getSize() > $maxBytes) {
            $this->error(sprintf('El archivo debe pesar menos de %d MB.', intdiv($maxBytes, 1024 * 1024)));
        }

        $ruta = (string) $archivo->getRealPath();
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($ruta);
        $cabecera = (string) file_get_contents($ruta, false, null, 0, 8);

        if ($mime === 'application/pdf' || str_starts_with($cabecera, '%PDF-')) {
            $this->validarPdf($ruta);

            return ['tipo' => TipoArchivoFormato::Pdf, 'extension' => 'pdf', 'mime' => 'application/pdf'];
        }

        if (in_array($mime, self::MIME_IMAGEN, true)) {
            $info = @getimagesize($ruta);

            if ($info === false || $info[0] < 200 || $info[1] < 200 || @imagecreatefromstring((string) file_get_contents($ruta)) === false) {
                $this->error('La imagen está dañada o es demasiado pequeña (mínimo 200×200 px) para usarse como formato.');
            }

            return ['tipo' => TipoArchivoFormato::Imagen, 'extension' => $mime === 'image/png' ? 'png' : ($mime === 'image/webp' ? 'webp' : 'jpg'), 'mime' => $mime];
        }

        if (in_array($mime, self::MIME_DOCX, true) && $this->esDocx($ruta)) {
            return ['tipo' => TipoArchivoFormato::Docx, 'extension' => 'docx', 'mime' => self::MIME_DOCX[0]];
        }

        $this->error('Formato no admitido. Sube un PDF, un Word (DOCX) o una imagen PNG, JPG o WEBP.');
    }

    private function validarPdf(string $ruta): void
    {
        try {
            $pdf = new Fpdi;

            if ($pdf->setSourceFile($ruta) < 1) {
                $this->error('El PDF no tiene páginas.');
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable) {
            $this->error('El PDF está dañado, protegido con contraseña o usa una compresión que no se puede leer. Ábrelo y vuelve a guardarlo como PDF (o súbelo como imagen).');
        }
    }

    private function esDocx(string $ruta): bool
    {
        $zip = new ZipArchive;

        if ($zip->open($ruta) !== true) {
            return false;
        }

        try {
            $tipos = (string) $zip->getFromName('[Content_Types].xml');

            // DOCM/macros: se rechaza aunque la extensión diga .docx.
            return $zip->locateName('word/document.xml') !== false
                && $zip->locateName('word/vbaProject.bin') === false
                && ! str_contains($tipos, 'macroEnabled');
        } finally {
            $zip->close();
        }
    }

    private function error(string $mensaje): never
    {
        throw ValidationException::withMessages(['archivo' => $mensaje]);
    }
}
