<?php

namespace App\Services\Pdf;

/**
 * Opciones de página que el motor necesita además del HTML (el resto vive
 * en el CSS @page del propio HTML).
 */
final class OpcionesPdf
{
    public function __construct(
        public readonly string $tamano = 'letter',
        public readonly string $orientacion = 'portrait',
        /** Número de página «Página X de Y» al pie. */
        public readonly bool $numerarPaginas = false,
        /** Texto del pie que el motor dibuja en su margen (Chrome). */
        public readonly string $textoPie = '',
        public readonly float $distanciaPieMm = 8.0,
        public readonly string $colorPie = '#6b7280',
        public readonly float $margenInferiorMm = 20.0,
    ) {}
}
