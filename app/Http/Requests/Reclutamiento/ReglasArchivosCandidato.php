<?php

namespace App\Http\Requests\Reclutamiento;

use Closure;
use Illuminate\Http\UploadedFile;

/**
 * Reglas de los archivos del mini expediente del candidato
 * (config('reclutamiento.evidencias')): los videos admiten un tamaño mayor
 * que fotografías y documentos. El tipo se valida por contenido real
 * (`mimes:` usa finfo), no por la extensión que manda el navegador.
 */
final class ReglasArchivosCandidato
{
    /**
     * @param  list<string>  $grupos  video, foto y/o documento.
     * @return array<string, list<mixed>>
     */
    public static function para(string $campo, array $grupos, bool $requerido = false): array
    {
        $config = (array) config('reclutamiento.evidencias');
        $extensiones = [];

        foreach ($grupos as $grupo) {
            $extensiones = [...$extensiones, ...(array) ($config['extensiones_'.$grupo] ?? [])];
        }

        $maxVideoMb = (int) ($config['max_video_mb'] ?? 250);
        $maxDocumentoMb = (int) ($config['max_documento_mb'] ?? 20);
        $admiteVideo = in_array('video', $grupos, true);

        return [
            $campo => [$requerido ? 'required' : 'nullable', 'array', 'max:'.(int) ($config['max_archivos'] ?? 20)],
            $campo.'.*' => [
                'file',
                'mimes:'.implode(',', array_unique($extensiones)),
                'max:'.(($admiteVideo ? $maxVideoMb : $maxDocumentoMb) * 1024),
                function (string $atributo, mixed $valor, Closure $falla) use ($maxDocumentoMb): void {
                    $esVideo = $valor instanceof UploadedFile && str_starts_with((string) $valor->getMimeType(), 'video/');

                    if ($valor instanceof UploadedFile && ! $esVideo && $valor->getSize() > $maxDocumentoMb * 1024 * 1024) {
                        $falla(sprintf('«%s» supera %d MB (solo los videos pueden pesar más).', $valor->getClientOriginalName(), $maxDocumentoMb));
                    }
                },
            ],
        ];
    }
}
