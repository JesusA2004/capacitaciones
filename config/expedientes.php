<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Disco de almacenamiento de documentos de expediente
    |--------------------------------------------------------------------------
    |
    | Disco registrado en config/filesystems.php usado por
    | App\Services\Expedientes\DocumentoStorageService. Nunca se expone al
    | frontend la ruta fisica real de este disco; el navegador solo conoce
    | IDs de documento (employee_documents.id) y descarga a traves de una
    | ruta protegida por policy (ver routes/rh.php).
    |
    */
    'disk' => 'nas',

    /*
    |--------------------------------------------------------------------------
    | Limite de tamano por archivo (MB)
    |--------------------------------------------------------------------------
    */
    'max_upload_mb' => (int) env('EXPEDIENTES_MAX_UPLOAD_MB', 20),

    /*
    |--------------------------------------------------------------------------
    | Extensiones permitidas para documentos de expediente
    |--------------------------------------------------------------------------
    */
    'extensiones_permitidas' => ['pdf', 'jpg', 'jpeg', 'png'],

];
