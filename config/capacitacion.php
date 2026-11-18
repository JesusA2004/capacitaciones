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

        /*
        | Formato oficial «Programa de capacitación para Certificación Mr
        | Lana» (RH-CC-R-001, 2 hojas). El sistema llena SOLO el encabezado
        | (nombre, sucursal, fecha de ingreso) sobre el PDF original con
        | FPDI; el resto se firma y llena a pluma. Copia versionada del
        | archivo que entregó RH en docs/certificacion/.
        */
        'formato' => [
            'clave' => 'RH-CC-R-001',
            'pdf' => env('CERTIFICACION_FORMATO_PDF', 'docs/certificacion/RH-CC-R-001_certificacion_gestor_de_credito.pdf'),
        ],

        /*
        | «NOTA: Para considerarse aprobado y asumir la certificación debe
        | mantener un promedio mínimo de 80» (hoja 2 del formato).
        */
        'promedio_minimo' => (float) env('CERTIFICACION_PROMEDIO_MINIMO', 80),
    ],

    /*
    | Días hacia adelante que la portada y el tablón del aula consideran
    | «próximas» sesiones en vivo y fechas límite.
    */
    'dias_proximos' => 14,

];
