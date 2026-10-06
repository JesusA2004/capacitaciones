<?php

namespace App\Services\Pdf;

use RuntimeException;

/**
 * El motor de PDF no está disponible (falta Chrome/node/puppeteer) o falló
 * al imprimir. El mensaje es legible para RH; el detalle técnico va al log.
 */
class PdfRendererException extends RuntimeException {}
