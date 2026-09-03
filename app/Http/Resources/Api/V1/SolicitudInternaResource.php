<?php

namespace App\Http\Resources\Api\V1;

use App\Models\SolicitudInterna;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SolicitudInterna
 */
class SolicitudInternaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'folio' => $this->folio,
            'tipo' => $this->tipo->value,
            'tipo_etiqueta' => $this->tipo->etiqueta(),
            'estado' => $this->estado->value,
            'estado_etiqueta' => $this->estado->etiqueta(),
            'fecha_inicio' => $this->fecha_inicio?->toDateString(),
            'fecha_fin' => $this->fecha_fin?->toDateString(),
            'motivo' => $this->motivo,
            'observaciones' => $this->observaciones,
            'motivo_rechazo' => $this->motivo_rechazo,
            'revisado_en' => $this->revisado_en?->toIso8601String(),
            'creada_en' => $this->created_at?->toIso8601String(),
        ];
    }
}
