<?php

namespace App\Services\DocumentosMaestros\Pdf;

use setasign\Fpdi\Fpdi;

/**
 * FPDI con las dos primitivas que el overlay necesita para acomodar un dato
 * largo dentro de su caja sin encimarlo sobre el formato:
 *  - condensar el texto a lo ancho (operador Tz del PDF: misma letra, más
 *    angosta; sigue siendo texto vectorial y seleccionable);
 *  - recortar a un rectángulo (nada se dibuja fuera de la caja).
 */
class FpdiAjustable extends Fpdi
{
    /**
     * Ancho de la letra en porcentaje (100 = normal). Es parte del estado de
     * texto del PDF: se queda puesto hasta que se regrese a 100.
     */
    public function escalaHorizontal(float $porcentaje): void
    {
        $this->_out(sprintf('BT %.2F Tz ET', $porcentaje));
    }

    /**
     * Todo lo que se dibuje hasta finRecorte() se limita a este rectángulo (mm).
     */
    public function recortar(float $x, float $y, float $ancho, float $alto): void
    {
        $this->_out(sprintf('q %.2F %.2F %.2F %.2F re W n', $x * $this->k, ($this->h - $y) * $this->k, $ancho * $this->k, -$alto * $this->k));
    }

    public function finRecorte(): void
    {
        $this->_out('Q');
    }
}
