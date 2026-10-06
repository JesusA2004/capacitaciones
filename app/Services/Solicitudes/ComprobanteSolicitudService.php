<?php

namespace App\Services\Solicitudes;

use App\Enums\CategoriaDocumento;
use App\Enums\FamiliaAdministrativa;
use App\Enums\TipoSolicitudInterna;
use App\Models\GeneratedDocument;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\DocumentosAdministrativos\DatosDocumentoAdministrativo;
use App\Services\DocumentosAdministrativos\DocumentoAdministrativoService;
use App\Services\DocumentosLaborales\FlujoDocumentalService;
use App\Services\DocumentosLaborales\MotorDocumentalService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Comprobante PDF de vacaciones y permisos aprobados, y constancia laboral
 * de una solicitud de constancia aprobada, integrados al flujo documental
 * general: se archivan en el expediente como documento del colaborador.
 *
 * Si Jurídico cargó una plantilla (claves comprobante_vacaciones /
 * comprobante_permiso / constancia_laboral) se usa esa; si no, se emite el
 * documento administrativo con el diseño vigente de Documentos maestros →
 * Documentos administrativos (Comprobante de solicitud / Constancia
 * laboral) — no es un formato jurídico.
 * El formato oficial con firma (config/solicitudes.php → formatos) sigue
 * funcionando aparte, sin cambios.
 *
 * Nunca revierte la aprobación: un fallo queda en el log.
 */
class ComprobanteSolicitudService
{
    public function __construct(
        private readonly MotorDocumentalService $motor,
        private readonly FlujoDocumentalService $flujo,
        private readonly DocumentoAdministrativoService $documentosAdministrativos,
        private readonly DatosDocumentoAdministrativo $datosDocumento,
    ) {}

    public function aplicaPara(SolicitudInterna $solicitud): bool
    {
        return $this->clave($solicitud) !== null;
    }

    public function generarSiAplica(SolicitudInterna $solicitud, User $actor): ?GeneratedDocument
    {
        $clave = $this->clave($solicitud);

        if ($clave === null) {
            return null;
        }

        $existente = GeneratedDocument::query()
            ->where('documentable_type', $solicitud->getMorphClass())
            ->where('documentable_id', $solicitud->id)
            ->where('clave_plantilla', $clave)
            ->first();

        if ($existente !== null) {
            return $existente;
        }

        try {
            $solicitud->loadMissing(['colaborador', 'usuario.colaborador', 'revisadoPor']);
            $colaborador = $solicitud->personaSolicitante();

            if ($colaborador === null) {
                return null;
            }

            $titulo = sprintf('%s %s', match ($solicitud->tipo) {
                TipoSolicitudInterna::Vacaciones => 'Comprobante de vacaciones',
                TipoSolicitudInterna::ConstanciaLaboral => 'Constancia laboral',
                default => 'Comprobante de permiso',
            }, $solicitud->folio);

            if ($this->motor->tienePlantillaActiva($clave)) {
                $documento = $this->motor->generar($colaborador, $clave, $actor, $this->variables($solicitud), $solicitud, $titulo);
            } else {
                $administrativo = $solicitud->tipo === TipoSolicitudInterna::ConstanciaLaboral
                    ? $this->documentosAdministrativos->generar(FamiliaAdministrativa::ConstanciaLaboral, $this->datosDocumento->constancia($colaborador), $actor)
                    : $this->documentosAdministrativos->generar(FamiliaAdministrativa::ComprobanteSolicitud, $this->datosDocumento->comprobante($solicitud, $colaborador), $actor);

                $documento = $this->motor->registrarPdf($colaborador, $administrativo['pdf'], $titulo, $actor, [
                    ...$administrativo['opciones_registro'],
                    'clave' => $clave,
                    'categoria' => match ($solicitud->tipo) {
                        TipoSolicitudInterna::Vacaciones => CategoriaDocumento::Vacaciones,
                        TipoSolicitudInterna::ConstanciaLaboral => CategoriaDocumento::Personales,
                        default => CategoriaDocumento::Permisos,
                    },
                    'payload' => $this->variables($solicitud),
                    'documentable' => $solicitud,
                ]);
            }

            // Comprobante sin firma/impresión requerida: queda archivado de inmediato.
            if ($documento->estado_flujo?->value === 'generado') {
                $this->flujo->archivar($documento, $actor);
            }

            return $documento->refresh();
        } catch (Throwable $e) {
            Log::warning('ComprobanteSolicitudService: no se pudo generar el comprobante.', ['solicitud_id' => $solicitud->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function clave(SolicitudInterna $solicitud): ?string
    {
        return match ($solicitud->tipo) {
            TipoSolicitudInterna::Vacaciones => 'comprobante_vacaciones',
            TipoSolicitudInterna::ConstanciaLaboral => 'constancia_laboral',
            TipoSolicitudInterna::PermisoConGoce,
            TipoSolicitudInterna::PermisoSinGoce,
            TipoSolicitudInterna::PermisoTiempo,
            TipoSolicitudInterna::SalidaTemprano,
            TipoSolicitudInterna::LlegadaTarde,
            TipoSolicitudInterna::PermisoEspecialCumpleanos,
            TipoSolicitudInterna::PermisoEspecialPaternidad,
            TipoSolicitudInterna::PermisoEspecialFallecimiento => 'comprobante_permiso',
            default => null,
        };
    }

    /**
     * @return array<string, string>
     */
    private function variables(SolicitudInterna $solicitud): array
    {
        return [
            'folio_solicitud' => (string) $solicitud->folio,
            'tipo_solicitud' => $solicitud->tipo->etiqueta(),
            'fecha_inicio_permiso' => $solicitud->fecha_inicio?->format('d/m/Y') ?? '',
            'fecha_fin_permiso' => $solicitud->fecha_fin?->format('d/m/Y') ?? '',
            'motivo_permiso' => (string) $solicitud->motivo,
            'dias_vacaciones' => $solicitud->dias_solicitados !== null ? (string) $solicitud->dias_solicitados : '',
            'observaciones' => (string) $solicitud->observaciones,
        ];
    }
}
