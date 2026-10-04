<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * No hay documento maestro activo para lo que el flujo pide (p. ej.
 * "Contrato de capacitación para Subgerente"). Nunca se elige otro en
 * silencio: se responde 422 DOCUMENT_TEMPLATE_MISSING explicando qué falta.
 *
 * Extiende ValidationException para que los flujos que ya convierten la
 * falta de plantilla en tarea pendiente (contratos, cierres) sigan
 * funcionando igual.
 */
class DocumentoMaestroFaltanteException extends ValidationException
{
    /**
     * @param  array{documento: string, clave: string, puesto: string|null, grupo: string|null, empresa: string|null, proceso: string|null}  $detalle
     */
    public function __construct(public readonly array $detalle)
    {
        $mensaje = sprintf(
            'No está cargado el formato de %s%s.',
            $detalle['documento'],
            $detalle['grupo'] !== null ? ' para '.$detalle['grupo'] : ($detalle['puesto'] !== null ? ' para '.$detalle['puesto'] : ''),
        );

        $validador = validator([], []);
        $validador->errors()->add('documento', $mensaje);

        parent::__construct($validador);
        $this->status = 422;
    }

    public function mensaje(): string
    {
        return (string) $this->validator->errors()->first('documento');
    }

    public function render(Request $request): ?JsonResponse
    {
        if (! $request->expectsJson()) {
            return null;
        }

        return response()->json([
            'code' => 'DOCUMENT_TEMPLATE_MISSING',
            'message' => $this->mensaje(),
            'detalle' => $this->detalle,
            'errors' => $this->errors(),
        ], 422);
    }
}
