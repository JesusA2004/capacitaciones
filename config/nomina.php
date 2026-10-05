<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Disco de almacenamiento de recibos de nómina
    |--------------------------------------------------------------------------
    |
    | Disco registrado en config/filesystems.php usado por
    | App\Services\Nomina\ReciboNominaService para guardar el PDF de cada
    | recibo. Nunca 'local' en producción: el VPS no es el lugar para
    | persistir documentos reales — mismo criterio que config/expedientes.php.
    |
    */
    'disk' => env('NOMINA_DISK', 'nas'),

    /*
    |--------------------------------------------------------------------------
    | Salario mínimo vigente (México, zona general)
    |--------------------------------------------------------------------------
    |
    | CONASAMI lo publica cada 1° de enero (https://www.gob.mx/conasami) —
    | un monto legal cambia cada año y NO tiene fallback hardcodeado aquí:
    | un valor viejo aplicado en silencio es un riesgo real, no un detalle
    | cosmético. Debe venir de la variable de entorno SALARIO_MINIMO_DIARIO
    | (ver .env.example); si no está definida, ambas quedan en null.
    |
    | Se usa como sueldo mensual por defecto SOLO cuando RH guarda «Datos
    | laborales» de un colaborador que nunca tuvo un sueldo capturado (ver
    | Rh\ExpedienteController::actualizarDatosLaborales()) — nunca sobrescribe
    | un sueldo que ya existe, y si esta variable falta, no inventa un monto:
    | se registra un aviso y el colaborador queda sin sueldo hasta que RH lo
    | capture a mano o alguien configure la variable de entorno.
    |
    */
    'salario_minimo_diario' => env('SALARIO_MINIMO_DIARIO') !== null
        ? (float) env('SALARIO_MINIMO_DIARIO')
        : null,

    'salario_minimo_mensual' => env('SALARIO_MINIMO_DIARIO') !== null
        ? (float) env('SALARIO_MINIMO_DIARIO') * 30
        : null,

    /*
    |--------------------------------------------------------------------------
    | Recibos quincenales automáticos (docs/NOMINA_QUINCENAL.md)
    |--------------------------------------------------------------------------
    |
    | Quincenas: del 1 al 15 (pago el 15) y del 16 al último día del mes
    | (pago el último día). `dias_anticipacion` días antes del pago el
    | comando `nomina:procesar-quincenas` prepara los recibos de todos los
    | colaboradores activos con sueldo como BORRADOR (RH los ajusta); en la
    | fecha de pago los EMITE solo (PDF + aviso). Con `emision_automatica`
    | en false solo se preparan y RH emite a mano desde «Recibos de nómina».
    |
    */
    'quincenal' => [
        'emision_automatica' => (bool) env('NOMINA_EMISION_AUTOMATICA', true),
        'dias_anticipacion' => (int) env('NOMINA_DIAS_ANTICIPACION', 3),
        'concepto_sueldo' => 'Sueldo quincenal',
    ],

];
