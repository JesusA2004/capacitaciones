<?php

namespace App\Http\Resources\Api\Integracion\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Base de los recursos de /api/integracion/v1: segunda lista blanca. Aunque
 * el servicio agregara una clave nueva, sólo salen las de campos(); así un
 * dato sensible nunca se filtra por descuido.
 *
 * @property array<string, mixed> $resource
 */
abstract class RecursoIntegracion extends JsonResource
{
    /**
     * @return list<string>
     */
    abstract protected function campos(Request $request): array;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $datos = [];

        foreach ($this->campos($request) as $campo) {
            $datos[$campo] = $this->resource[$campo] ?? null;
        }

        return $datos;
    }
}
