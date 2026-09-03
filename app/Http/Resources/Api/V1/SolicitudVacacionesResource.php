<?php

namespace App\Http\Resources\Api\V1;

use App\Models\SolicitudVacaciones;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SolicitudVacaciones
 */
class SolicitudVacacionesResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fecha_inicio' => $this->fecha_inicio->toDateString(),
            'fecha_fin' => $this->fecha_fin->toDateString(),
            'dias_solicitados' => $this->dias_solicitados,
            'comentario' => $this->comentario,
            'estado' => $this->estado->value,
            'estado_etiqueta' => $this->estado->etiqueta(),
            'motivo_rechazo' => $this->motivo_rechazo,
            'creada_en' => $this->created_at?->toIso8601String(),
        ];
    }
}
