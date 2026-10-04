<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * El documento pide datos que PEOPLE no tiene (estado civil, nacionalidad,
 * testigos…): no se genera nada a medias. 422 DATOS_FALTANTES con la lista
 * exacta de lo que falta y dónde se completa, para que la UI muestre
 * "Faltan N datos requeridos" y pida SOLO eso.
 */
class DatosDocumentoFaltantesException extends ValidationException
{
    /**
     * @param  list<array{campo: string, base: string, fuente: string, columna: string, etiqueta: string, tipo: string, editable: bool}>  $faltantes
     */
    public function __construct(public readonly string $documento, public readonly array $faltantes)
    {
        $validador = validator([], []);
        $validador->errors()->add('datos', sprintf(
            '%s: faltan %d dato(s) requerido(s): %s.',
            $documento,
            count($faltantes),
            implode(', ', array_map(fn (array $f): string => $f['etiqueta'], $faltantes)),
        ));

        parent::__construct($validador);
        $this->status = 422;
    }

    public function render(Request $request): ?JsonResponse
    {
        if (! $request->expectsJson()) {
            return null;
        }

        return response()->json([
            'code' => 'DATOS_FALTANTES',
            'message' => sprintf('Faltan %d dato(s) requerido(s) para generar «%s».', count($this->faltantes), $this->documento),
            'documento' => $this->documento,
            'faltantes' => $this->faltantes,
            'errors' => $this->errors(),
        ], 422);
    }
}
