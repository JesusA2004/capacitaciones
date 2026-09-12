<?php

namespace App\Services\Candidatos;

use App\Enums\EstadoAltaDigital;
use App\Enums\EstadoCandidato;
use App\Enums\EstadoInvitacionIncorporacion;
use App\Models\Candidato;

/**
 * Traduce el estado real de un candidato (App\Enums\EstadoCandidato) más
 * su Alta Digital / Invitación de incorporación, si ya existen, a una
 * línea de tiempo visual de 10 etapas para Rh\Candidatos\Show.vue (ver
 * sección 8 del encargo). Solo lectura/presentación: nunca decide ni
 * cambia el estado real, eso sigue siendo App\Http\Controllers\Rh\CandidatoController.
 */
class CandidatoTimelineService
{
    private const ESTADOS_DESCARTE = [
        EstadoCandidato::NoViable,
        EstadoCandidato::Rechazado,
        EstadoCandidato::Descartado,
        EstadoCandidato::NoRespondio,
    ];

    /**
     * @return array<int, array{clave: string, titulo: string, estado: string, fecha: string|null, responsable: string|null, accion: string|null}>
     */
    public function construir(Candidato $candidato): array
    {
        $candidato->loadMissing(['altaDigital', 'incorporacionInvitacion', 'altaDigital.colaborador']);

        $descartado = in_array($candidato->estado, self::ESTADOS_DESCARTE, true);
        $alta = $candidato->altaDigital;
        $invitacion = $candidato->incorporacionInvitacion;

        return [
            $this->etapa(
                'registro',
                'Registro',
                'completado',
                $candidato->created_at?->toIso8601String(),
                $candidato->creadoPor?->nombreCompleto(),
                null,
            ),
            $this->etapa(
                'entrevista',
                'Entrevista',
                $this->estadoEtapa($candidato, $descartado, [
                    'completado' => [EstadoCandidato::Entrevistado, EstadoCandidato::DocumentacionSolicitada, EstadoCandidato::EnRevision, EstadoCandidato::AprobadoGerencia, EstadoCandidato::AprobadoRh, EstadoCandidato::Contratado],
                    'actual' => [EstadoCandidato::Contactado, EstadoCandidato::Respondio, EstadoCandidato::Viable, EstadoCandidato::EntrevistaProgramada],
                ]),
                $candidato->fecha_entrevista?->toIso8601String(),
                null,
                $candidato->estado === EstadoCandidato::Viable ? 'Programar entrevista' : null,
            ),
            $this->etapa(
                'documentos',
                'Documentos',
                $this->estadoEtapa($candidato, $descartado, [
                    'completado' => [EstadoCandidato::EnRevision, EstadoCandidato::AprobadoGerencia, EstadoCandidato::AprobadoRh, EstadoCandidato::Contratado],
                    'actual' => [EstadoCandidato::DocumentacionSolicitada],
                ]),
                null,
                null,
                $candidato->estado === EstadoCandidato::Entrevistado ? 'Solicitar documentos' : null,
            ),
            $this->etapa(
                'evaluacion',
                'Evaluación',
                $this->estadoEtapa($candidato, $descartado, [
                    'completado' => [EstadoCandidato::AprobadoGerencia, EstadoCandidato::AprobadoRh, EstadoCandidato::Contratado],
                    'actual' => [EstadoCandidato::EnRevision],
                ]),
                null,
                $candidato->gerenteInvolucrado?->nombreCompleto(),
                null,
            ),
            $this->etapa(
                'aprobado_rh',
                'Aprobado RH / Elegible',
                $this->estadoEtapa($candidato, $descartado, [
                    'completado' => [EstadoCandidato::AprobadoRh, EstadoCandidato::Contratado],
                    'actual' => [EstadoCandidato::AprobadoGerencia],
                ]),
                null,
                $candidato->responsableRh?->nombreCompleto(),
                null,
            ),
            $this->etapa(
                'seleccionado',
                'Seleccionado',
                $alta !== null || $candidato->estado === EstadoCandidato::Contratado ? 'completado'
                    : ($candidato->estado === EstadoCandidato::AprobadoRh ? 'actual' : 'pendiente'),
                null,
                null,
                $candidato->estado === EstadoCandidato::AprobadoRh && $alta === null ? 'Generar alta digital' : null,
            ),
            $this->etapa(
                'alta_digital',
                'Alta digital',
                $alta === null ? 'pendiente' : ($alta->estado === EstadoAltaDigital::Rechazada || $alta->estado === EstadoAltaDigital::Cancelada ? 'descartado' : 'completado'),
                $alta?->created_at?->toIso8601String(),
                $alta?->creadoPor?->nombreCompleto(),
                $alta !== null && $alta->estado->permiteCaptura() ? 'Copiar liga de captura' : null,
            ),
            $this->etapa(
                'qr_incorporacion',
                'QR / Incorporación',
                $invitacion === null ? 'pendiente' : ($invitacion->estado === EstadoInvitacionIncorporacion::Usado ? 'completado' : ($invitacion->estado === EstadoInvitacionIncorporacion::Activo ? 'actual' : 'descartado')),
                $invitacion?->used_at?->toIso8601String(),
                null,
                $alta !== null && $alta->estado === EstadoAltaDigital::Aprobada && $invitacion === null ? 'Generar QR de incorporación' : null,
            ),
            $this->etapa(
                'colaborador_activo',
                $descartado ? 'Descartado' : 'Colaborador activo',
                $descartado ? 'descartado' : ($alta?->colaborador !== null ? 'completado' : 'pendiente'),
                $alta?->colaborador?->created_at?->toIso8601String(),
                null,
                null,
            ),
        ];
    }

    /**
     * @param  array{completado: array<int, EstadoCandidato>, actual: array<int, EstadoCandidato>}  $mapa
     */
    private function estadoEtapa(Candidato $candidato, bool $descartado, array $mapa): string
    {
        if ($descartado) {
            return 'descartado';
        }

        if (in_array($candidato->estado, $mapa['completado'], true)) {
            return 'completado';
        }

        if (in_array($candidato->estado, $mapa['actual'], true)) {
            return 'actual';
        }

        return 'pendiente';
    }

    /**
     * @return array{clave: string, titulo: string, estado: string, fecha: string|null, responsable: string|null, accion: string|null}
     */
    private function etapa(string $clave, string $titulo, string $estado, ?string $fecha, ?string $responsable, ?string $accion): array
    {
        return [
            'clave' => $clave,
            'titulo' => $titulo,
            'estado' => $estado,
            'fecha' => $fecha,
            'responsable' => $responsable,
            'accion' => $accion,
        ];
    }
}
