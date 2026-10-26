<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

/**
 * Extensión con la que se guarda físicamente un archivo subido: la que
 * corresponde a su CONTENIDO real (MIME detectado con finfo), no la que trae
 * el nombre del cliente. Así una foto JPG renombrada «finiquito.pdf» se
 * guarda como .jpg y la vista previa/descarga la sirve como imagen, no como
 * un PDF roto. Solo si el contenido no se reconoce se usa la del nombre
 * (saneada a letras y números).
 */
final class ExtensionArchivo
{
    public static function real(UploadedFile $archivo): string
    {
        $adivinada = $archivo->guessExtension();

        if (is_string($adivinada) && $adivinada !== '') {
            return strtolower($adivinada);
        }

        $cliente = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $archivo->getClientOriginalExtension()));

        return $cliente !== '' ? $cliente : 'bin';
    }
}
