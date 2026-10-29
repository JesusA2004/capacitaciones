<?php

/*
|--------------------------------------------------------------------------
| Integraciones servidor-a-servidor
|--------------------------------------------------------------------------
|
| Clientes externos que leen People con un token propio (tabla
| clientes_integracion), nunca con un token de la app móvil. Los tokens se
| emiten/rotan/revocan con `php artisan integraciones:token-auditoria` y
| nunca viven en .env de People.
|
*/

return [
    'auditoria' => [
        // Solicitudes por minuto por cliente (429 con Retry-After al pasarse).
        'limite_por_minuto' => (int) env('INTEGRACION_AUDITORIA_LIMITE_POR_MINUTO', 120),
    ],
];
