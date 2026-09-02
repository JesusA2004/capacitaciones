<?php

namespace App\Services\Onboarding;

use App\Enums\EstadoDocumento;
use App\Models\AltaDigital;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\Expedientes\ExpedienteService;

/**
 * Checklist administrativo de incorporacion de un colaborador nuevo. No es
 * capacitacion: es puramente administrativo (datos, documentos, expediente).
 * No existe una tabla propia: se calcula a partir de User, EmployeeDocument,
 * AltaDigital y ExpedienteService, siguiendo el mismo criterio de "vista
 * calculada" que el expediente digital (ver docs/EXPEDIENTES_DIGITALES.md).
 */
class OnboardingService
{
    public function __construct(private readonly ExpedienteService $expediente) {}

    /**
     * @return array<int, array{clave: string, etiqueta: string, completado: bool}>
     */
    public function checklist(User $colaborador): array
    {
        $alta = AltaDigital::query()->where('user_id', $colaborador->id)->first();
        $resumen = $this->expediente->resumenCompletitud($colaborador);
        $documentos = EmployeeDocument::query()->where('user_id', $colaborador->id);

        $tieneContrato = fn (array $estados) => (clone $documentos)
            ->whereHas('tipo', fn ($q) => $q->where('clave', 'contrato'))
            ->whereIn('status', $estados)
            ->exists();

        return [
            $this->item(
                'datos_personales',
                'Datos personales capturados',
                $colaborador->curp !== null && $colaborador->domicilio !== null,
            ),
            $this->item(
                'datos_laborales',
                'Datos laborales capturados',
                $colaborador->sucursal_principal_id !== null
                    && $colaborador->puesto_id !== null
                    && $colaborador->fecha_ingreso !== null,
            ),
            $this->item(
                'fotografia',
                'Fotografía cargada',
                $colaborador->foto_path !== null || $alta?->foto_path !== null,
            ),
            $this->item('documentos_cargados', 'Documentos cargados', $documentos->exists()),
            $this->item(
                'documentos_aprobados',
                'Documentos aprobados',
                $resumen['requeridos_total'] > 0 && $resumen['requeridos_aprobados'] === $resumen['requeridos_total'],
            ),
            $this->item(
                'contrato_generado',
                'Contrato generado',
                $tieneContrato([
                    EstadoDocumento::Cargado->value,
                    EstadoDocumento::EnRevision->value,
                    EstadoDocumento::Aprobado->value,
                ]),
            ),
            $this->item(
                'contrato_firmado',
                'Contrato firmado cargado',
                $tieneContrato([EstadoDocumento::Aprobado->value]),
            ),
            $this->item(
                'aviso_privacidad',
                'Aviso de privacidad aceptado',
                (bool) $alta?->aviso_privacidad_aceptado,
            ),
            $this->item(
                'consentimiento',
                'Consentimiento firmado',
                (bool) $alta?->consentimiento_datos_aceptado,
            ),
            $this->item('expediente_completo', 'Expediente completo', $resumen['porcentaje'] >= 100.0),
            $this->item(
                'alta_aprobada',
                'Alta aprobada por RH',
                $alta?->estado->value === 'convertida_a_colaborador',
            ),
        ];
    }

    public function porcentaje(User $colaborador): float
    {
        $items = $this->checklist($colaborador);
        $completados = collect($items)->where('completado', true)->count();

        return $items === [] ? 0.0 : round(($completados / count($items)) * 100, 1);
    }

    /**
     * @return array{clave: string, etiqueta: string, completado: bool}
     */
    private function item(string $clave, string $etiqueta, bool $completado): array
    {
        return ['clave' => $clave, 'etiqueta' => $etiqueta, 'completado' => $completado];
    }
}
