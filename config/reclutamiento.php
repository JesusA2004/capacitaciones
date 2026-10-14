<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Almacenamiento de CVs de candidatos
    |--------------------------------------------------------------------------
    |
    | Mismo disco NAS que expedientes (config/expedientes.php) y biblioteca
    | multimedia: la base de datos solo guarda metadatos (disk/path/nombre),
    | el archivo real vive fuera del repositorio y de la base de datos.
    |
    */

    'disk' => 'nas',

    'max_upload_mb' => env('RECLUTAMIENTO_CV_MAX_MB', 10),

    // Por WhatsApp el CV llega igual en PDF/Word que como foto.
    'extensiones_permitidas' => ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'webp'],

    /*
    |--------------------------------------------------------------------------
    | Bandeja de CVs (descargas de WhatsApp Web → NAS)
    |--------------------------------------------------------------------------
    |
    | Carpeta del NAS sincronizada (Synology Drive Client) con la carpeta de
    | descargas de Chrome de la computadora de reclutamiento. El sistema lista
    | lo que llega ahí y, al asignarlo a un candidato, lo MUEVE a
    | candidatos/{id}/cv/ — así nadie tiene que elegir el archivo con la
    | ventana «Seleccionar archivo» (ver App\Services\Reclutamiento\BandejaCvService
    | y docs/RECLUTAMIENTO.md → «Bandeja de CVs»). La carpeta es relativa a la
    | raíz del disco.
    |
    */

    'bandeja_cv' => [
        'disk' => env('RECLUTAMIENTO_BANDEJA_DISK', 'nas'),
        'carpeta' => env('RECLUTAMIENTO_BANDEJA_CARPETA', 'reclutamiento/bandeja-cv'),
        // Cuántos archivos (los más recientes) se ofrecen en el selector.
        'limite' => (int) env('RECLUTAMIENTO_BANDEJA_LIMITE', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mini expediente del candidato (antes de la contratación)
    |--------------------------------------------------------------------------
    |
    | Videos/fotografías del estudio socioeconómico, resultados psicométricos
    | y soporte de referencias (ver ExpedienteCandidatoService). Un video
    | grabado con el celular pesa fácilmente 100+ MB: el servidor debe
    | aceptar ese tamaño (php.ini upload_max_filesize/post_max_size y Nginx
    | client_max_body_size, ver docs/DEPLOY.md).
    |
    */

    'evidencias' => [
        'max_archivos' => 20,
        'max_documento_mb' => (int) env('RECLUTAMIENTO_EVIDENCIA_MAX_MB', 20),
        'max_video_mb' => (int) env('RECLUTAMIENTO_VIDEO_MAX_MB', 250),
        'extensiones_video' => ['mp4', 'mov', 'qt', 'm4v', '3gp', 'webm'],
        'extensiones_foto' => ['jpg', 'jpeg', 'png', 'webp'],
        'extensiones_documento' => ['pdf', 'doc', 'docx', 'xls', 'xlsx'],
    ],

];
