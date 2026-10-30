<?php

use App\Enums\ReglaDiasPagados;

/*
|--------------------------------------------------------------------------
| Efecto de las incidencias en el recibo simple de pago
|--------------------------------------------------------------------------
|
| Regla de negocio versionada. PENDIENTE DE VALIDAR POR RH: la propuesta de
| Etapa 1 pide descontar los días de incapacidad del recibo, pero no define el
| prorrateo contra la base salarial ni si la empresa paga algún día. Mientras
| `validada_por_rh` sea false, las pantallas lo avisan.
|
| `regla`: ver App\Enums\ReglaDiasPagados. Por defecto la que el sistema ya
| aplicaba al ingreso a media quincena (LoteNominaService, NominaQuincenalService).
|
| `incapacidad_pagada_por_empresa`: tipos cuyos días la empresa sí paga. Vacío:
| ningún día se paga en el recibo; el subsidio del IMSS es ajeno a este recibo.
|
| `permiso_especial_con_goce`: los permisos especiales (cumpleaños, paternidad,
| luto…) se tratan como pagados.
*/

return [
    'regla' => ReglaDiasPagados::DiasCalendarioConTope->value,
    'validada_por_rh' => false,
    'incapacidad_pagada_por_empresa' => [],
    'permiso_especial_con_goce' => true,
];
