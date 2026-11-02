<?php

return [

    // Motor del PDF de finiquito: 'oficial' (por defecto) = formato oficial de RH
    // (docs/formatosRH/Formato_Finiquito.docx) como documento administrativo;
    // 'legado' = plantilla de Jurídico / overlay anterior si están configurados.
    'motor' => env('FINIQUITO_MOTOR', 'oficial'),

    /*
    |--------------------------------------------------------------------------
    | Porcentajes y reglas del cálculo de finiquito (México, LFT)
    |--------------------------------------------------------------------------
    |
    | Valores de partida configurables, no una fórmula legal definitiva: el
    | cálculo se muestra siempre como editable y sujeto a validación de RH/
    | contabilidad (ver App\Services\Finiquitos\FiniquitoService). Esta
    | pantalla no reemplaza una revisión legal/contable real.
    |
    */

    // Mínimo de ley: 25% sobre los días de vacaciones pendientes.
    'prima_vacacional_porcentaje' => 25,

    // Mínimo de ley: 15 días de aguinaldo anual.
    'dias_aguinaldo' => 15,

    // En PEOPLE no existen liquidaciones: el finiquito nunca calcula
    // indemnización ni gratificación (tampoco en bajas por bajo desempeño o
    // por una causal grave, que se sustentan con actas).

    // Catálogo de conceptos adicionales que RH puede capturar manualmente
    // en "otros_conceptos" (clave => etiqueta), además de bonos/descuentos/
    // adeudos ya capturados como columnas propias.
    'conceptos_extra' => [],

    // Candado interno, NO un paso de autorización: la baja no tiene
    // autorización de RH (la aplica de inmediato el jefe directo o RH). La
    // solicitud interna que respalda el cierre solo se marca «aprobada» al
    // EJECUTAR la baja (registrar la entrega del finiquito); si es true, eso
    // exige un finiquito ya revisado/aprobado/firmado/entregado (excepto con
    // el permiso solicitudes.bajas.omitir_finiquito, reservado a
    // super_admin). La clave conserva su nombre histórico.
    'exigir_finiquito_revisado_para_aprobar_baja' => true,

];
