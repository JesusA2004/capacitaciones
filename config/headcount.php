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
        'Gestor volante' => 'Gestor',
    ],
];
