<?php

namespace App\Services\Incorporacion;

use App\Enums\EstadoDocumento;
use App\Enums\EstadoUsuario;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\Expedientes\DocumentoStorageService;
use App\Services\Expedientes\ExpedienteService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Unica fuente de las reglas de negocio de "incorporacion documental" de la
 * app movil (colaborador con cuenta pero todavia sin `estatus` Activo): que
 * puede ver/subir/solicitar el colaborador, y que puede aprobar/rechazar/
 * autorizar RH. No expone nunca disk/path fisico (EmployeeDocument ya los
 * oculta) ni datos sensibles del colaborador (sueldos, etc.) — la vista
 * para colaborador (estadoIncorporacion) es deliberadamente mas angosta
 * que la de RH (detalleParaRh), que si puede ver quien subio/reviso cada
 * documento.
 *
 * Estados de documento visibles a la app: pendiente (sin subir), y el
 * valor crudo de EstadoDocumento para el resto (subido/cargado,
 * en_revision, aprobado, rechazado, requiere_correccion, vencido,
 * cambio_solicitado, cambio_autorizado) — ver docs/API_MOVIL.md.
 */
class IncorporacionService
{
    /**
     * Estados en los que el colaborador puede subir un archivo directo
     * (primera vez, o porque el anterior fue rechazado/vencido) sin pasar
     * por el flujo de solicitar-cambio.
     *
     * @var array<int, EstadoDocumento>
     */
    private const ESTADOS_SUBIDA_DIRECTA = [
        EstadoDocumento::Rechazado,
        EstadoDocumento::RequiereCorreccion,
        EstadoDocumento::Vencido,
    ];

    /** Estados en los que ya hay un documento vigente que solo se puede reemplazar porque RH autorizo el cambio. */
    private const ESTADOS_REEMPLAZO_AUTORIZADO = [EstadoDocumento::CambioAutorizado];

    /** Estados desde los que se puede solicitar un cambio (ya subido, en revision o aprobado). */
    private const ESTADOS_SOLICITAN_CAMBIO = [EstadoDocumento::EnRevision, EstadoDocumento::Aprobado];

    public function __construct(
        private readonly ExpedienteService $expediente,
        private readonly DocumentoStorageService $storage,
    ) {}

    /**
     * @return Collection<int, DocumentType>
     */
    public function tiposDocumento(): Collection
    {
        return DocumentType::query()->where('activo', true)->orderBy('nombre')->get();
    }

    /**
     * Payload completo para GET /colaborador/incorporacion (y su alias
     * /resumen): estado general, banderas de acceso y el detalle por
     * documento visible al propio colaborador.
     *
     * @return array<string, mixed>
     */
    public function estadoIncorporacion(User $colaborador): array
    {
        $tipos = $this->tiposDocumento();
        $vigentes = $this->expediente->documentosVigentes($colaborador);

        $documentos = $tipos->map(fn (DocumentType $tipo) => $this->documentoParaColaborador($tipo, $vigentes->get($tipo->id)));

        $progreso = $this->progreso($tipos, $vigentes);
        $estado = $this->estadoGeneral($colaborador, $tipos, $vigentes);

        return [
            'estado' => $estado,
            'puede_acceder_portal' => $colaborador->puedeAccederPortal(),
            'puede_subir_documentos' => $estado !== 'aprobado',
            'puede_solicitar_cambios' => $estado !== 'aprobado',
            'progreso' => $progreso,
            'documentos' => $documentos->values()->all(),
        ];
    }

