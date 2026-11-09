<?php

/*
|--------------------------------------------------------------------------
| Headcount y vacantes
|--------------------------------------------------------------------------
|
| Ver docs/HEADCOUNT_Y_VACANTES.md. Nombres exactos de App\Models\Puesto
| (PuestoJerarquiaSeeder).
|
*/

return [
    /*
    | Puestos que para PLANTILLA y VACANTES cuentan como otro puesto (misma
    | plaza, sin vacantes propias). Vacío desde 2026-11-09: el Gestor
    | volante dejó de ser «la misma plaza que Gestor» y tiene sus propias
    | plazas y vacantes (ver plazas_compartidas).
    */
    'puestos_equivalentes' => [],

    /*
    | Puestos con plazas PROPIAS que se prestan lugar entre sí: cada persona
    | ocupa primero una plaza de su puesto y, si ya no hay libre, una plaza
    | libre del otro puesto de la pareja en la misma sucursal. Así un volante
    | sin plaza de volante ocupa una plaza de Gestor (y al revés) y nada que
    | ya estaba ocupado pasa a vacante o a «fuera de plantilla» por haber
    | separado las plazas (App\Services\Headcount\PuestosPlantillaService).
    */
    'plazas_compartidas' => [
        'Gestor Volante' => 'Gestor',
    ],

    /*
    | Puesto cuyas plazas captura el botón «¿Esta sucursal tendrá volantes?»
    | (Administración → Sucursales).
    */
    'puesto_volante' => 'Gestor Volante',

    /*
    | Cómo se nombra el puesto en Vacantes (web, API, resumen, Excel y PDF) y
    | la aclaración que lo acompaña. El catálogo de puestos no cambia.
    */
    'etiquetas_vacante' => [
        'Gestor' => 'Gestor fijo',
        'Gestor Volante' => 'Gestor volante',
    ],

    'notas_vacante' => [
        'Gestor' => 'Con ruta asignada: la ruta se define en Matriz comercial.',
        'Gestor Volante' => 'Sin ruta fija: cubre faltas y apoya la operación de la sucursal.',
    ],

    /*
    | Puestos de empresa que nunca reciben plaza ni vacante automática
    | (App\Services\Headcount\PlazasEmpresaService).
    */
    'puestos_sin_plaza_automatica' => ['Dirección General'],

    /*
    | Nombre de sucursal en el Excel de headcount => nombre EXACTO en el
    | catálogo. Lista cerrada, solo alias confirmados por el negocio (el
    | mismo que config/expedientes.php migracion_inicial.alias_sucursales);
    | cualquier otro nombre sin coincidencia exacta se reporta como
    | pendiente, nunca se aproxima.
    */
    'alias_sucursales' => [
        'TULA DE ALLENDE' => 'Tula',
    ],
];
