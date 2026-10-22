<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Filas por página de los listados
    |--------------------------------------------------------------------------
    |
    | Opciones que el usuario puede elegir en el pie de cualquier tabla
    | («Mostrar [20] por página»). Viajan en la URL como `por_pagina`
    | (10 · 20 · 30 · 40 · 50 · todos). Ver App\Support\Paginacion.
    |
    */

    'opciones' => [10, 20, 30, 40, 50],

    /*
    | «Todos» trae todas las filas pero con un tope de seguridad, para que una
    | tabla enorme no tumbe el servidor ni el navegador. Si el listado tiene
    | más filas que este tope, la UI avisa que solo se muestran las primeras.
    */
    'todos_maximo' => (int) env('PAGINACION_TODOS_MAXIMO', 2000),

];
