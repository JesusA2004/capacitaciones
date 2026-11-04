<?php

namespace App\Http\Controllers\Api\V1\Rh;

use App\Http\Controllers\Rh\SolicitudEmpleoIngresoController as SolicitudEmpleoIngresoWebController;

/**
 * Mismo controlador (y mismo servicio) que la web de RH: la app de RH ve y
 * completa la solicitud de empleo con las mismas reglas.
 */
class SolicitudEmpleoIngresoController extends SolicitudEmpleoIngresoWebController {}
