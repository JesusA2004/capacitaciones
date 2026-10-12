<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Truncamientos conocidos de sucursal en REPORTE_TRABAJADORES_VAC.xlsx
    |--------------------------------------------------------------------------
    |
    | El reporte real (docs/onboarding/REPORTE_TRABAJADORES_VAC.xlsx, columna
    | SUCURSAL) recorta algunos nombres a 10 caracteres. Este catálogo es
    | CERRADO: solo estos textos exactos (normalizados: mayúsculas, sin
    | acentos) se traducen a la sucursal real del catálogo. Cualquier otro
    | texto debe coincidir EXACTO con el nombre de una sucursal activa — nunca
    | por prefijo ni LIKE (un "CUAUTLA NORTE" jamás debe caer en "Cuautla").
    | Un truncamiento nuevo se agrega aquí, a mano, tras revisarlo.
    |
    */

    'alias_sucursales' => [
        'ATLACOMULC' => 'Atlacomulco',
        'SAN LUIS P' => 'San Luis Potosí',
    ],

];
