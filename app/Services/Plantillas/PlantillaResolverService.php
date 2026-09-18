<?php

namespace App\Services\Plantillas;

use App\Enums\TipoPlantillaDocumento;
use App\Models\Colaborador;
use App\Models\DocumentTemplate;

/**
 * Resuelve automáticamente qué DocumentTemplate aplica a un colaborador para
 * un tipo dado (p. ej. "Contrato laboral — Gestores.docx" para todo el
 * departamento Gestores, sin que RH tenga que cargar la misma plantilla una
 * vez por cada puesto). Prioridad de más a menos específico: puesto,
 * departamento, sucursal, empresa, global (sin ninguna asignada) — el
 * primer nivel con una plantilla activa gana. Solo sugiere: RH siempre
 * puede cambiarla manualmente en el diálogo de generación (ver
 * Rh\FormatoController::store()).
 */
class PlantillaResolverService
{
    public function resolverPara(Colaborador $colaborador, TipoPlantillaDocumento $tipo): ?DocumentTemplate
    {
        $base = fn () => DocumentTemplate::query()->where('tipo', $tipo->value)->where('activo', true);

        if ($colaborador->puesto_id !== null) {
            $plantilla = $base()->where('puesto_id', $colaborador->puesto_id)->first();

            if ($plantilla !== null) {
                return $plantilla;
            }
        }

        if ($colaborador->departamento_id !== null) {
            $plantilla = $base()->whereNull('puesto_id')->where('departamento_id', $colaborador->departamento_id)->first();

            if ($plantilla !== null) {
                return $plantilla;
            }
        }

        if ($colaborador->sucursal_principal_id !== null) {
            $plantilla = $base()->whereNull('puesto_id')->whereNull('departamento_id')->where('sucursal_id', $colaborador->sucursal_principal_id)->first();

            if ($plantilla !== null) {
                return $plantilla;
            }
        }

        $empresaId = $colaborador->sucursalPrincipal?->empresa_id;

        if ($empresaId !== null) {
            $plantilla = $base()->whereNull('puesto_id')->whereNull('departamento_id')->whereNull('sucursal_id')->where('empresa_id', $empresaId)->first();

            if ($plantilla !== null) {
                return $plantilla;
            }
        }

        return $base()->whereNull('puesto_id')->whereNull('departamento_id')->whereNull('sucursal_id')->whereNull('empresa_id')->first();
    }
}
