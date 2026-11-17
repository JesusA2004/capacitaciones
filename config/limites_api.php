<?php

/*
|--------------------------------------------------------------------------
| Límites de solicitudes de la API móvil (/api/v1, Sanctum)
|--------------------------------------------------------------------------
|
| Solicitudes por minuto. Los define App\Providers\AppServiceProvider
| (configureRateLimiting). Los de la API servidor-a-servidor viven en
| config/integraciones.php (por cliente de integración).
|
| El login (`api-login`, 10/min por usuario+IP) NO es configurable a
| propósito: abrirlo facilitaría adivinar contraseñas.
|
*/

return [
    // General por cuenta (o por IP sin sesión). Antes 120/min.
    'movil_por_minuto' => (int) env('API_MOVIL_LIMITE_POR_MINUTO', 240),

    // Cargas e importaciones pesadas (archivos) por cuenta.
    'cargas_por_minuto' => (int) env('API_CARGAS_LIMITE_POR_MINUTO', 30),

    // Re-autenticación (confirmar contraseña) por cuenta+IP: estricto.
    'reautenticar_por_minuto' => 5,
];
