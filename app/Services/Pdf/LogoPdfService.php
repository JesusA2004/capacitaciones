<?php

namespace App\Services\Pdf;

use Illuminate\Support\Facades\Storage;

/**
 * Logo para los PDF como data URI (igual en Chrome y DomPDF, sin depender
 * de la red): el logo propio de la empresa (disco `public`) si existe; si
 * no, el logo institucional de los formatos oficiales
 * (resources/documentos/logo-mr-lana.png).
 */
class LogoPdfService
{
    public function dataUri(?string $logoEmpresa = null): ?string
    {
        if ($logoEmpresa !== null && $logoEmpresa !== '' && Storage::disk('public')->exists($logoEmpresa)) {
            $mime = Storage::disk('public')->mimeType($logoEmpresa) ?: 'image/png';

            return sprintf('data:%s;base64,%s', $mime, base64_encode((string) Storage::disk('public')->get($logoEmpresa)));
        }

        $ruta = resource_path('documentos/logo-mr-lana.png');

        return is_file($ruta) ? sprintf('data:image/png;base64,%s', base64_encode((string) file_get_contents($ruta))) : null;
    }
}
