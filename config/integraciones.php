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
        // Contrato «people-auditoria/3»: el NÚMERO de la clave de elector sólo
        // sale hacia Auditoría si el propietario lo enciende de forma expresa.
        // Apagado (por omisión) sólo viajan el tipo de identificación, la
        // institución que la expide y si hay clave capturada.
        'enviar_clave_elector' => (bool) env('INTEGRACION_AUDITORIA_ENVIAR_CLAVE_ELECTOR', false),
    ],
];
