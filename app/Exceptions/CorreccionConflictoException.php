<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * El dato cambió desde que el usuario lo abrió: responde 409 con la huella y
 * los valores vigentes que ESE usuario ya tiene permitido ver, para que los
 * revise antes de reintentar.
 */
class CorreccionConflictoException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $actual  Salida de CorreccionService::describir().
     */
    public function __construct(private readonly array $actual, string $mensaje = 'Otra persona cambió este dato mientras lo corregías. Revisa el valor vigente e intenta de nuevo.')
    {
        parent::__construct($mensaje);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'actual' => $this->actual], 409);
    }
}
