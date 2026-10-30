<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Descriptivos de puesto oficiales (PDF)
    |--------------------------------------------------------------------------
    |
    | Carpeta de los PDF originales y disco donde se guarda la copia exacta.
    | Asociación PDF → puesto PEOPLE: 1) nombre exacto, 2) alias explícito de
    | esta lista, 3) RH confirma. Nunca por parecido. Los alias aceptan
    | patrones con % (p. ej. «Gerente Regional%»).
    |
    */

    'carpeta' => env('DESCRIPTIVOS_CARPETA', base_path('docs/descriptivos')),

    'disk' => 'nas',

    'alias' => [
        'Asistente de Dirección Comercial' => ['Asistente de Dirección Comercial'],
        'Gerente de Sucursal' => ['Gerente de Sucursal'],
        'Gerente Regional Comercial' => ['Gerente Regional%', 'Subgerente Regional'],
        'Analista de mesa de control' => ['Analista de Mesa de Control'],
        // Solo el puesto de Mesa de Control: la «Auditora» de Contraloría es
        // otro puesto; si no existe este, RH lo confirma.
        'Auditoria de mesa de control' => ['Auditora de Mesa de Control', 'Auditor de Mesa de Control'],
        'Coordinadora' => ['Coordinadora de Sucursal'],
        'Gerencia Mesa de Control' => ['Gerente de Mesa de Control'],
        'Regional de Coordinación' => ['Coordinadora Regional', 'Regional Administrativo%'],
        'Subgerente de sucursal' => ['Subgerente', 'Subgerente de Sucursal'],
        'Vendedor de crédito' => ['Gestor', 'Gestor grupal', 'Gestor Volante'],
    ],

];
