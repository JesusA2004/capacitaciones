<?php

return [

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

    // Si está deshabilitada, indemnizacion() siempre regresa 0 (por ejemplo,
    // cuando la política de la empresa es no indemnizar en renuncia
    // voluntaria — ver FiniquitoService::calcularIndemnizacion()).
    'indemnizacion_habilitada' => true,

    // Días de sueldo por año de antigüedad usados para estimar la
    // indemnización en bajas por despido — ajustable por admin, no es un
    // mínimo de ley fijo (varía por tipo de baja/negociación).
    'dias_indemnizacion_por_anio' => 12,

    // Catálogo de conceptos adicionales que RH puede capturar manualmente
    // en "otros_conceptos" (clave => etiqueta), además de bonos/descuentos/
    // adeudos ya capturados como columnas propias.
    'conceptos_extra' => [],

    // Si es true, una solicitud de baja no puede aprobarse sin un cálculo de
    // finiquito en estado "revisado" (excepto quien tenga el permiso
    // solicitudes.bajas.omitir_finiquito, reservado a super_admin).
    'exigir_finiquito_revisado_para_aprobar_baja' => true,

];
