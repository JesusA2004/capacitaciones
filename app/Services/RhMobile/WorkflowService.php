<?php

namespace App\Services\RhMobile;

use App\Enums\EstadoDocumento;
use App\Enums\EstadoSolicitudInterna;
use App\Enums\EstadoSolicitudVacaciones;
use App\Models\Colaborador;
use App\Models\EmployeeDocument;
use App\Models\SolicitudInterna;
use App\Models\SolicitudVacaciones;
use App\Models\User;

/**
 * Primera version del motor de workflow para la app movil: hoy cada tipo de
 * recurso (solicitud/vacaciones/documento/incorporacion) tiene una sola
 * etapa de revision ("rh"), resuelta por permisos + alcance organizacional
 * (nunca por el frontend). Para agregar una segunda etapa (p. ej. gerencia
 * antes de RH) en el futuro: (1) agregar la clave de la nueva etapa a
 * ETAPAS_* correspondiente, (2) decidir en resolverEtapaActual() cuando
 * corresponde cada una segun el estado del recurso, (3) devolver
 * siguiente_etapa en vez de null cuando aplique. El resto de la app
 * (acciones_permitidas, progreso) no necesita cambiar. Ver seccion 17 del
 * encargo movil y docs/RH_MOBILE_API.md.
 */
class WorkflowService
{
    /**
     * @return array<string, mixed>
     */
    public function paraSolicitud(User $usuario, SolicitudInterna $solicitud): array
    {
        $pendiente = in_array($solicitud->estado, [EstadoSolicitudInterna::Enviada, EstadoSolicitudInterna::EnRevision], true);

        $acciones = ['ver'];
        if ($pendiente) {
            if ($usuario->can('rh.solicitudes.aprobar')) {
                $acciones[] = 'aprobar';
            }
            if ($usuario->can('rh.solicitudes.rechazar')) {
                $acciones[] = 'rechazar';
            }
            if ($usuario->can('rh.solicitudes.correccion')) {
                $acciones[] = 'solicitar_correccion';
            }
        }

        return [
            'acciones_permitidas' => $acciones,
            'workflow' => $this->flujoUnaEtapa(
                estado: $solicitud->estado->value,
                etapaTerminada: ! $pendiente,
                revisadoPor: $solicitud->revisadoPor,
                revisadoEn: $solicitud->revisado_en,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function paraVacacion(User $usuario, SolicitudVacaciones $vacacion): array
    {
        $pendiente = $vacacion->estado === EstadoSolicitudVacaciones::Pendiente;

        $acciones = ['ver'];
        if ($pendiente) {
            if ($usuario->can('rh.vacaciones.aprobar')) {
                $acciones[] = 'aprobar';
            }
            if ($usuario->can('rh.vacaciones.rechazar')) {
                $acciones[] = 'rechazar';
            }
        }

        return [
            'acciones_permitidas' => $acciones,
            'workflow' => $this->flujoUnaEtapa(
                estado: $vacacion->estado->value,
                etapaTerminada: ! $pendiente,
                revisadoPor: $vacacion->revisadoPor,
                revisadoEn: $vacacion->revisado_en,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function paraDocumento(User $usuario, EmployeeDocument $documento): array
    {
        $pendiente = in_array($documento->status, [EstadoDocumento::Cargado, EstadoDocumento::EnRevision, EstadoDocumento::CambioSolicitado], true);

        $acciones = ['ver'];
        if ($usuario->can('rh.documentos.ver_archivo')) {
            $acciones[] = 'ver_archivo';
        }
        if ($pendiente) {
            if ($usuario->can('rh.documentos.aprobar')) {
                $acciones[] = 'aprobar';
            }
            if ($usuario->can('rh.documentos.rechazar')) {
                $acciones[] = 'rechazar';
            }
        }

        return [
            'acciones_permitidas' => $acciones,
            'workflow' => $this->flujoUnaEtapa(
                estado: $documento->status->value,
                etapaTerminada: ! $pendiente,
                revisadoPor: $documento->revisadoPor,
                revisadoEn: $documento->reviewed_at,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function paraIncorporacion(User $usuario, Colaborador $colaborador, string $estadoGeneral): array
    {
        $pendiente = $estadoGeneral === 'completo' && $colaborador->incorporacion_decision === null;

        $acciones = ['ver'];
        if ($pendiente) {
            if ($usuario->can('rh.incorporaciones.aprobar')) {
                $acciones[] = 'aprobar';
            }
            if ($usuario->can('rh.incorporaciones.rechazar')) {
                $acciones[] = 'rechazar';
            }
        }

        return [
            'acciones_permitidas' => $acciones,
            'workflow' => $this->flujoUnaEtapa(
                estado: $colaborador->incorporacion_decision ?? $estadoGeneral,
                etapaTerminada: $colaborador->incorporacion_decision !== null,
                revisadoPor: $colaborador->incorporacionDecididaPor,
                revisadoEn: $colaborador->incorporacion_decidida_en,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function flujoUnaEtapa(string $estado, bool $etapaTerminada, ?User $revisadoPor, mixed $revisadoEn): array
    {
        return [
            'estado' => $estado,
            'etapa_actual' => $etapaTerminada ? null : ['clave' => 'rh', 'nombre' => 'Revisión RH'],
            'progreso' => ['actual' => $etapaTerminada ? 1 : 0, 'total' => 1],
            'flujo' => [
                [
                    'etapa' => 'rh',
                    'estado' => $etapaTerminada ? $estado : 'pendiente',
                    'usuario' => $revisadoPor?->nombreCompleto(),
                    'fecha' => $revisadoEn?->toIso8601String(),
                ],
            ],
            'siguiente_etapa' => null,
        ];
    }
}
