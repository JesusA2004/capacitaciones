<?php

/*
|--------------------------------------------------------------------------
| Integraciones servidor-a-servidor
|--------------------------------------------------------------------------
|
| Clientes externos que leen People con un token propio (tabla
| clientes_integracion), nunca con un token de la app móvil. Los tokens se
| emiten/rotan/revocan con `php artisan integraciones:clientes` (cualquier
| sistema) o `php artisan integraciones:token-auditoria` (atajo histórico de
| Auditoría) y nunca viven en .env de People.
| Ver docs/API_INTEGRACION_SISTEMAS.md y docs/integraciones/AUDITORIA.md.
|
*/

return [
    'auditoria' => [
        // Solicitudes por minuto del cliente Auditoría en /api/v1/auditoria
        // cuando el cliente no tiene un límite propio (limite_por_minuto).
        // 429 con Retry-After al pasarse. Antes 120/min.
        'limite_por_minuto' => (int) env('INTEGRACION_AUDITORIA_LIMITE_POR_MINUTO', 300),
        // Contrato «people-auditoria/3»: el NÚMERO de la clave de elector sólo
        // sale hacia Auditoría si el propietario lo enciende de forma expresa.
        // Apagado (por omisión) sólo viajan el tipo de identificación, la
        // institución que la expide y si hay clave capturada.
        'enviar_clave_elector' => (bool) env('INTEGRACION_AUDITORIA_ENVIAR_CLAVE_ELECTOR', false),
    ],

    /*
    | API general multi-sistema: /api/integracion/v1 (Reportes y los que sigan).
    */
    'sistemas' => [
        // Solicitudes por minuto por cliente cuando no tiene límite propio.
        'limite_por_minuto' => (int) env('INTEGRACION_LIMITE_POR_MINUTO', 600),
        // Tope de un límite propio (`--limite=` del comando): nadie pasa de aquí.
        'limite_maximo_por_minuto' => (int) env('INTEGRACION_LIMITE_MAXIMO_POR_MINUTO', 6000),

        // Bitácora de consultas (consultas_integracion): días que se conservan
        // antes de que `php artisan model:prune` las depure (mínimo 30).
        'bitacora' => (bool) env('INTEGRACION_BITACORA', true),
        'bitacora_retencion_dias' => (int) env('INTEGRACION_BITACORA_RETENCION_DIAS', 365),

        // Paginación por cursor de /colaboradores.
        'por_pagina' => 200,
        'por_pagina_maximo' => 1000,

        // Altas, bajas y rotación: rango máximo por consulta y número máximo
        // de periodos que puede devolver /rotacion.
        'rango_maximo_dias' => 731,
        'periodos_maximos' => 120,

        // Segundos que se reutiliza un cálculo de rotación idéntico (mismos
        // filtros y empresas) antes de recalcularlo. 0 = sin caché.
        'rotacion_cache_segundos' => (int) env('INTEGRACION_ROTACION_CACHE_SEGUNDOS', 60),
    ],
];
