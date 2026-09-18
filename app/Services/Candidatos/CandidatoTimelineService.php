<?php

namespace App\Services\Candidatos;

use App\Enums\EstadoAltaDigital;
use App\Enums\EstadoCandidato;
use App\Enums\EstadoInvitacionIncorporacion;
use App\Models\Candidato;

/**
 * Traduce el estado real de un candidato (App\Enums\EstadoCandidato) más
 * su Alta Digital / Invitación de incorporación, si ya existen, a una
 * línea de tiempo visual para Rh\Candidatos\Show.vue. Solo lectura/
 * presentación: nunca decide ni cambia el estado real, eso sigue siendo
 * App\Http\Controllers\Rh\CandidatoController.
 */
class CandidatoTimelineService
{
    private const ESTADOS_DESCARTE = [
        EstadoCandidato::NoSeleccionado,
        EstadoCandidato::NoViable,
        EstadoCandidato::NoRespondio,
        EstadoCandidato::Desistio,
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
                'preseleccion',
                'Preselección',
                $this->estadoEtapa($candidato, $descartado, [
                    'completado' => [EstadoCandidato::Entrevista, EstadoCandidato::Psicometricos, EstadoCandidato::EstudioSocioeconomico, EstadoCandidato::Pruebas, EstadoCandidato::ValidacionDocumental, EstadoCandidato::OfertaAprobacion, EstadoCandidato::ListoParaContratacion, EstadoCandidato::Contratado],
                    'actual' => [EstadoCandidato::Recibidos, EstadoCandidato::Preseleccion],
                ]),
                null,
                null,
                $candidato->estado === EstadoCandidato::Recibidos ? 'Preseleccionar candidato' : null,
            ),
            $this->etapa(
                'entrevista',
                'Entrevista',
                $this->estadoEtapa($candidato, $descartado, [
                    'completado' => [EstadoCandidato::Psicometricos, EstadoCandidato::EstudioSocioeconomico, EstadoCandidato::Pruebas, EstadoCandidato::ValidacionDocumental, EstadoCandidato::OfertaAprobacion, EstadoCandidato::ListoParaContratacion, EstadoCandidato::Contratado],
                    'actual' => [EstadoCandidato::Entrevista],
                ]),
                $candidato->fecha_entrevista?->toIso8601String(),
                null,
                $candidato->estado === EstadoCandidato::Preseleccion ? 'Programar entrevista' : null,
            ),
            $this->etapa(
                'evaluaciones',
                'Psicométricos, estudio socioeconómico y pruebas',
                $this->estadoEtapa($candidato, $descartado, [
                    'completado' => [EstadoCandidato::ValidacionDocumental, EstadoCandidato::OfertaAprobacion, EstadoCandidato::ListoParaContratacion, EstadoCandidato::Contratado],
                    'actual' => [EstadoCandidato::Psicometricos, EstadoCandidato::EstudioSocioeconomico, EstadoCandidato::Pruebas],
                ]),
                null,
                $candidato->gerenteInvolucrado?->nombreCompleto(),
                null,
            ),
            $this->etapa(
                'validacion_documental',
                'Validación documental',
                $this->estadoEtapa($candidato, $descartado, [
                    'completado' => [EstadoCandidato::OfertaAprobacion, EstadoCandidato::ListoParaContratacion, EstadoCandidato::Contratado],
                    'actual' => [EstadoCandidato::ValidacionDocumental],
                ]),
                null,
                null,
                $candidato->estado === EstadoCandidato::Pruebas ? 'Solicitar documentos' : null,
            ),
            $this->etapa(
                'oferta_aprobacion',
                'Oferta / aprobación',
                $this->estadoEtapa($candidato, $descartado, [
                    'completado' => [EstadoCandidato::ListoParaContratacion, EstadoCandidato::Contratado],
                    'actual' => [EstadoCandidato::OfertaAprobacion],
                ]),
                null,
                $candidato->responsableRh?->nombreCompleto(),
                null,
            ),
            $this->etapa(
                'listo_para_contratacion',
                'Listo para contratación',
                $alta !== null || $invitacion !== null || $candidato->estado === EstadoCandidato::Contratado ? 'completado'
                    : ($candidato->estado === EstadoCandidato::ListoParaContratacion ? 'actual' : 'pendiente'),
                null,
                null,
                $candidato->estado === EstadoCandidato::ListoParaContratacion && $alta === null && $invitacion === null ? 'Generar alta digital o invitación QR' : null,
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
