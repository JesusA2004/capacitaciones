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
        // Confirmado (config/expedientes.php y SucursalSeeder: ciudad de Tula).
        'tula de allende' => 'Tula',
    ],

    // Unidades de negocio FUERA de People cuyas bajas sí entran en la
    // estadística de rotación (Reporte de Rotación de Personal 2026 de RH:
    // «unidades fuera de Mr. Lana»). Nombre normalizado => nombre a mostrar y
    // grupo (unidad de negocio). Sus bajas históricas se guardan con
    // sucursal_id nulo y `unidad_externa`; NO se crean sucursales ni empresas,
    // así que no tocan headcount, vacantes, organigrama, matriz comercial,
    // alcances ni el menú. Solo las ve quien tiene alcance global en Rotación.
    // Un nombre que no esté aquí ni en el catálogo de sucursales es un error.
    'unidades_externas' => [
        'itzamarketing' => ['nombre' => 'Itzamarketing', 'grupo' => 'Itzamarketing'],
        'zen japones' => ['nombre' => 'Zen Japonés', 'grupo' => 'Lerom (restaurantes)'],
    ],

    // Histórico individual de bajas 2026 (anexo del «Reporte de Rotación de
    // Personal 2026», docs/rotacionNuevo/). Lo carga
    // `php artisan rotacion:cargar-historico`; ver docs/ROTACION.md.
    'historico_2026' => [
        'archivo' => 'claude/rotacion/bajas_historicas_2026.csv',
        'esperado' => 'claude/rotacion/bajas_historicas_2026.esperado.json',
    ],

    // Homologación inicial: clave de causal de baja => categoría analítica
    // (causales_analiticas.clave). Solo se aplica a causales SIN mapeo; un
    // cambio posterior se hace desde Rotación › Causales y queda auditado.
    // Ver docs/ROTACION.md.
    'homologacion_inicial' => [
        'RV-01' => 'oferta_laboral',
        'RV-02' => 'no_le_gusto',
        'RV-03' => 'no_le_gusto',
        'RV-04' => 'no_le_gusto',
        'RV-05' => 'personales_familiares',
        'RV-06' => 'renuncia_voluntaria',
        'NR-01' => 'bajo_desempeno',
        'NR-02' => 'bajo_desempeno',
        'NR-03' => 'probidad_conducta',
        'NR-04' => 'estructural',
        'NR-05' => 'estructural',
        'NR-06' => 'estructural',
        'RE-01' => 'abandono_faltas',
        'RE-02' => 'desfases_cartera',
        'RE-03' => 'probidad_conducta',
        'RE-04' => 'probidad_conducta',
        'RE-05' => 'probidad_conducta',
        'OT-01' => 'otras_terminaciones',
        'OT-02' => 'otras_terminaciones',
        'OT-03' => 'otras_terminaciones',
    ],

    // Umbrales (%) a partir de los cuales Rotación muestra un hallazgo.
    'hallazgos' => [
        'oferta_minimo_pct' => 15,
        'temprana_minimo_pct' => 30,
    ],

];
