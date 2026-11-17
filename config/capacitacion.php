<?php

/*
|--------------------------------------------------------------------------
| Capacitación (Fase 3) — configuración de negocio versionada
|--------------------------------------------------------------------------
|
| Ver docs/CAPACITACION.md. Los valores de aquí son una BASE MÍNIMA
| funcional: los umbrales de la «Certificación Mr. Lana» y la definición de
| rutas por puesto siguen pendientes de decisión de negocio, por eso viven
| en configuración y no en el código de los servicios.
|
*/

return [

    'certificacion' => [

        /*
        | Nivel de la Certificación Mr. Lana según cuántos cursos con
        | constancia completó el colaborador (App\Enums\NivelCertificacion).
        | Cuenta solo constancias emitidas (Certificado), nunca cursos sin
        | constancia. PENDIENTE de negocio: rutas por puesto (qué cursos
        | exactos forman cada nivel) en lugar de un simple conteo.
        */
        'umbrales' => [
            'bronce' => (int) env('CERTIFICACION_UMBRAL_BRONCE', 1),
            'plata' => (int) env('CERTIFICACION_UMBRAL_PLATA', 3),
            'oro' => (int) env('CERTIFICACION_UMBRAL_ORO', 5),
        ],
    ],

    /*
    | Días hacia adelante que la portada y el tablón del aula consideran
    | «próximas» sesiones en vivo y fechas límite.
    */
    'dias_proximos' => 14,

];
