<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Formatos oficiales de MR. LANA
    |--------------------------------------------------------------------------
    |
    | Mismo disco NAS que plantillas/expedientes: la base de datos solo
    | guarda metadatos, el PDF/DOCX real vive fuera del repositorio. Ver
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

];
