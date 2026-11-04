<?php

namespace App\Services\Incorporacion\SolicitudEmpleo;

use App\Exceptions\DatosDocumentoFaltantesException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La Solicitud de empleo / el Estudio socioeconómico no tiene todos sus
 * datos: 422 DATOS_FALTANTES (mismo código que el motor documental) con la
 * lista EXACTA en español. Nunca se emite un PDF incompleto.
 */
class FaltanDatosIngresoException extends DatosDocumentoFaltantesException
{
    /**
     * @param  list<string>  $etiquetas
     */
    public function __construct(string $documento, public readonly array $etiquetas, public readonly string $mensaje)
    {
        parent::__construct($documento, array_map(fn (string $etiqueta): array => [
            'campo' => $etiqueta,
            'base' => $etiqueta,
            'fuente' => 'incorporacion',
            'columna' => '',
            'etiqueta' => $etiqueta,
            'tipo' => 'texto',
            'editable' => false,
        ], $etiquetas));
    }

    public function render(Request $request): ?JsonResponse
    {
        if (! $request->expectsJson()) {
            return null;
        }

        return response()->json([
            'code' => 'DATOS_FALTANTES',
            'message' => $this->mensaje,
            'documento' => $this->documento,
            'detalle' => ['documento' => $this->documento, 'completar_en' => 'solicitud_empleo'],
            'faltantes' => $this->faltantes,
            'errors' => ['datos' => [$this->mensaje]],
        ], 422);
    }
}
