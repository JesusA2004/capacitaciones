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
    |--------------------------------------------------------------------------
    | Acceso, círculo de sucursal y contacto (decisión de RH, 2026-11-11)
    |--------------------------------------------------------------------------
    |
    | - Acceso: entra quien tiene `directorio.ver` (Roles y permisos) o es
    |   personal de Corporativo. `roles_sin_acceso` son los roles que NO lo
    |   reciben por defecto (gestores = rol colaborador); se cambia desde la
    |   pantalla de roles, no aquí.
    | - Círculo de sucursal: quien no ve región ni toda la empresa (gerente,
    |   subgerente, coordinadora) ve a la gente de su sucursal SIN los
    |   gestores (`grupos_fuera_del_circulo`), más la gerencia regional
    |   (`puestos_regionales`) y su jefe directo.
    | - Contacto: teléfono y correo PERSONALES y correo corporativo. Los
    |   gestores no tienen correo corporativo (`grupos_sin_correo_corporativo`):
    |   nunca se les marca como dato faltante.
    */
    'roles_sin_acceso' => ['colaborador'],

    'grupos_fuera_del_circulo' => ['gestor'],

    'puestos_regionales' => ['Gerente Regional%', 'Subgerente Regional%'],

    'grupos_sin_correo_corporativo' => ['gestor'],

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
        'Subgerente Regional' => 'Gerentes regionales',
        'Coordinadora Regional%' => 'Coordinación regional',
        'Gerente de Sucursal' => 'Gerentes de sucursal',
        'Subgerente%' => 'Subgerentes',
        'Coordinadora de Sucursal' => 'Coordinadoras de sucursal',
    ],

];
