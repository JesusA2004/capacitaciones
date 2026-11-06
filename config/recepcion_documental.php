<?php

use App\Services\RecepcionDocumental\Reglas\ReglaAltaImss;
use App\Services\RecepcionDocumental\Reglas\ReglaAltas;
use App\Services\RecepcionDocumental\Reglas\ReglaBajas;
use App\Services\RecepcionDocumental\Reglas\ReglaContratoIndeterminado;
use App\Services\RecepcionDocumental\Reglas\ReglaInfonavit;
use App\Services\RecepcionDocumental\Reglas\ReglaListasAsistencia;
use App\Services\RecepcionDocumental\Reglas\ReglaPension;
use App\Services\RecepcionDocumental\Reglas\ReglaPrestamos;
use App\Services\RecepcionDocumental\Reglas\ReglaRecibosNomina;
use App\Services\RecepcionDocumental\Reglas\ReglaVacaciones;

/*
|--------------------------------------------------------------------------
| Recepción documental RH (docs/RECEPCION_DOCUMENTAL_RH.md)
|--------------------------------------------------------------------------
|
| Control MENSUAL de los originales firmados que RH recibe por paquetería.
| Lo esperado NO se captura a mano: cada regla lo calcula desde eventos
| reales del sistema (alta, baja, recibo emitido…). Una regla apagada no
| genera ni toca renglones.
|
| INFONAVIT, pensión alimenticia y listas de asistencia están REGISTRADAS
| pero APAGADAS a propósito: no hay en el sistema evidencia de qué
| documento se firma, en qué evento ni con qué granularidad. RH debe
| definir la regla (y, si aplica, la plantilla) antes de encenderlas.
*/

return [

    'reglas' => [
        'altas' => ['clase' => ReglaAltas::class, 'activa' => (bool) env('RECEPCION_DOC_ALTAS', true)],
        'alta_imss' => ['clase' => ReglaAltaImss::class, 'activa' => (bool) env('RECEPCION_DOC_ALTA_IMSS', true)],
        'contrato_indeterminado' => ['clase' => ReglaContratoIndeterminado::class, 'activa' => (bool) env('RECEPCION_DOC_INDETERMINADO', true)],
        'bajas' => ['clase' => ReglaBajas::class, 'activa' => (bool) env('RECEPCION_DOC_BAJAS', true)],
        'vacaciones' => ['clase' => ReglaVacaciones::class, 'activa' => (bool) env('RECEPCION_DOC_VACACIONES', true)],
        'prestamos' => ['clase' => ReglaPrestamos::class, 'activa' => (bool) env('RECEPCION_DOC_PRESTAMOS', true)],
        'recibos_nomina' => ['clase' => ReglaRecibosNomina::class, 'activa' => (bool) env('RECEPCION_DOC_NOMINA', true)],
        // Pendientes de definir por RH: no generan nada aunque se enciendan.
        'infonavit' => ['clase' => ReglaInfonavit::class, 'activa' => false],
        'pension' => ['clase' => ReglaPension::class, 'activa' => false],
        'listas_asistencia' => ['clase' => ReglaListasAsistencia::class, 'activa' => false],
    ],

    /*
    | Nombre visible de cada tipo documental esperado (clave del documento
    | maestro o clave propia del módulo). Los documentos de baja toman el
    | nombre del catálogo «Documentos requeridos de la baja».
    */
    'tipos' => [
        'contrato_capacitacion' => 'Contrato de capacitación inicial',
        'contrato_confidencialidad' => 'Contrato de confidencialidad',
        'contrato_no_competencia' => 'Acuerdo de no competencia',
        'contrato_periodo_prueba' => 'Contrato de periodo de prueba',
        'contrato_tiempo_determinado' => 'Contrato por tiempo determinado',
        'contrato_indeterminado' => 'Contrato por tiempo indeterminado',
        'alta_imss' => 'Alta IMSS firmada',
        'formato_vacaciones' => 'Formato de vacaciones firmado',
        'prestamo_contrato' => 'Contrato de crédito para trabajadores',
        'prestamo_pagare' => 'Pagaré para trabajadores',
        'prestamo_consentimiento_retencion' => 'Carta consentimiento de retención en nómina',
        'recibo_nomina' => 'Recibo de nómina firmado',
    ],

    /*
    | BAJAS: en recepción solo se esperan los originales que de verdad viajan
    | por paquetería al cerrar una baja: la carta de renuncia firmada (solo en
    | renuncia voluntaria) y el finiquito firmado. Se toman del catálogo
    | «Documentos requeridos de la baja» por su documento_sistema; el resto
    | del catálogo (entrevista de salida, acta con testigos, aviso de
    | rescisión, evidencias de la causal…) sigue pidiéndose en el expediente
    | de la baja, pero ya no en Recepción documental.
    */
    'bajas' => [
        'documentos_sistema' => ['carta_renuncia', 'finiquito'],
    ],

    // Modalidad del paquete de alta cuando el colaborador todavía no tiene
    // contrato inicial registrado (la que usa la empresa hoy).
    'modalidad_alta_por_defecto' => 'capacitacion_inicial',

    /*
    | Fecha del recibo que decide el mes: fecha_pago (DATE, día en que se
    | paga y se firma); sin fecha de pago se usa periodo_fin.
    */
    'nomina' => [
        'columna_fecha' => 'fecha_pago',
    ],

    // Carpeta dentro del disco de expedientes (config('expedientes.disk')).
    'carpeta' => 'RecepcionDocumental',

    // Materialización diaria del corte del mes en curso (routes/console.php).
    'programacion' => [
        'activa' => (bool) env('RECEPCION_DOC_PROGRAMADA', true),
        'hora' => '06:40',
    ],
];
