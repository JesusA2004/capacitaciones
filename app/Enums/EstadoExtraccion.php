<?php

namespace App\Enums;

/**
 * Estado de una App\Models\DocumentExtraction (ver docs/DOCUMENT_EXTRACTION.md).
 * "pending" y "processing" son transitorios (el Job los recorre solo);
 * "processed"/"failed" son estados finales del intento automatico;
 * "reviewed" lo pone RH al aceptar/corregir/ignorar o marcar el documento
 * como correcto — nunca lo pone el proceso automatico.
 */
enum EstadoExtraccion: string
{
    case Pendiente = 'pending';
    case Procesando = 'processing';
    case Procesado = 'processed';
    case Fallido = 'failed';
    case Revisado = 'reviewed';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::Procesando => 'Procesando',
            self::Procesado => 'Datos detectados',
            self::Fallido => 'No se pudo leer',
            self::Revisado => 'Revisado por RH',
        };
    }
}
