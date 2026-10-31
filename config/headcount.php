<?php

/*
|--------------------------------------------------------------------------
| Headcount y vacantes
|--------------------------------------------------------------------------
|
| Puestos que para PLANTILLA y VACANTES cuentan como otro puesto. RH
| contrata "un gestor": entra como volante y después toma su ruta, pero es
| la misma plaza — no se abren ni se muestran vacantes separadas de
| volante (docs/HEADCOUNT_Y_VACANTES.md). El organigrama sí los distingue
| (el gestor tiene ruta, el volante no).
|
| Nombres exactos de App\Models\Puesto (PuestoJerarquiaSeeder).
|
*/

return [
    'puestos_equivalentes' => [
        'Gestor Volante' => 'Gestor',
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
