<?php

namespace App\Services\DocumentosMaestros\Calidad;

use RuntimeException;

/**
 * El servidor no pudo convertir un PDF en imágenes (motor ausente o que
 * falló). Es un problema de INFRAESTRUCTURA, no un defecto del formato: el
 * QA visual queda pendiente, nunca "fallido".
 */
class RasterizacionFallidaException extends RuntimeException {}
