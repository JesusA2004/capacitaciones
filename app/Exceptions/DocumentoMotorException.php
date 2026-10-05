<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Problema ESPERABLE del motor documental que impide emitir un documento
 * oficial (no hay conversor fiel, la versión del formato no pasó el QA
 * visual, un dato no cabe en su campo). Siempre 422 con un `code` estable
 * para que web y app muestren el mensaje correcto; nunca un 500.
 *
 * Extiende ValidationException para que los flujos que ya convierten un
 * 422 en tarea pendiente (contratos, cierres) sigan funcionando igual.
 */
abstract class DocumentoMotorException extends ValidationException
{
    /**
     * @param  array<string, mixed>  $detalle
     */
    public function __construct(public readonly string $mensajeUsuario, public readonly array $detalle = [])
    {
        $validador = validator([], []);
        $validador->errors()->add('documento', $mensajeUsuario);

        parent::__construct($validador);
        $this->status = 422;
    }

    abstract public function codigo(): string;

    public function render(Request $request): ?JsonResponse
    {
        if (! $request->expectsJson()) {
            return null;
        }

        return response()->json([
            'code' => $this->codigo(),
            'message' => $this->mensajeUsuario,
            'detalle' => $this->detalle,
            'errors' => $this->errors(),
        ], 422);
    }
}
