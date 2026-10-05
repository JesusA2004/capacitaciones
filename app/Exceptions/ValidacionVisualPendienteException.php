<?php

namespace App\Exceptions;

use App\Models\DocumentTemplate;

/**
 * 422 DOCUMENT_VISUAL_VALIDATION_FAILED: la versión activa del formato no
 * pasó (o no ha corrido) el QA visual ORIGINAL vs GENERADO, o fue validada
 * con un conversor distinto al disponible. No se emite el documento oficial.
 */
class ValidacionVisualPendienteException extends DocumentoMotorException
{
    public static function para(DocumentTemplate $master, ?string $razon = null): self
    {
        return new self(
            'La versión del formato no está validada para generar documentos oficiales.',
            array_filter([
                'documento' => $master->nombre,
                'version' => $master->version,
                'estado' => $master->visual_validation_status->value,
                'razon' => $razon,
            ], fn (mixed $v): bool => $v !== null),
        );
    }

    public function codigo(): string
    {
        return 'DOCUMENT_VISUAL_VALIDATION_FAILED';
    }
}
