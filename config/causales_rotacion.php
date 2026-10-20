<?php

/*
|--------------------------------------------------------------------------
| Causales de rotación (subcatálogo del tipo de baja)
|--------------------------------------------------------------------------
|
| Una baja tiene SOLO tres tipos (App\Enums\TipoBaja): renuncia, rescisión y
| fin de contrato. El desglose («motivo») es la causal de rotación, que RH
| administra desde Bajas y finiquitos → «Causales de rotación» (tabla
| causales_rotacion, App\Services\CierreLaboral\CausalRotacionService).
|
| Aquí solo vive el catálogo INICIAL (CausalesRotacionSeeder lo carga sin
| pisar lo que RH ya editó) y el mapeo de las causas anteriores a tipo +
| causal (migración 2026_10_20_090000_causales_rotacion_y_tres_tipos_de_baja).
|
| `clave` es estable: el sistema la usa para elegir la causal automática de
| la no renovación desde la evaluación del periodo de prueba
| (`no_acredita_periodo_prueba`). `excluye_indemnizacion`: el finiquito no
| estima indemnización para esa causal aunque el tipo la contemple (p. ej.
| abandono de trabajo), igual que antes con la causa «abandono».
|
*/

return [

    'iniciales' => [
        'renuncia' => [
            'renuncia_voluntaria' => ['nombre' => 'Renuncia voluntaria'],
            'mejor_oferta' => ['nombre' => 'Mejor oferta laboral'],
            'cambio_residencia' => ['nombre' => 'Cambio de residencia'],
            'motivos_personales' => ['nombre' => 'Motivos personales o familiares'],
            'motivos_salud' => ['nombre' => 'Motivos de salud'],
            'estudios' => ['nombre' => 'Estudios'],
            'inconformidad_condiciones' => ['nombre' => 'Inconformidad con condiciones laborales'],
            'mutuo_acuerdo' => ['nombre' => 'Mutuo acuerdo'],
        ],
        'rescision' => [
            'bajo_desempeno' => ['nombre' => 'Bajo desempeño'],
            'abandono_trabajo' => ['nombre' => 'Abandono de trabajo', 'excluye_indemnizacion' => true],
            'falta_probidad' => ['nombre' => 'Falta de probidad u honradez'],
            'faltas_injustificadas' => ['nombre' => 'Faltas injustificadas (más de 3 en 30 días)'],
            'desobediencia' => ['nombre' => 'Desobediencia injustificada'],
            'incumplimiento_reglamento' => ['nombre' => 'Incumplimiento del reglamento o políticas internas'],
            'otra_art_47' => ['nombre' => 'Otra causal del art. 47 LFT'],
        ],
        'fin_contrato' => [
            'no_acredita_periodo_prueba' => ['nombre' => 'No acredita periodo de prueba / capacitación inicial'],
            'termino_contrato_determinado' => ['nombre' => 'Término de contrato por tiempo determinado'],
            'conclusion_obra' => ['nombre' => 'Conclusión de obra o proyecto'],
        ],
    ],

    // Causal automática de la no renovación decidida en la evaluación del
    // periodo de prueba (EvaluacionPeriodoPruebaService).
    'clave_no_renovacion' => 'no_acredita_periodo_prueba',

    /*
    | Causas anteriores (valores viejos de tipo_baja) → tipo + clave de
    | causal. El valor original se conserva en tipo_baja_original.
    */
    'legado' => [
        'renuncia' => ['tipo' => 'renuncia', 'causal' => 'renuncia_voluntaria'],
        'mutuo_acuerdo' => ['tipo' => 'renuncia', 'causal' => 'mutuo_acuerdo'],
        'despido' => ['tipo' => 'rescision', 'causal' => 'otra_art_47'],
        'baja_inmediata' => ['tipo' => 'rescision', 'causal' => 'otra_art_47'],
        'bajo_desempeno' => ['tipo' => 'rescision', 'causal' => 'bajo_desempeno'],
        'abandono' => ['tipo' => 'rescision', 'causal' => 'abandono_trabajo'],
        'no_renovacion' => ['tipo' => 'fin_contrato', 'causal' => 'no_acredita_periodo_prueba'],
        'fin_contrato' => ['tipo' => 'fin_contrato', 'causal' => 'termino_contrato_determinado'],
        // «Otro» no dice nada: se clasifica como rescisión SIN causal (queda
        // visible para que RH la reclasifique); la migración lo reporta.
        'otro' => ['tipo' => 'rescision', 'causal' => null],
    ],

];
