<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Directorio de la empresa
    |--------------------------------------------------------------------------
    |
    | Grupos de puesto (puestos.grupo_documental) y nombres de puesto que NO
    | aparecen en el directorio.
    |
    | Propuesta de mejoras Etapa 1 (RH, 2026-10-29): el Directorio debe listar
    | a TODAS las personas activas de la plantilla —incluidos los gestores—,
    | aunque no tengan correo ni teléfono; quien no tenga contacto aparece con
    | la etiqueta «Datos por completar», nunca se oculta. Por eso ambas listas
    | quedan vacías. El mecanismo de exclusión se conserva por si RH decide
    | volver a ocultar algún grupo en el futuro (basta agregar su clave aquí).
    |
    | El contacto corporativo sigue protegido por permiso en
    | DirectorioColaboradoresService (listar a alguien NO revela su contacto).
    |
    */

    'excluir_grupos' => [],

    // Además, por nombre de puesto (p. ej. «Gestor grupal» sin grupo).
    'excluir_puestos' => [],

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
