<?php

/*
|--------------------------------------------------------------------------
| Seguimiento (Fase 2): conexión con la API v1 de Reportes
|--------------------------------------------------------------------------
|
| PEOPLE NO calcula colocación, recuperación, cartera ni mora: los LEE de
| Reportes (servidor a servidor, token Sanctum de un ApiConsumer de
| Reportes con abilities branches.read, employees.read, periods.read y
| financial.read). Ver docs/SEGUIMIENTO.md y, en Reportes,
| docs/api-reporteria.md §14 y §15.
|
| Periodos (modelo de Reportes, respetado tal cual): la base es la SEMANA
| (type=weekly); un MES (type=monthly) agrupa 4 semanas en
| component_period_ids. Los datos reales se cargan al MES; el desglose
| semanal sale de /periods/{mes}/weekly-activity (por fecha de operación).
|
*/

return [

    'reportes' => [
        // Base de la API SIN la ruta /api/v1, p. ej. https://reportes.mr-lana.com
        // o, en local con WAMP, http://localhost/Reportes/public
        'url' => env('REPORTES_API_URL'),

        // Token del ApiConsumer «MR. LANA PEOPLE» (se imprime UNA vez al
        // crearlo en Reportes con php artisan api:consumers:create).
        'token' => env('REPORTES_API_TOKEN'),

        // Segundos. weekly-activity calcula la radiografía la primera vez
        // (luego Reportes la guarda 12 h): por eso un timeout holgado.
        'timeout' => (int) env('REPORTES_API_TIMEOUT', 60),
        'timeout_conexion' => (int) env('REPORTES_API_TIMEOUT_CONEXION', 5),

        // Reintentos solo ante fallas de red, 429 y 5xx (nunca 401/403/404/422).
        'reintentos' => (int) env('REPORTES_API_REINTENTOS', 2),
        'espera_reintento_ms' => (int) env('REPORTES_API_ESPERA_REINTENTO_MS', 400),

        // Minutos que PEOPLE guarda cada respuesta correcta (Reportes ya
        // invalida su propia caché al regenerar la radiografía).
        'cache_minutos' => (int) env('REPORTES_API_CACHE_MINUTOS', 30),

        'verificar_ssl' => (bool) env('REPORTES_API_VERIFICAR_SSL', true),
    ],

    // Meses hacia atrás que se ofrecen en el selector de periodo y que se
    // grafican en la tendencia mensual de un gestor (Reportes admite hasta 24).
    'meses_tendencia' => 6,

    // Semanas que se grafican en la tendencia semanal de un gestor
    // (las del mes elegido y las de los meses anteriores necesarios).
    'semanas_tendencia' => 12,

];
