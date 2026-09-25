<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Plantillas oficiales de MR. LANA
    |--------------------------------------------------------------------------
    |
    | Mismo disco NAS que plantillas/expedientes: la base de datos solo
    | guarda metadatos, el archivo real vive fuera del repositorio. Ver
    | docs/FORMATOS_OFICIALES.md.
    |
    */

    'disk' => 'nas',

    /*
    | Carpeta local (fuera de Git salvo .gitkeep) donde RH coloca los PDFs
    | oficiales originales antes de correr `php artisan
    | formatos:importar-originales`. Relativa a base_path().
    */
    'origen_local' => 'claude/formatos/originales',

    /*
    | Tamaño máximo del archivo base que RH puede subir (KB).
    */
    'max_kb' => (int) env('FORMATOS_MAX_KB', 20480),

    /*
    | OCR opcional para formatos escaneados (imagen). Sin ruta configurada,
    | el sistema funciona igual con mapeo manual.
    */
    'ocr' => [
        'tesseract' => env('FORMATOS_TESSERACT_PATH'),
        'idioma' => env('FORMATOS_TESSERACT_IDIOMA', 'spa'),
    ],

    /*
    | Conversión Word → PDF. Con LibreOffice (soffice) la conversión es
    | fiel al documento; sin él se usa PhpWord + DomPDF (aproximada, se
    | avisa en pantalla).
    */
    'libreoffice' => env('FORMATOS_LIBREOFFICE_PATH'),

];
