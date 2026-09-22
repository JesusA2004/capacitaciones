<?php

namespace App\Services\Expedientes;

use App\Enums\EstadoDocumento;
use App\Models\Colaborador;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use Illuminate\Support\Collection;

/**
 * Calcula el estado del expediente digital de un colaborador a partir de sus
 * documentos cargados, comparados contra el catalogo de tipos de documento
 * requeridos y activos. No hay una tabla "expedientes": el expediente es una
 * vista calculada sobre Colaborador + EmployeeDocument (ver docs/EXPEDIENTES_DIGITALES.md).
 */
class ExpedienteService
{
    /** @var Collection<int, DocumentType>|null */
    private ?Collection $tiposRequeridosCache = null;

    /**
     * Memoizado en la instancia: los llamadores tipicos (listado de
     * expedientes, dashboard RH) inyectan un unico ExpedienteService y lo
     * reutilizan para varios colaboradores en la misma peticion.
     *
     * @return Collection<int, DocumentType>
     */
    private function tiposRequeridos(): Collection
    {
        return $this->tiposRequeridosCache ??= DocumentType::query()->where('requerido', true)->where('activo', true)->get();
    }

    /**
     * El documento vigente de cada tipo para un colaborador: el mas
     * reciente que no este archivado (una nueva version archiva a la
     * anterior al subirse, ver EmployeeDocumentController::subir).
     *
     * @return Collection<int, EmployeeDocument> indexada por document_type_id
     */
    public function documentosVigentes(Colaborador $colaborador): Collection
    {
        return EmployeeDocument::query()
            ->where('colaborador_id', $colaborador->id)
            ->where('status', '!=', EstadoDocumento::Archivado->value)
            ->with(['tipo', 'subidoPor:id,name,apellidos', 'revisadoPor:id,name,apellidos'])
            ->orderByDesc('version')
            ->get()
            ->unique('document_type_id')
            ->keyBy('document_type_id');
    }

    /**
     * @return array{porcentaje: float, requeridos_total: int, requeridos_aprobados: int, pendientes: int, rechazados: int}
     */
    public function resumenCompletitud(Colaborador $colaborador): array
    {
        $tiposRequeridos = $this->tiposRequeridos();
        $vigentes = $this->documentosVigentes($colaborador);

        $aprobados = 0;
        $pendientes = 0;
        $rechazados = 0;

        foreach ($tiposRequeridos as $tipo) {
            $documento = $vigentes->get($tipo->id);

            if ($documento === null) {
                $pendientes++;

                continue;
            }

            match ($documento->status) {
                EstadoDocumento::Aprobado => $aprobados++,
                EstadoDocumento::Rechazado, EstadoDocumento::RequiereCorreccion, EstadoDocumento::Vencido => $rechazados++,
                default => $pendientes++,
            };
        }

        $total = $tiposRequeridos->count();

        return [
            'porcentaje' => $total > 0 ? round(($aprobados / $total) * 100, 1) : 0.0,
            'requeridos_total' => $total,
            'requeridos_aprobados' => $aprobados,
            'pendientes' => $pendientes,
            'rechazados' => $rechazados,
        ];
    }

    /**
     * Estado documental completo del expediente. "Completo" significa que
     * TODOS los documentos obligatorios (document_types.requerido + activo)
     * están APROBADOS — tener archivos cargados no basta.
     *
     * - entregados: obligatorios con un archivo vigente (cualquier estado
     *   distinto de rechazado/vencido/requiere corrección).
     * - aprobados: obligatorios con su versión vigente aprobada.
     * - faltantes: obligatorios sin archivo vigente o con el vigente rechazado/vencido.
     * - porcentaje: aprobados / requeridos.
     *
     * @return array{requeridos: int, entregados: int, aprobados: int, en_revision: int, rechazados: int, faltantes: int, porcentaje: float, completo: bool, documentos: list<array<string, mixed>>}
     */
    public function estadoDocumental(Colaborador $colaborador): array
    {
        $tiposRequeridos = $this->tiposRequeridos();
        $vigentes = $this->documentosVigentes($colaborador);

        $entregados = 0;
        $aprobados = 0;
        $enRevision = 0;
        $rechazados = 0;
        $faltantes = 0;
        $documentos = [];

        foreach ($tiposRequeridos as $tipo) {
            $documento = $vigentes->get($tipo->id);
            $estado = $documento?->status;
            $rechazado = in_array($estado, [EstadoDocumento::Rechazado, EstadoDocumento::RequiereCorreccion, EstadoDocumento::Vencido], true);

            if ($documento === null || $rechazado) {
                $faltantes++;
            } else {
                $entregados++;
            }

            if ($estado === EstadoDocumento::Aprobado) {
                $aprobados++;
            } elseif ($rechazado) {
                $rechazados++;
            } elseif ($documento !== null) {
                $enRevision++;
            }

            $documentos[] = [
                'document_type_id' => $tipo->id,
                'clave' => $tipo->clave,
                'nombre' => $tipo->nombre,
                'categoria' => $tipo->categoria->value,
                'obligatorio' => true,
                'estado' => $estado->value ?? EstadoDocumento::Pendiente->value,
                'documento_id' => $documento?->id,
                'version' => $documento?->version,
                'cargado_en' => $documento?->created_at?->toIso8601String(),
                'cargado_por' => $documento?->uploaded_by,
                'validado_por' => $documento?->reviewed_by,
                'validado_en' => $documento?->reviewed_at?->toIso8601String(),
                'motivo_rechazo' => $documento?->rejection_reason,
                'observaciones' => $documento?->comments,
            ];
        }

        $requeridos = $tiposRequeridos->count();

        return [
            'requeridos' => $requeridos,
            'entregados' => $entregados,
            'aprobados' => $aprobados,
            'en_revision' => $enRevision,
            'rechazados' => $rechazados,
            'faltantes' => $faltantes,
            'porcentaje' => $requeridos > 0 ? round(($aprobados / $requeridos) * 100, 1) : 100.0,
            'completo' => $aprobados === $requeridos,
            'documentos' => $documentos,
        ];
    }

    public function documentosPendientesCount(Colaborador $colaborador): int
    {
        $resumen = $this->resumenCompletitud($colaborador);

        return $resumen['pendientes'] + $resumen['rechazados'];
    }
}
