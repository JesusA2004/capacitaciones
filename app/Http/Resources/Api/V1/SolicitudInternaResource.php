<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\TipoSolicitudInterna;
use App\Models\SolicitudInterna;
use App\Services\Expedientes\CorreccionesService;
use App\Services\Nomina\PrestamoSeguimientoService;
use App\Services\Solicitudes\AprobacionOrganizacionalService;
use App\Services\Solicitudes\SolicitudesService;
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
            'tipo_etiqueta' => app(SolicitudesService::class)->etiquetaTipo($this->resource),
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
            // Permiso: modalidad, goce, causal y si RH ya lo autorizó (solo
            // entonces existe el formato oficial en `permiso_pdf`).
            'permiso' => app(SolicitudesService::class)->resumenPermiso($this->resource),
            'permiso_pdf' => $this->tipo === TipoSolicitudInterna::Permiso ? route('api.v1.solicitudes.permiso-pdf', $this->id, false) : null,
            // «Completar mis datos»: lo propuesto (se aplica al autorizar RH).
            'datos_propuestos' => app(SolicitudesService::class)->comparativoDatos($this->resource),
            // Motor central de aprobaciones (docs/SOLICITUDES_UNIFICADAS.md →
            // «Contrato API del flujo»): pasos ordenados, quién sigue y si
            // quien consulta puede actuar. La app solo pinta esto.
            ...$this->flujoApi($request),
            // Corrección del expediente: estado simple (enviada, en_revision,
            // aprobada, rechazada, requiere_informacion), comentario de RH,
            // si puede responder e historial (docs/SOLICITUDES_UNIFICADAS.md →
            // «Correcciones solicitadas por el colaborador»).
            'correccion' => app(CorreccionesService::class)->esCorreccion($this->resource)
                ? app(CorreccionesService::class)->aArray($this->resource, $request->user())
                : null,
            // Línea de tiempo (solo en el detalle, cuando se cargó).
            'historial' => $this->relationLoaded('historial') ? app(CorreccionesService::class)->historial($this->resource) : null,
        ];
    }

    /**
     * @return array{flujo: list<array<string, mixed>>, puede_actuar_usuario_actual: bool, accion_usuario_actual: string|null, motivo_no_puede: string|null, pendiente_de: array<string, mixed>|null, estado_flujo_texto: string}
     */
    private function flujoApi(Request $request): array
    {
        $flujo = app(AprobacionOrganizacionalService::class)->flujo($this->resource, $request->user());

        return [
            'flujo' => $flujo['pasos'],
            'puede_actuar_usuario_actual' => $flujo['puede_actuar_usuario_actual'],
            'accion_usuario_actual' => $flujo['accion_usuario_actual'],
            'motivo_no_puede' => $flujo['motivo_no_puede'],
            'pendiente_de' => $flujo['pendiente_de'],
            'estado_flujo_texto' => $flujo['estado_texto'],
        ];
    }
}
