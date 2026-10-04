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

    /*
    | Conversor preferido para documentos maestros (contratos, avisos…):
    | 'auto' (LibreOffice si está configurado; si no, Microsoft Word en
    | servidores Windows con Office; si no, PhpWord aproximado), o forzar
    | 'libreoffice' | 'word' | 'phpword'. La salida aproximada se marca en
    | el documento generado (fidelidad = aproximada) para que RH lo sepa.
    */
    'conversor' => env('FORMATOS_CONVERSOR', 'auto'),
    'word_script' => resource_path('scripts/docx-a-pdf-word.ps1'),
    'timeout_segundos' => (int) env('FORMATOS_CONVERSOR_TIMEOUT', 180),

];
