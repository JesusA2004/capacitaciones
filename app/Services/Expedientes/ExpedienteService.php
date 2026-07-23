<?php

namespace App\Services\Expedientes;

use App\Enums\EstadoDocumento;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Calcula el estado del expediente digital de un colaborador a partir de sus
 * documentos cargados, comparados contra el catalogo de tipos de documento
 * requeridos y activos. No hay una tabla "expedientes": el expediente es una
 * vista calculada sobre User + EmployeeDocument (ver docs/EXPEDIENTES_DIGITALES.md).
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
    public function documentosVigentes(User $colaborador): Collection
    {
        return EmployeeDocument::query()
            ->where('user_id', $colaborador->id)
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
    public function resumenCompletitud(User $colaborador): array
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

    public function documentosPendientesCount(User $colaborador): int
    {
        $resumen = $this->resumenCompletitud($colaborador);

        return $resumen['pendientes'] + $resumen['rechazados'];
    }
}
