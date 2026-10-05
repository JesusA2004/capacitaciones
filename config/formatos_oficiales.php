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
    | Conversión Word → PDF (App\Services\Formatos\Motor\ConversorDocxPdf).
    | Niveles de fidelidad (App\Enums\FidelidadConversion):
    |   nativa     Microsoft Word por COM (Windows con Office).
    |   alta       LibreOffice headless; válida para documentos definitivos
    |              SOLO si la versión del master pasó el QA visual con
    |              LibreOffice en ese servidor.
    |   aproximada PhpWord + DomPDF: solo vista previa. NUNCA un documento
    |              laboral definitivo (422 DOCUMENT_CONVERTER_UNAVAILABLE).
    */
    'libreoffice' => env('FORMATOS_LIBREOFFICE_PATH'),

    /*
    | 'auto' (Word si está instalado; si no, LibreOffice si está
    | configurado), o forzar 'word' | 'libreoffice' | 'phpword' (este último
    | deja SIN conversor fiel: los documentos definitivos se bloquean).
    */
    'conversor' => env('FORMATOS_CONVERSOR', 'auto'),
    'word_script' => resource_path('scripts/docx-a-pdf-word.ps1'),
    'word_habilitado' => (bool) env('FORMATOS_WORD_HABILITADO', true),
    'timeout_segundos' => (int) env('FORMATOS_CONVERSOR_TIMEOUT', 180),

];
