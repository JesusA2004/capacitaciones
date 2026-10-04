<?php

namespace App\Http\Controllers\Api\V1\Rh;

use App\Http\Controllers\Rh\DocumentoProcesoController as DocumentoProcesoWebController;

/**
 * API móvil de "Documentos del proceso": exactamente las mismas acciones y
 * el mismo payload que la web (mismo DocumentoProcesoService, mismas
 * reglas). La app solo renderiza lo que el backend devuelve; nunca decide
 * qué documento le toca a una persona. Ver docs/API_MOVIL.md.
 */
class DocumentoProcesoController extends DocumentoProcesoWebController {}
