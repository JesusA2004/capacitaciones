<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

/**
 * Content-Type seguro para servir archivos que SUBIÓ una persona (adjuntos
 * de solicitudes, documentos del alta pública, anexos de actas…).
 *
 * El MIME guardado en BD no es confiable: registros anteriores lo tomaban de
 * getClientMimeType() (lo que declara el navegador/cliente). Un archivo que
 * pasa `mimes:pdf` (empieza con «%PDF-») pero declarado como text/html se
 * servía inline con ese tipo y el navegador lo ejecutaba como HTML en el
 * dominio de People (XSS almacenado contra RH). Aquí solo pasan los tipos
 * que el navegador muestra sin ejecutar código; cualquier otro se entrega
 * como application/octet-stream, y siempre con nosniff.
 */
final class TipoContenidoSeguro
{
    /** @var list<string> */
    private const PERMITIDOS = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/heic',
        'image/heif',
        'video/mp4',
        'video/quicktime',
        'video/webm',
        'audio/mpeg',
        'audio/mp4',
        'audio/wav',
        'text/plain',
        'text/csv',
        'application/zip',
        'application/msword',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    public static function tipo(?string $mime): string
    {
        $normalizado = strtolower(trim(explode(';', (string) $mime)[0]));

        return in_array($normalizado, self::PERMITIDOS, true) ? $normalizado : 'application/octet-stream';
    }

    /**
     * Encabezados de tipo para la respuesta (se combinan con el
     * Content-Disposition de cada endpoint).
     *
     * @return array{Content-Type: string, X-Content-Type-Options: string}
     */
    public static function encabezados(?string $mime): array
    {
        return [
            'Content-Type' => self::tipo($mime),
            'X-Content-Type-Options' => 'nosniff',
        ];
    }

    /** MIME a guardar de un archivo recién subido: el detectado por contenido, nunca el que declara el cliente. */
    public static function deArchivo(UploadedFile $archivo): string
    {
        return $archivo->getMimeType() ?? 'application/octet-stream';
    }
}
