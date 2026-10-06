<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\TipoSolicitudInterna;
use App\Models\SolicitudInterna;
use App\Services\Nomina\PrestamoSeguimientoService;
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
            'modo_fechas' => $this->tipo->modoFechas()->value,
            // Duración (días naturales) o cuántos días de vacaciones.
            'dias_solicitados' => $this->dias_solicitados,
            // Vacaciones: los días específicos elegidos (fuente real).
            'dias' => $this->tipo === TipoSolicitudInterna::Vacaciones
                ? $this->diasVacaciones->map(fn ($d) => $d->fecha->toDateString())->values()->all()
                : [],
            'motivo' => $this->motivo,
            'observaciones' => $this->observaciones,
            'motivo_rechazo' => $this->motivo_rechazo,
            'revisado_en' => $this->revisado_en?->toIso8601String(),
            'creada_en' => $this->created_at?->toIso8601String(),
            // Préstamo: lo que pidió el colaborador y en qué etapa va (visto
            // bueno → autorización RH → firma). null en cualquier otro tipo.
            'prestamo' => $this->tipo === TipoSolicitudInterna::PrestamoInterno
                ? app(PrestamoSeguimientoService::class)->paraColaborador($this->resource)
                : null,
        ];
    }
}
