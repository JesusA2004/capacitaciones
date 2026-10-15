<?php

/*
|--------------------------------------------------------------------------
| Rotación de personal (Excel de RH)
|--------------------------------------------------------------------------
|
| Carga del «INDICE DE ROTACIÓN DE PERSONAL» (formato real en
| docs/rotacion/ y claude/rotacion/). Ver docs/REPORTES_RH.md
| § «Rotación de personal (carga de Excel)».
|
*/

return [

    // Disco y carpeta donde se guarda cada Excel original subido.
    'disk' => env('ROTACION_DISK', 'nas'),
    'prefijo' => 'rotacion',

    // Tamaño máximo del archivo (KB).
    'max_kb' => 10240,

    // Meses que muestra la gráfica y la tabla de la pantalla.
    'meses_ventana' => 12,

    // Diferencia (en puntos porcentuales) a partir de la cual el índice que
    // trae la celda ROTACIÓN del Excel se reporta como distinto del
    // recalculado (bajas × 100 / plantilla final).
    'tolerancia_indice' => 0.05,

    // Nombre del Excel (normalizado: minúsculas, sin acentos) => nombre o
    // clave EXACTA de la sucursal en el catálogo. Solo para nombres que el
    // negocio confirmó; cualquier otro nombre que no coincida se reporta.
    'alias_sucursales' => [
        // 'san luis' => 'SLP01',
    ],

];
