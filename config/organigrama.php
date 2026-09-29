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
];
