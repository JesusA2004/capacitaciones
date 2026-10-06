<?php

/*
|--------------------------------------------------------------------------
| Motores de PDF para documentos administrativos HTML
|--------------------------------------------------------------------------
|
| Recibo de nómina, finiquito, comprobante de solicitud y constancia se
| arman en HTML (plantilla de Documentos maestros → Documentos
| administrativos) y se imprimen con un motor (App\Services\Pdf):
|
|   browsershot  Chrome/Chromium headless (CSS de impresión moderno). Requiere
|                node, el paquete npm `puppeteer` y Chrome/Chromium en el
|                servidor.
|   dompdf       barryvdh/laravel-dompdf (sin dependencias externas, CSS
|                limitado).
|
| Los contratos y documentos jurídicos NO pasan por aquí: siguen con su
| motor DOCX / PDF overlay (docs/MOTOR_DOCUMENTOS_MAESTROS.md).
|
*/

return [

    // Motor por defecto de una versión de plantilla que no elige uno.
    'renderer' => env('PDF_RENDERER', 'browsershot'),

    // Si Chrome falla, ¿se permite caer a DomPDF? Solo si se configura
    // explícitamente: por defecto el error se reporta (nunca se cambia de
    // motor en silencio).
    'fallback_dompdf' => (bool) env('PDF_FALLBACK_DOMPDF', false),

    'browsershot' => [
        // Rutas absolutas; vacío = se buscan en el PATH del proceso
        // (ver `php artisan people:diagnostico-pdf`).
        'node_binary' => env('BROWSERSHOT_NODE_BINARY'),
        'npm_binary' => env('BROWSERSHOT_NPM_BINARY'),
        'chrome_path' => env('BROWSERSHOT_CHROME_PATH'),
        // Carpeta donde está node_modules/puppeteer (por defecto, la del proyecto).
        'node_modules_path' => env('BROWSERSHOT_NODE_MODULES_PATH', base_path('node_modules')),
        // PHP-FPM corre como www-data sin sandbox de usuario de Chrome.
        'no_sandbox' => (bool) env('BROWSERSHOT_NO_SANDBOX', true),
        'timeout' => (int) env('BROWSERSHOT_TIMEOUT', 60),
    ],

];
