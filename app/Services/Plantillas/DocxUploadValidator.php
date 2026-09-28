<?php

namespace App\Services\Plantillas;

use Illuminate\Http\UploadedFile;
use ZipArchive;

/**
 * Un DOCX es un ZIP: `mimes:docx` + el límite de tamaño SUBIDO
 * (`plantillas.max_upload_mb`) no dicen nada sobre cuánto pesa una vez
 * DESCOMPRIMIDO. Un archivo pequeño con una razón de compresión absurda
 * (zip bomb) podría agotar la memoria del proceso cuando
 * `PlantillaDocumentoService`/PhpWord lo abren — esta validación corta eso
 * en la subida, antes de que el archivo llegue a guardarse.
 */
class DocxUploadValidator
{
    private const MAX_UNCOMPRESSED_BYTES = 100 * 1024 * 1024; // 100 MB

    public static function esZipSeguro(UploadedFile $archivo): bool
    {
        $zip = new ZipArchive;

        if ($zip->open((string) $archivo->getRealPath()) !== true) {
            return false;
        }

        $totalDescomprimido = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);

            if ($stat === false) {
                $zip->close();

                return false;
            }

            $totalDescomprimido += $stat['size'];

            if ($totalDescomprimido > self::MAX_UNCOMPRESSED_BYTES) {
                $zip->close();

                return false;
            }
        }

        $zip->close();

        return true;
    }
}
