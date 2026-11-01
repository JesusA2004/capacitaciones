<?php

namespace App\Http\Controllers\Api\V1\Rh;

use App\Http\Controllers\Correcciones\CorreccionController as CorreccionWebController;

/**
 * Espejo móvil: mismo servicio y misma respuesta. Salario y recibos quedan fuera
 * (config/correcciones.php, api => false): responden 404.
 */
class CorreccionController extends CorreccionWebController {}