    /**
     * Detalle por documento para RH (App\Http\Controllers\Api\V1\Rh\ExpedienteController):
     * a diferencia de estadoIncorporacion(), aqui si se expone quien subio/
     * reviso/autorizo cada documento y el id real de EmployeeDocument (para
     * poder ver/aprobar/rechazar/autorizar-cambio ese documento puntual).
     *
     * @return array<int, array<string, mixed>>
     */
    public function detalleParaRh(User $colaborador): array
    {
        $tipos = $this->tiposDocumento();
        $vigentes = $this->expediente->documentosVigentes($colaborador);

        return $tipos->map(function (DocumentType $tipo) use ($vigentes) {
            $documento = $vigentes->get($tipo->id);
            $documento?->loadMissing('cambioAutorizadoPor:id,name,apellidos');

            return [
                'tipo_id' => $tipo->id,
                'tipo' => $tipo->clave,
                'nombre' => $tipo->nombre,
                'obligatorio' => $tipo->requerido,
                'documento_id' => $documento?->id,
                'estado' => $documento?->status->value ?? 'pendiente',
                'version' => $documento?->version,
                'nombre_original' => $documento?->original_name,
                'comentarios' => $documento?->comments,
                'motivo_rechazo' => $documento?->rejection_reason,
                'subido_por' => $documento?->subidoPor?->nombreCompleto(),
                'revisado_por' => $documento?->revisadoPor?->nombreCompleto(),
                'cambio_autorizado_por' => $documento?->cambioAutorizadoPor?->nombreCompleto(),
                'fecha_subida' => $documento?->created_at?->toIso8601String(),
                'fecha_revision' => $documento?->reviewed_at?->toIso8601String(),
            ];
        })->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function documentoParaColaborador(DocumentType $tipo, ?EmployeeDocument $documento): array
    {
        $estadoDocumento = $documento === null ? null : $documento->status;

        return [
            'id' => $tipo->id,
            'tipo' => $tipo->clave,
            'nombre' => $tipo->nombre,
            'obligatorio' => $tipo->requerido,
            'estado' => $estadoDocumento === null ? 'pendiente' : $estadoDocumento->value,
            'mensaje' => $tipo->descripcion,
            'motivo_rechazo' => $documento !== null && in_array($estadoDocumento, [EstadoDocumento::Rechazado, EstadoDocumento::RequiereCorreccion], true)
                ? $documento->rejection_reason
                : null,
            'puede_subir' => $documento === null || in_array($estadoDocumento, self::ESTADOS_SUBIDA_DIRECTA, true),
            'puede_reemplazar' => in_array($estadoDocumento, self::ESTADOS_REEMPLAZO_AUTORIZADO, true),
            'puede_solicitar_cambio' => in_array($estadoDocumento, self::ESTADOS_SOLICITAN_CAMBIO, true),
            'fecha_subida' => $documento?->created_at?->toIso8601String(),
            'fecha_revision' => $documento?->reviewed_at?->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<int, DocumentType>  $tipos
     * @param  Collection<int, EmployeeDocument>  $vigentes
     * @return array{total: int, aprobados: int, pendientes: int, en_revision: int, rechazados: int, porcentaje: float}
     */
    public function progreso(Collection $tipos, Collection $vigentes): array
    {
        $requeridos = $tipos->where('requerido', true);

        $aprobados = 0;
        $pendientes = 0;
        $enRevision = 0;
        $rechazados = 0;

        foreach ($requeridos as $tipo) {
            $documento = $vigentes->get($tipo->id);

            match ($documento?->status) {
                EstadoDocumento::Aprobado => $aprobados++,
                EstadoDocumento::EnRevision, EstadoDocumento::Cargado, EstadoDocumento::CambioSolicitado, EstadoDocumento::CambioAutorizado => $enRevision++,
                EstadoDocumento::Rechazado, EstadoDocumento::RequiereCorreccion, EstadoDocumento::Vencido => $rechazados++,
                default => $pendientes++,
            };
        }

        $total = $requeridos->count();

        return [
            'total' => $total,
            'aprobados' => $aprobados,
            'pendientes' => $pendientes,
            'en_revision' => $enRevision,
            'rechazados' => $rechazados,
            'porcentaje' => $total > 0 ? round(($aprobados / $total) * 100) : 0,
        ];
    }

    /**
     * @param  Collection<int, DocumentType>  $tipos
     * @param  Collection<int, EmployeeDocument>  $vigentes
     */
    public function estadoGeneral(User $colaborador, Collection $tipos, Collection $vigentes): string
    {
        if ($colaborador->incorporacion_decision === 'rechazado') {
            return 'rechazado';
        }

        if ($colaborador->incorporacion_decision === 'aprobado') {
            return 'aprobado';
        }

        $requeridos = $tipos->where('requerido', true);

        $todosAprobados = $requeridos->every(fn (DocumentType $tipo) => $vigentes->get($tipo->id)?->status === EstadoDocumento::Aprobado);

        if ($todosAprobados) {
            return 'completo';
        }

        $hayAlgunoEnCurso = $requeridos->contains(fn (DocumentType $tipo) => in_array(
            $vigentes->get($tipo->id)?->status,
            [EstadoDocumento::Cargado, EstadoDocumento::EnRevision, EstadoDocumento::CambioSolicitado, EstadoDocumento::CambioAutorizado],
            true,
        ));

        return $hayAlgunoEnCurso ? 'en_revision' : 'incompleto';
    }

    /**
     * Atajo para obtener solo el estado general (usado por RH al listar/
     * filtrar expedientes, donde no hace falta el detalle por documento).
     */
    public function estado(User $colaborador): string
    {
        $tipos = $this->tiposDocumento();
        $vigentes = $this->expediente->documentosVigentes($colaborador);

        return $this->estadoGeneral($colaborador, $tipos, $vigentes);
    }

    /**
     * Sube el documento requerido `$tipo` para `$colaborador`. Solo se
     * permite si no hay version vigente, o si la vigente esta en un
     * estado que admite subida directa (rechazada/requiere
     * correccion/vencida) o RH ya autorizo el reemplazo
     * (cambio_autorizado). Reutiliza DocumentoStorageService::subirVersion
     * (misma logica de versionado que usa RH desde la web): el resultado
     * siempre queda en_revision.
     */
    public function subirDocumento(User $colaborador, DocumentType $tipo, UploadedFile $archivo, int $subidoPorId): EmployeeDocument
    {
        $vigente = $this->documentoVigente($colaborador, $tipo);

        if ($vigente !== null && ! in_array($vigente->status, [...self::ESTADOS_SUBIDA_DIRECTA, ...self::ESTADOS_REEMPLAZO_AUTORIZADO], true)) {
            throw new RuntimeException('Este documento ya fue subido y esta en revision o aprobado. Solicita un cambio si necesitas modificarlo.');
        }

        return $this->storage->subirVersion($colaborador, $tipo, $archivo, $subidoPorId);
    }

    /**
     * Marca el documento vigente de `$tipo` como cambio_solicitado: RH debe
     * autorizarlo (autorizarCambio) antes de que el colaborador pueda subir
     * una nueva version.
     */
    public function solicitarCambio(User $colaborador, DocumentType $tipo): EmployeeDocument
    {
        $vigente = $this->documentoVigente($colaborador, $tipo);

        if ($vigente === null) {
            throw new RuntimeException('Todavia no has subido este documento.');
        }

        if (! in_array($vigente->status, self::ESTADOS_SOLICITAN_CAMBIO, true)) {
            throw new RuntimeException('Solo puedes solicitar cambio de un documento en revision o aprobado.');
        }

        $vigente->update([
            'status' => EstadoDocumento::CambioSolicitado->value,
            'change_requested_at' => now(),
        ]);

        return $vigente;
    }

    public function aprobarDocumento(EmployeeDocument $documento, User $revisor, ?string $comentario): void
    {
        $documento->update([
            'status' => EstadoDocumento::Aprobado->value,
            'reviewed_by' => $revisor->id,
            'reviewed_at' => now(),
            'comments' => $comentario,
            'rejection_reason' => null,
        ]);
    }

    public function rechazarDocumento(EmployeeDocument $documento, User $revisor, string $motivo): void
    {
        $documento->update([
            'status' => EstadoDocumento::Rechazado->value,
            'reviewed_by' => $revisor->id,
            'reviewed_at' => now(),
            'rejection_reason' => $motivo,
        ]);
    }

    /**
     * Autoriza el cambio que el colaborador solicito: el documento debe
     * estar en cambio_solicitado. Al quedar en cambio_autorizado, el
     * colaborador ya puede subir la nueva version (ver subirDocumento).
     */
    public function autorizarCambio(EmployeeDocument $documento, User $revisor): void
    {
        if ($documento->status !== EstadoDocumento::CambioSolicitado) {
            throw new RuntimeException('Este documento no tiene una solicitud de cambio pendiente.');
        }

        $documento->update([
            'status' => EstadoDocumento::CambioAutorizado->value,
            'change_authorized_by' => $revisor->id,
            'change_authorized_at' => now(),
        ]);
    }

    /**
     * Aprueba la incorporacion completa: solo si todos los documentos
     * obligatorios estan aprobados. Activa al colaborador (estatus
     * Activo) para que pueda usar el portal/app normal.
     */
    public function aprobarIncorporacion(User $colaborador, User $revisor): void
    {
        $tipos = $this->tiposDocumento();
        $vigentes = $this->expediente->documentosVigentes($colaborador);

        $requeridos = $tipos->where('requerido', true);
        $todosAprobados = $requeridos->isNotEmpty() && $requeridos->every(
            fn (DocumentType $tipo) => $vigentes->get($tipo->id)?->status === EstadoDocumento::Aprobado
        );

        if (! $todosAprobados) {
            throw new RuntimeException('No se puede aprobar la incorporacion: hay documentos obligatorios sin aprobar.');
        }

        $colaborador->update([
            'estatus' => EstadoUsuario::Activo,
            'incorporacion_decision' => 'aprobado',
            'incorporacion_decidida_por' => $revisor->id,
            'incorporacion_decidida_en' => now(),
            'incorporacion_motivo_rechazo' => null,
        ]);
    }

    public function rechazarIncorporacion(User $colaborador, User $revisor, string $motivo): void
    {
        $colaborador->update([
            'incorporacion_decision' => 'rechazado',
            'incorporacion_decidida_por' => $revisor->id,
            'incorporacion_decidida_en' => now(),
            'incorporacion_motivo_rechazo' => $motivo,
        ]);
    }

    private function documentoVigente(User $colaborador, DocumentType $tipo): ?EmployeeDocument
    {
        return $this->expediente->documentosVigentes($colaborador)->get($tipo->id);
    }
}
