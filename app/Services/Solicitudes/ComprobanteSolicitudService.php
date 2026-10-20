<?php

namespace App\Services\Solicitudes;

use App\Enums\CategoriaDocumento;
use App\Enums\CausalPermisoEspecial;
use App\Enums\EstadoSolicitudInterna;
use App\Enums\FamiliaAdministrativa;
use App\Enums\GocePermiso;
use App\Enums\TipoPermisoSolicitado;
use App\Enums\TipoSolicitudInterna;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\DocumentosAdministrativos\DatosDocumentoAdministrativo;
use App\Services\DocumentosAdministrativos\DocumentoAdministrativoService;
use App\Services\DocumentosLaborales\FlujoDocumentalService;
use App\Services\DocumentosLaborales\MotorDocumentalService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
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

    /** clave_plantilla del formato oficial de permiso generado. */
    public const CLAVE_PERMISO = 'formato_permiso_oficial';

    /**
     * Formato oficial de permiso LIBERADO: solo de un permiso autorizado por
     * Recursos Humanos (estado aprobada). Antes de eso no existe ni se puede
     * descargar/imprimir. Si la generación posterior a la aprobación falló,
     * se reintenta aquí (idempotente: nunca duplica el documento).
     *
     * @throws ValidationException Permiso todavía no autorizado por RH.
     */
    public function permisoLiberado(SolicitudInterna $solicitud): GeneratedDocument
    {
        if ($solicitud->tipo !== TipoSolicitudInterna::Permiso) {
            throw ValidationException::withMessages(['solicitud' => 'Esta solicitud no es un permiso.']);
        }

        if ($solicitud->estado !== EstadoSolicitudInterna::Aprobada || $solicitud->revisado_por === null) {
            throw ValidationException::withMessages(['solicitud' => 'El formato de permiso se libera cuando Recursos Humanos lo autoriza.']);
        }

        $solicitud->loadMissing('revisadoPor');
        $documento = $solicitud->revisadoPor !== null ? $this->generarSiAplica($solicitud, $solicitud->revisadoPor) : null;

        if ($documento === null) {
            throw ValidationException::withMessages(['solicitud' => 'No se pudo generar el formato de permiso; intenta de nuevo o avisa a sistemas.']);
        }

        return $documento;
    }

    public function respuestaPermiso(SolicitudInterna $solicitud): StreamedResponse
    {
        $documento = $this->permisoLiberado($solicitud);

        return $this->motor->respuesta($documento);
    }

    /**
     * Resumen del permiso para web/app (gerente, RH y colaborador).
     *
     * @return array<string, mixed>|null
     */
    public function resumenPermiso(SolicitudInterna $solicitud): ?array
    {
        if ($solicitud->tipo !== TipoSolicitudInterna::Permiso) {
            return null;
        }

        $solicitud->loadMissing('revisadoPor');
        $liberado = $solicitud->estado === EstadoSolicitudInterna::Aprobada && $solicitud->revisado_por !== null;

        return [
            'tipo' => $solicitud->permiso_tipo,
            'tipo_etiqueta' => TipoPermisoSolicitado::tryFrom((string) $solicitud->permiso_tipo)?->etiqueta(),
            'goce' => $solicitud->permiso_goce,
            'goce_etiqueta' => GocePermiso::tryFrom((string) $solicitud->permiso_goce)?->etiqueta(),
            'causal' => $solicitud->permiso_causal,
            'causal_etiqueta' => CausalPermisoEspecial::tryFrom((string) $solicitud->permiso_causal)?->etiqueta(),
            'hora_salida' => $solicitud->hora_salida !== null ? substr((string) $solicitud->hora_salida, 0, 5) : null,
            'hora_entrada' => $solicitud->hora_entrada !== null ? substr((string) $solicitud->hora_entrada, 0, 5) : null,
            'dias' => $solicitud->dias_solicitados,
            'autorizado_por_rh' => $liberado,
            'autorizado_por' => $liberado && $solicitud->revisadoPor !== null ? trim($solicitud->revisadoPor->name.' '.$solicitud->revisadoPor->apellidos) : null,
            'autorizado_en' => $liberado ? $solicitud->revisado_en?->toIso8601String() : null,
            'pdf_disponible' => $liberado,
        ];
    }

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

            // Permiso: el FORMATO OFICIAL (docs/formatosRH/Formato_Permiso.docx)
            // lleno con la solicitud ya autorizada por RH. Requiere impresión y
            // las tres firmas físicas (jefe inmediato, RH y colaborador).
            if ($solicitud->tipo === TipoSolicitudInterna::Permiso) {
                $administrativo = $this->documentosAdministrativos->generar(FamiliaAdministrativa::Permiso, $this->datosDocumento->permiso($solicitud, $colaborador), $actor);
                $documento = $this->motor->registrarPdf($colaborador, $administrativo['pdf'], sprintf('Solicitud de permiso %s', $solicitud->folio), $actor, [
                    ...$administrativo['opciones_registro'],
                    'clave' => $clave,
                    'categoria' => CategoriaDocumento::Permisos,
                    'payload' => $this->variables($solicitud),
                    'documentable' => $solicitud,
                    'requiere_impresion' => true,
                    'requiere_firma_fisica' => true,
                ]);

                return $documento->refresh();
            }

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

    /**
     * Título del documento que se emitirá al aprobar (para la vista previa).
     */
    public function tituloDocumento(SolicitudInterna $solicitud): ?string
    {
        if ($this->clave($solicitud) === null) {
            return null;
        }

        return match ($solicitud->tipo) {
            TipoSolicitudInterna::Permiso => 'Formato de permiso',
            TipoSolicitudInterna::Vacaciones => 'Comprobante de vacaciones',
            TipoSolicitudInterna::ConstanciaLaboral => 'Constancia laboral',
            default => 'Comprobante de permiso',
        };
    }

    /**
     * Vista previa REAL (mismo diseño y motor que generarSiAplica()) con los
     * datos de la solicitud, ANTES de aprobarla: no registra documento, no
     * archiva nada ni toca la solicitud. Los datos de autorización (quién y
     * cuándo) se simulan en memoria con quien está por aprobar.
     *
     * @throws ValidationException El tipo no emite comprobante o no hay colaborador.
     */
    public function vistaPreviaPdf(SolicitudInterna $solicitud, User $actor): string
    {
        $clave = $this->clave($solicitud);

        if ($clave === null) {
            throw ValidationException::withMessages(['solicitud' => 'Este tipo de solicitud no emite comprobante al aprobarse.']);
        }

        $solicitud->loadMissing(['colaborador', 'usuario.colaborador', 'revisadoPor', 'diasVacaciones']);
        $colaborador = $solicitud->personaSolicitante();

        if ($colaborador === null) {
            throw ValidationException::withMessages(['solicitud' => 'La solicitud no tiene un colaborador asociado.']);
        }

        // Copia en memoria: nunca se guarda (clone no persiste nada).
        $copia = clone $solicitud;

        if ($copia->estado !== EstadoSolicitudInterna::Aprobada) {
            $copia->setRelation('revisadoPor', $actor);
            $copia->revisado_en = Carbon::now();
        }

        if ($solicitud->tipo === TipoSolicitudInterna::Permiso) {
            return $this->documentosAdministrativos->renderizarVigente(FamiliaAdministrativa::Permiso, $this->datosDocumento->permiso($copia, $colaborador));
        }

        $heredada = $this->motor->plantillaActiva($clave);

        if ($heredada !== null) {
            return $this->motor->previsualizar($colaborador, $heredada, $this->variables($copia));
        }

        $maestro = DocumentTemplate::query()
            ->where('clave', $clave)
            ->where('estado_master', 'listo')
            ->where('activo', true)
            ->orderByDesc('version')
            ->first();

        if ($maestro !== null) {
            return $this->motor->previsualizarMaestro($maestro, $this->motor->contextoDesde($colaborador, $copia, $actor, $this->variables($copia)))['pdf'];
        }

        return $solicitud->tipo === TipoSolicitudInterna::ConstanciaLaboral
            ? $this->documentosAdministrativos->renderizarVigente(FamiliaAdministrativa::ConstanciaLaboral, $this->datosDocumento->constancia($colaborador))
            : $this->documentosAdministrativos->renderizarVigente(FamiliaAdministrativa::ComprobanteSolicitud, $this->datosDocumento->comprobante($copia, $colaborador));
    }

    private function clave(SolicitudInterna $solicitud): ?string
    {
        return match ($solicitud->tipo) {
            TipoSolicitudInterna::Permiso => self::CLAVE_PERMISO,
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
