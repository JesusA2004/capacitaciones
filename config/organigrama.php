<?php

/*
|--------------------------------------------------------------------------
| Organigrama por personas
|--------------------------------------------------------------------------
|
| El organigrama "por personas" (App\Services\Organigrama\OrganigramaPersonasService)
| arma una tarjeta por colaborador siguiendo el árbol de puestos. Estos
| puestos, y todos los que cuelgan de ellos, son "de sucursal": cada
| sucursal tiene su propia rama (Gerente de Sucursal → Subgerente → Gestor →
| Volante) y un colaborador solo cuelga de alguien de SU sucursal. El resto
| (dirección, gerencias corporativas, regionales, sistemas, RH, mesa de
| control…) es corporativo y no se parte por sucursal.
|
| Son los nombres que siembra database/seeders/PuestoJerarquiaSeeder.php.
|
*/

return [
    'puestos_raiz_sucursal' => ['Gerente de Sucursal', 'Coordinadora de Sucursal'],

    // Puestos "de región" ADEMÁS de los que la matriz comercial liga a una
    // región (nodos_comerciales.puesto_id: Región Q1 → "Gerente Regional
    // Q1", Región Q3 → "Gerente Regional Q3"). El gerente de una sucursal
    // cuelga del puesto regional de SU región; si nadie lo ocupa, se ve
    // quien lo cubre (CoberturaPuesto) o VACANTE. "Gerente regional" es el
    // puesto genérico anterior (solo mientras alguien lo siga ocupando).
    'puestos_de_region' => ['Gerente regional'],

    /*
    | Mini-organigramas (inducción capítulo 4 y pantalla de Organigrama):
    | slug => nombre EXACTO del puesto raíz en el catálogo real (seeders
    | PuestoJerarquiaSeeder). MiniOrganigramaService::areas() ignora en
    | silencio cualquier entrada cuyo nombre ya no exista — nunca inventa un
    | puesto. Un área real sin puesto dedicado (p. ej. Jurídico Empresarial,
    | que en el catálogo actual es el puesto "Abogado") usa el nombre real
    | del puesto, no el nombre del área del PDF de inducción.
    */
    'areas' => [
        'direccion_comercial' => 'Dirección Comercial',
        'recursos_humanos' => 'Gerencia de Recursos Humanos',
        'contraloria' => 'Gerente de Contraloría',
        'sistemas' => 'Responsable de Sistemas',
        'juridico_empresarial' => 'Abogado',
        'mesa_de_control' => 'Gerente de Mesa de Control',
        'sucursal' => 'Gerente de Sucursal',
    ],

    // Capítulo 4 de Inducción Mr. Lana (InduccionMrLanaModuloSeeder, bloque
    // 'organigrama_dinamico'): las 3 vistas del PDF rediseño, resueltas al
    // puesto raíz real — nunca una imagen fija.
    'vistas_induccion' => [
        'corporativo' => 'Dirección General',
        'comercial' => 'Dirección Comercial',
        'sucursal' => 'Gerente de Sucursal',
    ],
];
