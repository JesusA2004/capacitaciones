<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Directorio de la empresa
    |--------------------------------------------------------------------------
    |
    | Grupos de puesto (puestos.grupo_documental) que NO aparecen en el
    | directorio. Los gestores no tienen correo corporativo y no son contacto
    | de comunicación interna (decisión de RH, 2026-10-13); siguen existiendo
    | en expedientes, organigrama y reportes.
    |
    */

    'excluir_grupos' => ['gestor'],

    // Además, por nombre de puesto (p. ej. «Gestor grupal» sin grupo).
    'excluir_puestos' => ['Gestor%'],

    /*
    | Vista «Por área»: en Corporativo el área es el departamento del puesto
    | (con estos alias); en sucursal, el puesto en plural.
    */
    'alias_areas_corporativo' => [
        'Ventas' => 'Dirección Comercial',
        'Operaciones' => 'Dirección Comercial',
    ],

    'areas_sucursal' => [
        'Gerente Regional%' => 'Gerentes regionales',
        'Coordinadora Regional%' => 'Coordinación regional',
        'Gerente de Sucursal' => 'Gerentes de sucursal',
        'Subgerente%' => 'Subgerentes',
        'Coordinadora de Sucursal' => 'Coordinadoras de sucursal',
    ],

];
