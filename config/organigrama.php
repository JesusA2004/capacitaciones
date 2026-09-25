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
    'puestos_raiz_sucursal' => ['Gerente de Sucursal', 'Coordinadora'],

    // Un ocupante por región de la matriz comercial (Q1, Q3…): una sucursal
    // cuelga del de SU región. Si una región no tiene titular, se muestra
    // quien la cubre (CoberturaPuesto) o "sin ocupar".
    'puestos_de_region' => ['Gerente regional'],
];
