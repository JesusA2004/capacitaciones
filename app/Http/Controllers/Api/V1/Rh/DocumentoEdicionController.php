<?php

namespace App\Http\Controllers\Api\V1\Rh;

use App\Http\Controllers\Rh\DocumentoEdicionController as DocumentoEdicionWebController;

/**
 * Espejo móvil: mismo controlador, mismo servicio y mismas Policies que la web.
 */
class DocumentoEdicionController extends DocumentoEdicionWebController {}
