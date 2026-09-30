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
    | verifica el monto diario vigente y actualízalo aquí (o vía la variable
    | de entorno SALARIO_MINIMO_DIARIO) cuando cambie. Se usa como sueldo
    | mensual por defecto cuando RH guarda «Datos laborales» de un
    | colaborador sin capturar un sueldo (ver
    | Rh\ExpedienteController::actualizarDatosLaborales()) — nunca se deja el
    | sueldo en null.
    |
    */
    'salario_minimo_diario' => (float) env('SALARIO_MINIMO_DIARIO', 278.80),

    'salario_minimo_mensual' => (float) env('SALARIO_MINIMO_DIARIO', 278.80) * 30,

];
